<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class PaymentService
{
    public function __construct(
        private readonly DB $db,
        private readonly DocNumberService $docs,
        private readonly LedgerService $ledger,
        private readonly CashService $cash
    ) {
    }

    public function history(array $filters, int $limit, int $offset): array
    {
        $where=['1=1'];$params=[];
        if(($filters['from']??'')!==''){$where[]='pm.payment_date>=:from';$params['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='pm.payment_date<=:to';$params['to']=$filters['to'];}
        if((int)($filters['party_id']??0)>0){$where[]='pm.party_id=:party';$params['party']=(int)$filters['party_id'];}
        if(($filters['status']??'')!==''){$where[]='pm.status=:status';$params['status']=$filters['status'];}
        if(($filters['search']??'')!==''){$where[]='(pm.doc_no LIKE :search OR p.name LIKE :search OR p.code LIKE :search)';$params['search']='%'.$filters['search'].'%';}
        $base='FROM payments pm INNER JOIN parties p ON p.id=pm.party_id LEFT JOIN users u ON u.id=pm.created_by WHERE '.implode(' AND ',$where);
        $total=(int)($this->db->fetchOne('SELECT COUNT(*) c '.$base,$params)['c']??0);
        $params['limit']=$limit;$params['offset']=$offset;
        $rows=$this->db->fetchAll(
            'SELECT pm.*,p.code party_code,p.name party_name,u.full_name user_name,
                    ch.cheque_no,ch.bank,ch.cheque_date,ch.status cheque_status,
                    pu.doc_no purchase_doc
             '.$base.' ORDER BY pm.payment_date DESC,pm.id DESC LIMIT :limit OFFSET :offset',
            $params
        );
        return ['rows'=>$rows,'total'=>$total];
    }

    public function suppliers(string $q):array
    {
        return $this->db->fetchAll(
            "SELECT id,code,name
             FROM parties
             WHERE party_type='SUPPLIER' AND active=1
               AND (code LIKE :q OR name LIKE :q OR phone LIKE :q)
             ORDER BY name LIMIT 30",
            ['q'=>'%'.$q.'%']
        );
    }

    public function post(array $input, int $userId): array
    {
        $date=(string)($input['payment_date']??date('Y-m-d'));
        $partyId=(int)($input['party_id']??0);
        $amount=(string)($input['amount']??'0.00');
        $method=strtoupper((string)($input['method']??'CASH'));
        $counterId=(int)($input['counter_id']??0);

        if($partyId<1||bccomp($amount,'0.00',2)<=0) {
            throw new \InvalidArgumentException('Supplier and a positive payment amount are required.');
        }

        return $this->db->transaction(function()use($input,$userId,$date,$partyId,$amount,$method,$counterId):array{
            $party=$this->db->fetchOne(
                "SELECT * FROM parties WHERE id=:id AND party_type='SUPPLIER' AND active=1 FOR UPDATE",
                ['id'=>$partyId]
            );
            if(!$party) throw new \InvalidArgumentException('Supplier is invalid or inactive.');
            if(!in_array($method,['CASH','ONLINE','CHEQUE'],true)) throw new \InvalidArgumentException('Payment method is invalid.');

            $chequeId=null;
            if($method==='CHEQUE'){
                $chequeNo=trim((string)($input['cheque_no']??''));
                if($chequeNo==='') throw new \InvalidArgumentException('Cheque number is required.');
                $chequeDate=(string)($input['cheque_date']??$date);
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$chequeDate)) throw new \InvalidArgumentException('Cheque date is invalid.');
                $this->db->execute(
                    "INSERT INTO cheques(direction,party_id,cheque_no,bank,cheque_date,amount,status,created_by)
                     VALUES('OUT',:party,:no,:bank,:date,:amount,:status,:user)",
                    [
                        'party'=>$partyId,'no'=>$chequeNo,'bank'=>trim((string)($input['cheque_bank']??''))?:null,
                        'date'=>$chequeDate,'amount'=>$amount,
                        'status'=>'PENDING','user'=>$userId
                    ]
                );
                $chequeId=(int)$this->db->pdo()->lastInsertId();
            }

            $doc=$this->docs->next('PAYMENT');
            $this->db->execute(
                "INSERT INTO payments(doc_no,payment_date,party_id,amount,method,counter_id,cheque_id,source,purchase_id,narration,created_by)
                 VALUES(:doc,:date,:party,:amount,:method,:counter,:cheque,'MANUAL',NULL,:narration,:user)",
                [
                    'doc'=>$doc,'date'=>$date,'party'=>$partyId,'amount'=>$amount,'method'=>$method,
                    'counter'=>$counterId>0?$counterId:null,'cheque'=>$chequeId,
                    'narration'=>trim((string)($input['narration']??''))?:null,'user'=>$userId
                ]
            );
            $paymentId=(int)$this->db->pdo()->lastInsertId();

            if($chequeId){
                $this->db->execute('UPDATE cheques SET payment_id=:payment WHERE id=:id',['payment'=>$paymentId,'id'=>$chequeId]);
            }

            if($method!=='CHEQUE'||$this->postingMode()==='ON_RECEIPT'){
                $this->ledger->post($partyId,$date,'PAYMENT',$paymentId,$amount,'0.00','Payment '.$doc,$userId);
            }
            if($method==='CASH'){
                $this->cash->post($counterId,$date,'OUT',$amount,'PAYMENT',$paymentId,$userId);
            }

            return ['id'=>$paymentId,'doc_no'=>$doc,'amount'=>$amount,'balance_after'=>$this->ledger->balance($partyId)];
        });
    }

    public function void(int $paymentId,int $userId,string $reason):void
    {
        $reason=trim($reason);
        if($reason==='') throw new \InvalidArgumentException('Void reason is required.');
        $this->db->transaction(function()use($paymentId,$userId,$reason):void{
            $payment=$this->db->fetchOne('SELECT * FROM payments WHERE id=:id FOR UPDATE',['id'=>$paymentId]);
            if(!$payment) throw new \InvalidArgumentException('Payment not found.');
            if($payment['status']!=='POSTED') throw new \InvalidArgumentException('Payment is already void.');

            $this->ledger->reverseDocument('PAYMENT',$paymentId,$userId,(string)$payment['payment_date'],$reason);
            if($payment['method']==='CASH') $this->cash->reverseDocument('PAYMENT',$paymentId,$userId);
            if($payment['cheque_id']){
                $this->db->execute("UPDATE cheques SET status='BOUNCED',bounce_reason=:reason WHERE id=:id AND status<>'BOUNCED'",['reason'=>$reason,'id'=>$payment['cheque_id']]);
            }
            $this->db->execute("UPDATE payments SET status='VOID', narration=CONCAT(COALESCE(narration,''),' [VOID]') WHERE id=:id",['id'=>$paymentId]);
        });
    }

    private function postingMode():string
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key='cheque_ledger_posting'");
        return strtoupper((string)($row['setting_value']??'ON_CLEARANCE'));
    }
}
