<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ExpenseService
{
    public function __construct(private readonly DB $db, private readonly CashService $cash, private readonly DocNumberService $docs)
    {
    }

    public function categories():array
    {
        return $this->db->fetchAll('SELECT * FROM expense_categories WHERE active=1 ORDER BY name');
    }

    public function post(array $input,int $userId):array
    {
        $date=(string)($input['expense_date']??date('Y-m-d'));
        $categoryId=(int)($input['category_id']??0);
        $amount=(string)($input['amount']??'0.00');
        $method=strtoupper((string)($input['method']??'CASH'));
        $counterId=(int)($input['counter_id']??0);

        if($categoryId<1||bccomp($amount,'0.00',2)<=0) throw new \InvalidArgumentException('Category and a positive expense amount are required.');
        if($method!=='CASH') throw new \InvalidArgumentException('Expenses support cash payment only.');

        return $this->db->transaction(function()use($input,$userId,$date,$categoryId,$amount,$method,$counterId):array{
            $category=$this->db->fetchOne('SELECT id FROM expense_categories WHERE id=:id AND active=1 FOR UPDATE',['id'=>$categoryId]);
            if(!$category) throw new \InvalidArgumentException('Expense category is invalid or inactive.');

            if($counterId<1) throw new \InvalidArgumentException('A cash counter is required for cash expenses.');

            $doc=$this->docs->next('EXPENSE');
            $this->db->execute(
                'INSERT INTO expenses(expense_date,category_id,amount,method,counter_id,reference_no,payee,notes,status,created_by)
                 VALUES(:date,:category,:amount,:method,:counter,:reference,:payee,:notes,\'POSTED\',:user)',
                [
                    'date'=>$date,'category'=>$categoryId,'amount'=>$amount,'method'=>$method,'counter'=>$counterId,
                    'reference'=>trim((string)($input['reference_no']??''))?:null,
                    'payee'=>trim((string)($input['payee']??''))?:null,
                    'notes'=>trim((string)($input['notes']??''))?:null,'user'=>$userId
                ]
            );
            $id=(int)$this->db->pdo()->lastInsertId();
            $this->cash->post($counterId,$date,'OUT',$amount,'EXPENSE',$id,$userId);
            return ['id'=>$id,'doc_no'=>$doc,'amount'=>$amount];
        });
    }

    public function history(array $filters,int $limit,int $offset):array
    {
        $where=['1=1'];$params=[];
        if(($filters['from']??'')!==''){$where[]='e.expense_date>=:from';$params['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='e.expense_date<=:to';$params['to']=$filters['to'];}
        if((int)($filters['category_id']??0)>0){$where[]='e.category_id=:category';$params['category']=(int)$filters['category_id'];}
        if(($filters['status']??'')!==''){$where[]='e.status=:status';$params['status']=$filters['status'];}
        $base='FROM expenses e INNER JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.created_by WHERE '.implode(' AND ',$where);
        $total=(int)($this->db->fetchOne('SELECT COUNT(*) c '.$base,$params)['c']??0);
        $params['limit']=$limit;$params['offset']=$offset;
        $rows=$this->db->fetchAll(
            'SELECT e.*,c.name category_name,u.full_name user_name,co.name counter_name '.$base.'
             LEFT JOIN counters co ON co.id=e.counter_id
             ORDER BY e.expense_date DESC,e.id DESC LIMIT :limit OFFSET :offset',
            $params
        );
        return ['rows'=>$rows,'total'=>$total];
    }

    public function void(int $id,int $userId,string $reason):void
    {
        $reason=trim($reason);
        if($reason==='') throw new \InvalidArgumentException('Void reason is required.');
        $this->db->transaction(function()use($id,$userId,$reason):void{
            $expense=$this->db->fetchOne('SELECT * FROM expenses WHERE id=:id FOR UPDATE',['id'=>$id]);
            if(!$expense) throw new \InvalidArgumentException('Expense not found.');
            if($expense['status']!=='POSTED') throw new \InvalidArgumentException('Expense is already void.');
            $this->cash->reverseDocument('EXPENSE',$id,$userId);
            $this->db->execute("UPDATE expenses SET status='VOID',void_reason=:reason,voided_at=NOW(),voided_by=:user WHERE id=:id",['reason'=>$reason,'user'=>$userId,'id'=>$id]);
        });
    }
}
