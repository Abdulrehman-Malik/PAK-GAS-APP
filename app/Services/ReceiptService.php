<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ReceiptService
{
    public function __construct(
        private readonly DB $db,
        private readonly DocNumberService $docs,
        private readonly LedgerService $ledger,
        private readonly CashService $cash
    ) {
    }

    public function printData(?int $receiptId, ?int $saleId): array
    {
        if(($receiptId??0)>0){
            $receipt=$this->db->fetchOne(
                'SELECT r.*,p.code customer_code,p.name customer_name,s.doc_no sale_doc,s.txn_date sale_date,
                        ch.cheque_no,ch.bank,ch.cheque_date,ch.status cheque_status
                 FROM receipts r
                 INNER JOIN parties p ON p.id=r.party_id
                 LEFT JOIN sales s ON s.id=r.sale_id
                 LEFT JOIN cheques ch ON ch.id=r.cheque_id
                 WHERE r.id=:id',
                ['id'=>$receiptId]
            );
            if(!$receipt)throw new \InvalidArgumentException('Receipt not found.');
            $saleId=(int)($receipt['sale_id']??0);
        }elseif(($saleId??0)>0){
            $receipt=null;
            $receipt=$this->db->fetchOne(
                'SELECT r.*,p.code customer_code,p.name customer_name,s.doc_no sale_doc,s.txn_date sale_date
                 FROM receipts r INNER JOIN parties p ON p.id=r.party_id
                 INNER JOIN sales s ON s.id=r.sale_id
                 WHERE r.sale_id=:sale ORDER BY r.id DESC LIMIT 1',
                ['sale'=>$saleId]
            );
            if(!$receipt){
                $sale=$this->db->fetchOne(
                    'SELECT s.*,p.code customer_code,p.name customer_name
                     FROM sales s INNER JOIN parties p ON p.id=s.customer_id WHERE s.id=:id',
                    ['id'=>$saleId]
                );
                if(!$sale)throw new \InvalidArgumentException('Sale not found.');
                $receipt=$sale;
            }
        }else{
            throw new \InvalidArgumentException('Receipt or sale is required.');
        }

        $lines=[];
        if(($saleId??0)>0){
            $lines=$this->db->fetchAll(
                'SELECT sl.*,c.code cylinder_code,cg.name group_name
                 FROM sale_lines sl INNER JOIN cylinders c ON c.id=sl.cylinder_id
                 INNER JOIN cylinder_groups cg ON cg.id=c.group_id
                 WHERE sl.sale_id=:sale ORDER BY sl.id',
                ['sale'=>$saleId]
            );
        }
        return ['receipt'=>$receipt,'lines'=>$lines];
    }

    public function list(array $where, array $params, int $limit, int $offset): array
    {
        $params['limit']=$limit;
        $params['offset']=$offset;
        return $this->db->fetchAll(
            'SELECT r.*,p.code party_code,p.name party_name,u.full_name user_name,
                    ch.cheque_no,ch.bank,ch.cheque_date,ch.status cheque_status,
                    s.doc_no sale_doc
             FROM receipts r
             INNER JOIN parties p ON p.id=r.party_id
             LEFT JOIN users u ON u.id=r.created_by
             LEFT JOIN cheques ch ON ch.id=r.cheque_id
             LEFT JOIN sales s ON s.id=r.sale_id
             WHERE '.implode(' AND ',$where).'
             ORDER BY r.receipt_date DESC,r.id DESC LIMIT :limit OFFSET :offset',
            $params
        );
    }

    public function dbCount(string $base, array $params): int
    {
        return (int)($this->db->fetchOne('SELECT COUNT(*) c '.$base,$params)['c']??0);
    }

    public function customers(string $q): array
    {
        return $this->db->fetchAll(
            "SELECT id,code,name,allow_credit,credit_limit
             FROM parties
             WHERE party_type='CUSTOMER' AND active=1
               AND (code LIKE :q OR name LIKE :q OR phone LIKE :q)
             ORDER BY name LIMIT 30",
            ['q'=>'%'.$q.'%']
        );
    }

    public function post(array $input, int $userId): array
    {
        $date = (string) ($input['receipt_date'] ?? date('Y-m-d'));
        $partyId = (int) ($input['party_id'] ?? 0);
        $amount = (string) ($input['amount'] ?? '0.00');
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $counterId = (int) ($input['counter_id'] ?? 0);

        if ($partyId < 1 || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Customer and a positive receipt amount are required.');
        }

        return $this->db->transaction(function () use ($input, $userId, $date, $partyId, $amount, $method, $counterId): array {
            $party = $this->db->fetchOne(
                "SELECT * FROM parties WHERE id=:id AND party_type='CUSTOMER' AND active=1 FOR UPDATE",
                ['id'=>$partyId]
            );
            if (!$party) {
                throw new \InvalidArgumentException('Customer is invalid or inactive.');
            }
            $this->validateMethod($method);

            $chequeId = null;
            if ($method === 'CHEQUE') {
                $chequeNo = trim((string)($input['cheque_no'] ?? ''));
                if ($chequeNo === '') {
                    throw new \InvalidArgumentException('Cheque number is required.');
                }
                $chequeDate = (string)($input['cheque_date'] ?? $date);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $chequeDate)) {
                    throw new \InvalidArgumentException('Cheque date is invalid.');
                }

                $this->db->execute(
                    "INSERT INTO cheques
                     (direction,party_id,cheque_no,bank,cheque_date,amount,status,created_by)
                     VALUES ('IN',:party,:no,:bank,:date,:amount,:status,:user)",
                    [
                        'party'=>$partyId,
                        'no'=>$chequeNo,
                        'bank'=>trim((string)($input['cheque_bank'] ?? '')) ?: null,
                        'date'=>$chequeDate,
                        'amount'=>$amount,
                        'status'=>'PENDING',
                        'user'=>$userId,
                    ]
                );
                $chequeId=(int)$this->db->pdo()->lastInsertId();
            }

            $doc=$this->docs->next('RECEIPT');
            $this->db->execute(
                "INSERT INTO receipts
                 (doc_no,receipt_date,party_id,amount,method,counter_id,cheque_id,source,sale_id,narration,created_by)
                 VALUES (:doc,:date,:party,:amount,:method,:counter,:cheque,'MANUAL',NULL,:narration,:user)",
                [
                    'doc'=>$doc,'date'=>$date,'party'=>$partyId,'amount'=>$amount,'method'=>$method,
                    'counter'=>$counterId>0?$counterId:null,'cheque'=>$chequeId,
                    'narration'=>trim((string)($input['narration'] ?? '')) ?: null,'user'=>$userId
                ]
            );
            $receiptId=(int)$this->db->pdo()->lastInsertId();

            if ($chequeId) {
                $this->db->execute('UPDATE cheques SET receipt_id=:receipt WHERE id=:id',[
                    'receipt'=>$receiptId,'id'=>$chequeId
                ]);
            }

            if ($method!=='CHEQUE' || $this->postingMode()==='ON_RECEIPT') {
                $this->ledger->post($partyId,$date,'RECEIPT',$receiptId,'0.00',$amount,'Receipt '.$doc,$userId);
            }
            if ($method==='CASH') {
                $this->cash->post($counterId,$date,'IN',$amount,'RECEIPT',$receiptId,$userId);
            }

            return [
                'id'=>$receiptId,
                'doc_no'=>$doc,
                'amount'=>$amount,
                'balance_after'=>$this->ledger->balance($partyId),
            ];
        });
    }

    public function void(int $receiptId, int $userId, string $reason): void
    {
        $reason=trim($reason);
        if ($reason==='') throw new \InvalidArgumentException('Void reason is required.');

        $this->db->transaction(function()use($receiptId,$userId,$reason):void{
            $receipt=$this->db->fetchOne('SELECT * FROM receipts WHERE id=:id FOR UPDATE',['id'=>$receiptId]);
            if(!$receipt) throw new \InvalidArgumentException('Receipt not found.');
            if($receipt['status']!=='POSTED') throw new \InvalidArgumentException('Receipt is already void.');

            $this->ledger->reverseDocument('RECEIPT',$receiptId,$userId,(string)$receipt['receipt_date'],$reason);
            if($receipt['method']==='CASH') $this->cash->reverseDocument('RECEIPT',$receiptId,$userId);

            if($receipt['cheque_id']) {
                $this->db->execute(
                    "UPDATE cheques SET status='BOUNCED', bounce_reason=:reason WHERE id=:id AND status<>'BOUNCED'",
                    ['reason'=>$reason,'id'=>$receipt['cheque_id']]
                );
            }
            $this->db->execute(
                "UPDATE receipts SET status='VOID', narration=CONCAT(COALESCE(narration,''),' [VOID]') WHERE id=:id",
                ['id'=>$receiptId]
            );
        });
    }

    private function validateMethod(string $method): void
    {
        if(!in_array($method,['CASH','ONLINE','CHEQUE'],true)) {
            throw new \InvalidArgumentException('Payment method is invalid.');
        }
    }

    private function postingMode(): string
    {
        $row=$this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key='cheque_ledger_posting'"
        );
        return strtoupper((string)($row['setting_value']??'ON_CLEARANCE'));
    }
}
