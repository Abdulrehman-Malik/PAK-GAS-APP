<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class StockService
{
    public function __construct(private readonly DB $db, private readonly CylinderStatus $status) {}
    public function createOpeningBatch(array $rows,int $userId,string $source='MANUAL',?string $notes=null): int
    {
        if ($rows===[]) throw new \InvalidArgumentException('At least one opening-stock row is required.');
        return $this->db->transaction(function() use($rows,$userId,$source,$notes): int {
            $this->db->execute('INSERT INTO stock_batches(batch_date,source,status,notes,created_by) VALUES(:batch_date,:source,\'POSTED\',:notes,:uid)',[
                'batch_date'=>$rows[0]['batch_date'],'source'=>$source,'notes'=>$notes,'uid'=>$userId
            ]);
            $batchId=(int)$this->db->lastInsertId();
            foreach($rows as $row){
                $group=$this->db->fetchOne('SELECT id,code,capacity_kg FROM cylinder_groups WHERE id=:id AND active=1 FOR UPDATE',['id'=>(int)$row['group_id']]);
                if(!$group) throw new \InvalidArgumentException('Cylinder group is not active or does not exist.');
                $gas=(string)$row['actual_gas'];
                if(bccomp($gas,'0.000',3)<0||bccomp($gas,(string)$group['capacity_kg'],3)>0) throw new \InvalidArgumentException('Actual gas must be between 0 and cylinder capacity.');
                $location=(string)$row['location'];
                $customerId=$row['customer_id']!==null?(int)$row['customer_id']:null;
                if($location==='ISSUED'){
                    if($customerId===null) throw new \InvalidArgumentException('Issued opening stock requires a customer.');
                    $customer=$this->db->fetchOne('SELECT id FROM parties WHERE id=:id AND party_type=\'CUSTOMER\' AND active=1',['id'=>$customerId]);
                    if(!$customer) throw new \InvalidArgumentException('Selected customer is invalid or inactive.');
                }
                $quantity=max(1,(int)$row['quantity']);
                $codes=$row['codes']??[];
                if($location==='ISSUED' && $quantity>count($codes) && $row['code_mode']==='MANUAL') throw new \InvalidArgumentException('Manual mode requires one code per cylinder.');
                for($i=0;$i<$quantity;$i++){
                    $code=$row['code_mode']==='MANUAL'?(string)$codes[$i]:$this->nextCode((int)$group['id'],(string)$group['code'],(string)$group['capacity_kg']);
                    if($this->db->fetchOne('SELECT id FROM cylinders WHERE code=:code',['code'=>$code])) throw new \InvalidArgumentException('Duplicate cylinder code: '.$code);
                    $this->db->execute('INSERT INTO cylinders(code,group_id,gas_kg,location,customer_id,condition_code,active,created_by,updated_by) VALUES(:code,:group_id,:gas_kg,:location,:customer_id,:condition_code,1,:uid,:uid)',[
                        'code'=>$code,'group_id'=>$group['id'],'gas_kg'=>$gas,'location'=>$location==='ISSUED'?'CUSTOMER':'SHOP','customer_id'=>$customerId,'condition_code'=>$row['condition_code'],'uid'=>$userId
                    ]);
                    $cylinderId=(int)$this->db->lastInsertId();
                    $this->db->execute('INSERT INTO cylinder_movements(cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,source_document_type,source_document_id,stock_batch_id,created_by) VALUES(:cid,\'OPENING\',0,:gas,\'SHOP\',:loc,:customer,NULL,:batch,:batch,:uid)',[
                        'cid'=>$cylinderId,'gas'=>$gas,'loc'=>$location==='ISSUED'?'CUSTOMER':'SHOP','customer'=>$customerId,'batch'=>$batchId,'uid'=>$userId
                    ]);
                }
            }
            return $batchId;
        });
    }
    private function nextCode(int $groupId,string $groupCode,string $capacity): string
    {
        $prefix='CYL-'.strtoupper($groupCode).'-';
        $this->db->execute('INSERT INTO code_sequences(prefix,last_value) VALUES(:prefix,0) ON DUPLICATE KEY UPDATE last_value=last_value',['prefix'=>$prefix]);
        $row=$this->db->fetchOne('SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',['prefix'=>$prefix]);
        $next=(int)$row['last_value']+1;
        $this->db->execute('UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',['value'=>$next,'prefix'=>$prefix]);
        return $prefix.str_pad((string)$next,6,'0',STR_PAD_LEFT);
    }
}
