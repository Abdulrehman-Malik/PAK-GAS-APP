<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class MasterProtectionService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function partyUsage(int $id): array
    {
        $checks=[
            'sales'=>'SELECT COUNT(*) c FROM sales WHERE customer_id=:id',
            'receipts'=>'SELECT COUNT(*) c FROM receipts WHERE party_id=:id',
            'purchases'=>'SELECT COUNT(*) c FROM purchases WHERE supplier_id=:id',
            'payments'=>'SELECT COUNT(*) c FROM payments WHERE party_id=:id',
            'ledger'=>'SELECT COUNT(*) c FROM ledger_entries WHERE party_id=:id',
            'cylinders'=>'SELECT COUNT(*) c FROM cylinders WHERE customer_id=:id',
            'cheques'=>'SELECT COUNT(*) c FROM cheques WHERE party_id=:id'
        ];
        return $this->counts($checks,$id);
    }

    public function groupUsage(int $id): array
    {
        $checks=[
            'cylinders'=>'SELECT COUNT(*) c FROM cylinders WHERE group_id=:id',
            'rates'=>'SELECT COUNT(*) c FROM rates WHERE group_id=:id',
            'purchase lines'=>'SELECT COUNT(*) c FROM purchase_lines WHERE group_id=:id'
        ];
        return $this->counts($checks,$id);
    }

    public function cylinderUsage(int $id): array
    {
        $checks=[
            'movements'=>'SELECT COUNT(*) c FROM cylinder_movements WHERE cylinder_id=:id',
            'sales'=>'SELECT COUNT(*) c FROM sale_lines WHERE cylinder_id=:id',
            'purchase lines'=>'SELECT COUNT(*) c FROM purchase_lines WHERE cylinder_id=:id'
        ];
        return $this->counts($checks,$id);
    }

    public function assertDeleteAllowed(string $label,array $usage):void
    {
        foreach($usage as $name=>$count){
            if($count>0) throw new \InvalidArgumentException($label.' cannot be deleted because it is used in '.$count.' '.$name.'. Deactivate it instead.');
        }
    }

    private function counts(array $checks,int $id):array
    {
        $result=[];
        foreach($checks as $name=>$sql)$result[$name]=(int)($this->db->fetchOne($sql,['id'=>$id])['c']??0);
        return $result;
    }
}
