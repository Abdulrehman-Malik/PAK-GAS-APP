<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;

final class StockService
{
    public function move(
        int $cylinderId,
        string $movementType,
        string $toLocation,
        string $afterGas,
        ?int $customerId,
        string $rate,
        string $documentType,
        int $documentId,
        int $userId,
        ?string $notes = null
    ): array {
        $cylinder = $this->db->fetchOne(
            'SELECT c.*, cg.capacity_kg
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id = c.group_id
             WHERE c.id = :id
             FOR UPDATE',
            ['id' => $cylinderId]
        );
        if (!$cylinder || !(int) $cylinder['active']) {
            throw new \InvalidArgumentException('Cylinder not found or inactive.');
        }

        $before = (string) $cylinder['gas_kg'];
        $capacity = (string) $cylinder['capacity_kg'];
        if (bccomp($afterGas, '0.000', 3) < 0 || bccomp($afterGas, $capacity, 3) > 0) {
            throw new \InvalidArgumentException('Cylinder gas is outside its capacity.');
        }
        if ($toLocation === 'CUSTOMER' && $customerId === null) {
            throw new \InvalidArgumentException('A customer is required when issuing a cylinder.');
        }
        $movementCustomerId = $customerId;
        if ($toLocation !== 'CUSTOMER') {
            $customerId = null;
        }
        if ($cylinder['location'] === 'SOLD') {
            throw new \InvalidArgumentException('Sold cylinder cannot be moved again.');
        }

        $this->db->execute(
            'UPDATE cylinders
             SET gas_kg = :gas, location = :location, customer_id = :customer, updated_by = :user
             WHERE id = :id',
            [
                'gas' => $afterGas,
                'location' => $toLocation,
                'customer' => $customerId,
                'user' => $userId,
                'id' => $cylinderId,
            ]
        );

        $this->db->execute(
            'INSERT INTO cylinder_movements
             (cylinder_id, movement_type, before_gas_kg, after_gas_kg, from_location, to_location,
              customer_id, rate, source_document_type, source_document_id, created_by)
             VALUES (:cylinder, :movement, :before, :after, :from_location, :to_location,
                     :customer, :rate, :document_type, :document_id, :user)',
            [
                'cylinder' => $cylinderId,
                'movement' => $movementType,
                'before' => $before,
                'after' => $afterGas,
                'from_location' => $cylinder['location'],
                'to_location' => $toLocation,
                'customer' => $movementCustomerId,
                'rate' => $rate,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'user' => $userId,
            ]
        );

        return [
            'before_gas' => $before,
            'after_gas' => $afterGas,
            'from_location' => $cylinder['location'],
            'to_location' => $toLocation,
            'customer_id' => $customerId,
        ];
    }

