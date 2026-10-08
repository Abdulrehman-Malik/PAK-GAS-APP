<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ExpenseService
{
    public function __construct(private readonly DB $db, private readonly CashService $cash, private readonly AuditService $audit)
    {
    }

    public function post(array $input, int $userId): int
    {
        $date=(string)($input['expense_date']??'');
        $category=(int)($input['category_id']??0);
        $amount=(string)($input['amount']??'0.00');
        $method=strtoupper((string)($input['method']??'CASH'));
        $counter=(int)($input['counter_id']??0);

        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$category<1) throw new \InvalidArgumentException('Date and expense category are required.');
        if(bccomp($amount,'0.00',2)<=0) throw new \InvalidArgumentException('Expense amount must be greater than zero.');
        if(!in_array($method,['CASH','ONLINE','CHEQUE'],true)) throw new \InvalidArgumentException('Invalid expense method.');
        if($method==='CASH'&&$counter<1) throw new \InvalidArgumentException('Cash counter is required.');

        return $this->db->transaction(function()use($date,$category,$amount,$method,$counter,$userId,$input):int{
            $cat=$this->db->fetchOne('SELECT id FROM expense_categories WHERE id=:id AND active=1 FOR UPDATE',['id'=>$category]);
            if(!$cat) throw new \InvalidArgumentException('Expense category is invalid or inactive.');
            $this->db->execute('INSERT INTO expenses(expense_date,category_id,amount,method,counter_id,reference_no,payee,notes,status,created_by) VALUES(:date,:cat,:amount,:method,:counter,:ref,:payee,:notes,\'POSTED\',:user)',[
                'date'=>$date,'cat'=>$category,'amount'=>$amount,'method'=>$method,'counter'=>$counter>0?$counter:null,
                'ref'=>(string)($input['reference_no']??''),'payee'=>(string)($input['payee']??''),'notes'=>(string)($input['notes']??''),'user'=>$userId
            ]);
            $id=$this->db->lastInsertId();
            if($method==='CASH') $this->cash->post($counter,$date,'OUT',$amount,'EXPENSE',$id,$userId);
            $this->audit->record($userId,'CREATE','expenses',$id,null,['amount'=>$amount,'method'=>$method],null);
            return $id;
        });
    }

    public function void(int $id,int $userId,string $reason):void
    {
        $reason=trim($reason);if($reason==='')throw new \InvalidArgumentException('Void reason is required.');
        $this->db->transaction(function()use($id,$userId,$reason):void{
            $e=$this->db->fetchOne('SELECT * FROM expenses WHERE id=:id FOR UPDATE',['id'=>$id]);
            if(!$e||$e['status']!=='POSTED')throw new \InvalidArgumentException('Expense is not available for void.');
            if($e['method']==='CASH')$this->cash->reverseDocument('EXPENSE',$id,(string)$e['expense_date'],$userId);
            $this->db->execute('UPDATE expenses SET status=\'VOID\',void_reason=:reason,voided_at=NOW(),voided_by=:user WHERE id=:id',['reason'=>$reason,'user'=>$userId,'id'=>$id]);
            $this->audit->record($userId,'VOID','expenses',$id,['status'=>'POSTED'],['status'=>'VOID','reason'=>$reason],null);
        });
    }
}
