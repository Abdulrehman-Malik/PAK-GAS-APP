<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\DB;
final class StockBatchRepository {
 public function __construct(private readonly DB $db){}
 public function paginate(string $from,string $to,int $limit,int $offset):array{
  $where='1=1';$p=['limit'=>$limit,'offset'=>$offset];
  if($from!==''){$where.=' AND b.batch_date>=:from';$p['from']=$from;}
  if($to!==''){$where.=' AND b.batch_date<=:to';$p['to']=$to;}
  $total=(int)($this->db->fetchOne("SELECT COUNT(*) c FROM stock_batches b WHERE {$where}",$p)['c']??0);
  $rows=$this->db->fetchAll("SELECT b.*,u.full_name created_by_name,(SELECT COUNT(*) FROM cylinder_movements m WHERE m.stock_batch_id=b.id) cylinder_count FROM stock_batches b LEFT JOIN users u ON u.id=b.created_by WHERE {$where} ORDER BY b.id DESC LIMIT :limit OFFSET :offset",$p);
  return ['rows'=>$rows,'total'=>$total];
 }
 public function find(int $id):?array{return $this->db->fetchOne('SELECT * FROM stock_batches WHERE id=:id',['id'=>$id]);}
 public function cylinders(int $batchId):array{return $this->db->fetchAll('SELECT m.cylinder_id,c.code,m.to_location,m.after_gas_kg FROM cylinder_movements m JOIN cylinders c ON c.id=m.cylinder_id WHERE m.stock_batch_id=:id AND m.movement_type=\'OPENING\'',['id'=>$batchId]);}
}