    public function reverseMovement(array $movement, int $userId, int $documentId, string $documentType = 'SALE_VOID'): void
    {
        $cylinder = $this->db->fetchOne(
            'SELECT * FROM cylinders WHERE id=:id FOR UPDATE',
            ['id'=>$movement['cylinder_id']]
        );
        if (!$cylinder) {
            throw new \InvalidArgumentException('Cylinder no longer exists.');
        }

        $this->db->execute(
            'UPDATE cylinders
             SET gas_kg=:gas, location=:location, customer_id=:customer, updated_by=:user
             WHERE id=:id',
            [
                'gas'=>$movement['before_gas_kg'],
                'location'=>$movement['from_location'],
                'customer'=>$movement['from_location']==='CUSTOMER'
                    ? ($movement['customer_id'] !== null ? (int)$movement['customer_id'] : null)
                    : null,
                'user'=>$userId,
                'id'=>$movement['cylinder_id'],
            ]
        );

        $this->db->execute(
            'INSERT INTO cylinder_movements
             (cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,rate,source_document_type,source_document_id,created_by)
             VALUES (:cylinder,\'VOID_REVERSAL\',:before,:after,:from_location,:to_location,:customer,:rate,:doc_type,:doc_id,:user)',
            [
                'cylinder'=>$movement['cylinder_id'],
                'before'=>$cylinder['gas_kg'],
                'after'=>$movement['before_gas_kg'],
                'from_location'=>$cylinder['location'],
                'to_location'=>$movement['from_location'],
                'customer'=>$movement['customer_id'],
                'rate'=>$movement['rate'],
                'doc_type'=>$documentType,
                'doc_id'=>$documentId,
                'user'=>$userId,
            ]
        );
    }

    public function createManualCylinder(int $groupId,string $code,string $gas,string $condition,int $userId):int
    {
        $group=$this->db->fetchOne('SELECT id,capacity_kg FROM cylinder_groups WHERE id=:id AND active=1 FOR UPDATE',['id'=>$groupId]);
        if(!$group)throw new \InvalidArgumentException('Cylinder group is invalid or inactive.');
        if(bccomp($gas,'0.000',3)<0||bccomp($gas,(string)$group['capacity_kg'],3)>0)throw new \InvalidArgumentException('Gas is outside cylinder capacity.');
        if(!in_array($condition,['GOOD','DAMAGED'],true))throw new \InvalidArgumentException('Invalid cylinder condition.');
        $this->db->execute(
            "INSERT INTO cylinders(code,group_id,gas_kg,location,customer_id,condition_code,source,active,created_by,updated_by)
             VALUES(:code,:group,:gas,'SHOP',NULL,:condition,'MANUAL',1,:user,:user)",
            ['code'=>$code,'group'=>$groupId,'gas'=>$gas,'condition'=>$condition,'user'=>$userId]
        );
        $id=(int)$this->db->pdo()->lastInsertId();
        $this->db->execute(
            "INSERT INTO cylinder_movements(cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,rate,source_document_type,source_document_id,created_by)
             VALUES(:id,'ADJUSTMENT',0,:gas,'SHOP','SHOP',NULL,'0.00','CYLINDER',:id,:user)",
            ['id'=>$id,'gas'=>$gas,'user'=>$userId]
        );
        return $id;
    }

    public function createPurchasedCylinder(
        int $groupId,
        string $code,
        string $gas,
        string $condition,
        int $userId,
        int $purchaseId
    ): int {
        $group = $this->db->fetchOne(
            'SELECT id, code, capacity_kg FROM cylinder_groups WHERE id = :id AND active = 1 FOR UPDATE',
            ['id' => $groupId]
        );
        if (!$group) {
            throw new \InvalidArgumentException('Cylinder group is invalid or inactive.');
        }
        if (bccomp($gas, '0.000', 3) < 0 || bccomp($gas, (string) $group['capacity_kg'], 3) > 0) {
            throw new \InvalidArgumentException('Initial gas is outside cylinder capacity.');
        }
        if (!in_array($condition, ['GOOD', 'DAMAGED'], true)) {
            throw new \InvalidArgumentException('Invalid cylinder condition.');
        }

        $this->db->execute(
            'INSERT INTO cylinders
             (code, group_id, gas_kg, location, customer_id, condition_code, source, active, created_by, updated_by)
             VALUES (:code, :group_id, :gas, \'SHOP\', NULL, :condition, \'PURCHASE\', 1, :user, :user)',
            [
                'code' => $code,
                'group_id' => $groupId,
                'gas' => $gas,
                'condition' => $condition,
                'user' => $userId,
            ]
        );
        $id = (int) $this->db->pdo()->lastInsertId();

        $this->db->execute(
            'INSERT INTO cylinder_movements
             (cylinder_id, movement_type, before_gas_kg, after_gas_kg, from_location, to_location,
              customer_id, rate, source_document_type, source_document_id, created_by)
             VALUES (:cylinder, \'PURCHASE_NEW\', 0, :gas, \'SHOP\', \'SHOP\',
                     NULL, :rate, \'PURCHASE\', :purchase, :user)',
            [
                'cylinder' => $id,
                'gas' => $gas,
                'rate' => '0.00',
                'purchase' => $purchaseId,
                'user' => $userId,
            ]
        );

        return $id;
    }

    public function __construct(private readonly DB $db, private readonly CodeGenerator $codes) {}

    public function voidOpeningBatch(int $batchId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') throw new \InvalidArgumentException('Void reason is required.');
        $this->db->transaction(function () use ($batchId, $userId, $reason): void {
            $batch = $this->db->fetchOne('SELECT * FROM stock_batches WHERE id=:id FOR UPDATE', ['id'=>$batchId]);
            if (!$batch) throw new \InvalidArgumentException('Opening stock batch not found.');
            if ($batch['status'] !== 'POSTED') throw new \InvalidArgumentException('Only a posted opening-stock batch can be voided.');

            $movements = $this->db->fetchAll(
                "SELECT m.*,c.code FROM cylinder_movements m
                 INNER JOIN cylinders c ON c.id=m.cylinder_id
                 WHERE m.stock_batch_id=:batch AND m.movement_type='OPENING'
                 FOR UPDATE",
                ['batch'=>$batchId]
            );
            foreach ($movements as $m) {
                $later = $this->db->fetchOne(
                    "SELECT id,movement_type FROM cylinder_movements
                     WHERE cylinder_id=:cid AND id>:opening_id
                     ORDER BY id LIMIT 1",
                    ['cid'=>$m['cylinder_id'],'opening_id'=>$m['id']]
                );
                if ($later) {
                    throw new \InvalidArgumentException('Cannot void batch: cylinder '.$m['code'].' has a later '.$later['movement_type'].' movement.');
                }
            }

            foreach ($movements as $m) {
                $this->db->execute(
                    "INSERT INTO cylinder_movements
                     (cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,source_document_type,source_document_id,stock_batch_id,created_by)
                     VALUES (:cid,'OPENING_VOID',:before,0,:from_loc,'SHOP',NULL,'STOCK_BATCH',:batch,:batch,:uid)",
                    [
                        'cid'=>$m['cylinder_id'], 'before'=>$m['after_gas_kg'],
                        'from_loc'=>$m['to_location'], 'batch'=>$batchId, 'uid'=>$userId
                    ]
                );
                $this->db->execute(
                    "UPDATE cylinders SET gas_kg=0,location='SHOP',customer_id=NULL,active=0,updated_by=:uid WHERE id=:id",
                    ['uid'=>$userId,'id'=>$m['cylinder_id']]
                );
            }
            $this->db->execute(
                "UPDATE stock_batches SET status='VOID',voided_at=NOW(),voided_by=:uid,void_reason=:reason WHERE id=:id",
                ['uid'=>$userId,'reason'=>$reason,'id'=>$batchId]
            );
        });
    }

    public function createOpeningBatch(array $rows,int $userId,string $source='MANUAL',?string $notes=null):int
    {
        if($rows===[])throw new \InvalidArgumentException('At least one opening-stock row is required.');

        $work=function():int use($rows,$userId,$source,$notes){
            $batchDate=(string)$rows[0]['batch_date'];
            foreach($rows as $row){
                if((string)$row['batch_date']!==$batchDate){
                    throw new \InvalidArgumentException('All rows in a batch must use the same date.');
                }
            }

            $this->db->execute(
                "INSERT INTO stock_batches(batch_date,source,status,notes,created_by)
                 VALUES(:date,:source,'POSTED',:notes,:user)",
                ['date'=>$batchDate,'source'=>$source,'notes'=>$notes,'user'=>$userId]
            );
            $batchId=(int)$this->db->pdo()->lastInsertId();

            foreach($rows as $row){
                $group=$this->db->fetchOne(
                    'SELECT id,code,capacity_kg FROM cylinder_groups WHERE id=:id AND active=1 FOR UPDATE',
                    ['id'=>(int)$row['group_id']]
                );
                if(!$group)throw new \InvalidArgumentException('Cylinder group is invalid or inactive.');

                $gas=(string)$row['actual_gas'];
                if(bccomp($gas,'0.000',3)<0||bccomp($gas,(string)$group['capacity_kg'],3)>0){
                    throw new \InvalidArgumentException('Actual gas must be between 0 and cylinder capacity.');
                }

                $location=(string)$row['location'];
                if(!in_array($location,['SHOP','ISSUED'],true))throw new \InvalidArgumentException('Invalid opening-stock location.');

                $customerId=$row['customer_id']!==null?(int)$row['customer_id']:null;
                if($location==='ISSUED'){
                    if($customerId===null)throw new \InvalidArgumentException('Issued opening stock requires a customer.');
                    $customer=$this->db->fetchOne(
                        "SELECT id FROM parties WHERE id=:id AND party_type='CUSTOMER' AND active=1",
                        ['id'=>$customerId]
                    );
                    if(!$customer)throw new \InvalidArgumentException('Selected customer is invalid or inactive.');
                }elseif($customerId!==null){
                    throw new \InvalidArgumentException('A SHOP cylinder cannot be linked to a customer.');
                }

                $quantity=(int)$row['quantity'];
                if($quantity<1||$quantity>1000)throw new \InvalidArgumentException('Quantity must be between 1 and 1000.');
                $codes=$row['codes']??[];
                if(!is_array($codes))$codes=preg_split('/[\s,]+/',(string)$codes,-1,PREG_SPLIT_NO_EMPTY)?:[];

                if(strtoupper((string)$row['code_mode'])==='MANUAL'){
                    if(count($codes)!==$quantity)throw new \InvalidArgumentException('Manual mode requires exactly one unique code per cylinder.');
                    $normalized=array_map(static fn($v):string=>strtoupper(trim((string)$v)),$codes);
                    if(count(array_unique($normalized))!==$quantity)throw new \InvalidArgumentException('Manual cylinder codes must be unique.');
                    $codes=$normalized;
                }

                for($i=0;$i<$quantity;$i++){
                    $code=$this->codes->nextCylinderCode(
                        (int)$group['id'],
                        (string)$group['code'],
                        strtoupper((string)$row['code_mode']),
                        (string)($codes[$i]??'')
                    );
                    $this->db->execute(
                        "INSERT INTO cylinders(code,group_id,gas_kg,location,customer_id,condition_code,source,active,created_by,updated_by)
                         VALUES(:code,:group,:gas,:location,:customer,:condition,'OPENING',1,:user,:user)",
                        [
                            'code'=>$code,'group'=>$group['id'],'gas'=>$gas,
                            'location'=>$location==='ISSUED'?'CUSTOMER':'SHOP',
                            'customer'=>$customerId,'condition'=>$row['condition_code'],'user'=>$userId
                        ]
                    );
                    $cylinderId=(int)$this->db->pdo()->lastInsertId();
                    $this->db->execute(
                        "INSERT INTO cylinder_movements
                         (cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,source_document_type,source_document_id,stock_batch_id,created_by)
                         VALUES(:cylinder,'OPENING',0,:gas,'SHOP',:location,:customer,NULL,:batch,:batch,:user)",
                        [
                            'cylinder'=>$cylinderId,'gas'=>$gas,
                            'location'=>$location==='ISSUED'?'CUSTOMER':'SHOP',
                            'customer'=>$customerId,'batch'=>$batchId,'user'=>$userId
                        ]
                    );
                }
            }
            return $batchId;
        };

        if($this->db->pdo()->inTransaction())return $work();
        return $this->db->transaction($work);
    }

}
