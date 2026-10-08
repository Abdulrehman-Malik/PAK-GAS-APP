<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;

final class StockService
{
    public function __construct(private readonly DB $db, private readonly CodeGenerator $codes) {}

    /**
     * Move a locked cylinder through the only stock mutation path.
     */
    public function moveCylinder(
        int $cylinderId,
        string $toLocation,
        ?int $customerId,
        string $afterGas,
        string $movementType,
        ?string $rate,
        string $sourceDocumentType,
        int $sourceDocumentId,
        int $userId
    ): array {
        if (!in_array($toLocation, ['SHOP', 'CUSTOMER', 'SOLD'], true)) {
            throw new \InvalidArgumentException('Invalid cylinder destination.');
        }

        if ($toLocation === 'CUSTOMER' && $customerId === null) {
            throw new \InvalidArgumentException('Customer is required for an issued cylinder.');
        }

        if ($toLocation !== 'CUSTOMER' && $customerId !== null) {
            throw new \InvalidArgumentException('Only customer cylinders can have a customer.');
        }

        $cylinder = $this->db->fetchOne(
            'SELECT c.*, cg.capacity_kg
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id = c.group_id
             WHERE c.id = :id
             FOR UPDATE',
            ['id' => $cylinderId]
        );

        if (!$cylinder) {
            throw new \InvalidArgumentException('Cylinder not found.');
        }

        $beforeGas = (string) $cylinder['gas_kg'];
        $capacity = (string) $cylinder['capacity_kg'];

        if (bccomp($afterGas, '0.000', 3) < 0 || bccomp($afterGas, $capacity, 3) > 0) {
            throw new \InvalidArgumentException('Gas quantity is outside the cylinder capacity.');
        }

        if ($cylinder['location'] === 'SOLD' && $toLocation !== 'SOLD') {
            // Only an explicit sale void may reverse a sold cylinder.
            if ($movementType !== 'SALE_VOID') {
                throw new \InvalidArgumentException('A sold cylinder cannot move again.');
            }
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
             VALUES
             (:cylinder, :movement, :before, :after, :from_location, :to_location,
              :customer, :rate, :source_type, :source_id, :user)',
            [
                'cylinder' => $cylinderId,
                'movement' => $movementType,
                'before' => $beforeGas,
                'after' => $afterGas,
                'from_location' => $cylinder['location'],
                'to_location' => $toLocation,
                'customer' => $customerId,
                'rate' => $rate,
                'source_type' => $sourceDocumentType,
                'source_id' => $sourceDocumentId,
                'user' => $userId,
            ]
        );

        return [
            'before_gas' => $beforeGas,
            'after_gas' => $afterGas,
            'from_location' => $cylinder['location'],
            'to_location' => $toLocation,
            'capacity_kg' => $capacity,
        ];
    }

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

    public function createOpeningBatch(array $rows,int $userId,string $source='MANUAL',?string $notes=null): int
    {
        if ($rows === []) throw new \InvalidArgumentException('At least one opening-stock row is required.');
        return $this->db->transaction(function() use($rows,$userId,$source,$notes): int {
            $batchDate = (string)$rows[0]['batch_date'];
            foreach ($rows as $row) {
                if ((string)$row['batch_date'] !== $batchDate) throw new \InvalidArgumentException('All rows in a batch must use the same date.');
            }
            $this->db->execute('INSERT INTO stock_batches(batch_date,source,status,notes,created_by) VALUES(:batch_date,:source,\'POSTED\',:notes,:uid)',
                ['batch_date'=>$batchDate,'source'=>$source,'notes'=>$notes,'uid'=>$userId]);
            $batchId=(int)$this->db->lastInsertId();

            foreach($rows as $row){
                $group=$this->db->fetchOne('SELECT id,code,capacity_kg FROM cylinder_groups WHERE id=:id AND active=1 FOR UPDATE',['id'=>(int)$row['group_id']]);
                if(!$group) throw new \InvalidArgumentException('Cylinder group is not active or does not exist.');
                $gas=(string)$row['actual_gas'];
                if(bccomp($gas,'0.000',3)<0 || bccomp($gas,(string)$group['capacity_kg'],3)>0) {
                    throw new \InvalidArgumentException('Actual gas must be between 0 and cylinder capacity.');
                }
                $location=(string)$row['location'];
                if(!in_array($location,['SHOP','ISSUED'],true)) throw new \InvalidArgumentException('Invalid opening-stock location.');
                $customerId=$row['customer_id']!==null?(int)$row['customer_id']:null;
                if($location==='ISSUED'){
                    if($customerId===null) throw new \InvalidArgumentException('Issued opening stock requires a customer.');
                    $customer=$this->db->fetchOne('SELECT id FROM parties WHERE id=:id AND party_type=\'CUSTOMER\' AND active=1',['id'=>$customerId]);
                    if(!$customer) throw new \InvalidArgumentException('Selected customer is invalid or inactive.');
                } elseif($customerId !== null) {
                    throw new \InvalidArgumentException('A SHOP cylinder cannot be linked to a customer.');
                }

                $quantity=(int)$row['quantity'];
                if($quantity<1 || $quantity>1000) throw new \InvalidArgumentException('Quantity must be between 1 and 1000.');
                $codes=$row['codes']??[];
                if($row['code_mode']==='MANUAL' && count($codes)!==$quantity) {
                    throw new \InvalidArgumentException('Manual mode requires exactly one unique code per cylinder.');
                }
                if($row['code_mode']==='MANUAL' && count(array_unique(array_map('strtoupper',$codes)))!==$quantity) {
                    throw new \InvalidArgumentException('Manual cylinder codes must be unique.');
                }

                for($i=0;$i<$quantity;$i++){
                    $code=$this->codes->nextCylinderCode((int)$group['id'],(string)$group['code'],(string)$row['code_mode'],(string)($codes[$i]??''));
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
}
