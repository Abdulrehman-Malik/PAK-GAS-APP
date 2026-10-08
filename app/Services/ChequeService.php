<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ChequeService
{
    public function __construct(private readonly DB $db, private readonly LedgerService $ledger)
    {
    }

    public function list(string $status = '', string $direction = '', int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = ['limit'=>$limit,'offset'=>$offset];

        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if ($direction !== '') {
            $where[] = 'c.direction = :direction';
            $params['direction'] = $direction;
        }

        $total = (int)($this->db->fetchOne(
            'SELECT COUNT(*) AS c FROM cheques c WHERE '.implode(' AND ',$where),
            $params
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            'SELECT c.*,
                    p.code AS party_code, p.name AS party_name,
                    r.doc_no AS receipt_doc, pm.doc_no AS payment_doc
             FROM cheques c
             LEFT JOIN parties p ON p.id=c.party_id
             LEFT JOIN receipts r ON r.id=c.receipt_id
             LEFT JOIN payments pm ON pm.id=c.payment_id
             WHERE '.implode(' AND ',$where).'
             ORDER BY c.cheque_date ASC,c.id DESC
             LIMIT :limit OFFSET :offset',
            $params
        );

        return ['rows'=>$rows,'total'=>$total];
    }

    public function clear(int $chequeId, string $date, int $userId): void
    {
        $this->db->transaction(function()use($chequeId,$date,$userId):void{
            $cheque=$this->db->fetchOne('SELECT * FROM cheques WHERE id=:id FOR UPDATE',['id'=>$chequeId]);
            if(!$cheque) throw new \InvalidArgumentException('Cheque not found.');
            if($cheque['status']!=='PENDING') throw new \InvalidArgumentException('Only pending cheques can be cleared.');

            $this->db->execute(
                "UPDATE cheques SET status='CLEARED',cleared_date=:date WHERE id=:id",
                ['date'=>$date,'id'=>$chequeId]
            );

            if($this->postingMode()==='ON_CLEARANCE'){
                if($cheque['receipt_id']){
                    $this->ledger->post(
                        (int)$cheque['party_id'],$date,'RECEIPT',(int)$cheque['receipt_id'],
                        '0.00',(string)$cheque['amount'],'Cheque cleared',$userId
                    );
                } elseif($cheque['payment_id']){
                    $this->ledger->post(
                        (int)$cheque['party_id'],$date,'PAYMENT',(int)$cheque['payment_id'],
                        (string)$cheque['amount'],'0.00','Cheque cleared',$userId
                    );
                }
            }
        });
    }

    public function bounce(int $chequeId, string $reason, int $userId): void
    {
        $reason=trim($reason);
        if($reason==='') throw new \InvalidArgumentException('Bounce reason is required.');

        $this->db->transaction(function()use($chequeId,$reason,$userId):void{
            $cheque=$this->db->fetchOne('SELECT * FROM cheques WHERE id=:id FOR UPDATE',['id'=>$chequeId]);
            if(!$cheque) throw new \InvalidArgumentException('Cheque not found.');
            if($cheque['status']==='BOUNCED') throw new \InvalidArgumentException('Cheque is already bounced.');

            $this->db->execute(
                "UPDATE cheques SET status='BOUNCED',bounce_reason=:reason WHERE id=:id",
                ['reason'=>$reason,'id'=>$chequeId]
            );

            if($this->postingMode()==='ON_RECEIPT'){
                if($cheque['receipt_id']){
                    $this->ledger->reverseDocument(
                        'RECEIPT',(int)$cheque['receipt_id'],$userId,date('Y-m-d'),$reason
                    );
                } elseif($cheque['payment_id']){
                    $this->ledger->reverseDocument(
                        'PAYMENT',(int)$cheque['payment_id'],$userId,date('Y-m-d'),$reason
                    );
                }
            } elseif($cheque['status']==='CLEARED'){
                // Defensive path for data created under an earlier posting rule.
                if($cheque['receipt_id']){
                    $this->ledger->reverseDocument('RECEIPT',(int)$cheque['receipt_id'],$userId,date('Y-m-d'),$reason);
                } elseif($cheque['payment_id']){
                    $this->ledger->reverseDocument('PAYMENT',(int)$cheque['payment_id'],$userId,date('Y-m-d'),$reason);
                }
            }
        });
    }

    private function postingMode():string
    {
        $row=$this->db->fetchOne(
            "SELECT setting_value FROM settings
             WHERE setting_group='cheques' AND setting_key='cheque_ledger_posting'"
        );
        return strtoupper((string)($row['setting_value']??'ON_CLEARANCE'));
    }
}
