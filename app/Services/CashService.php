<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class CashService {
 public function __construct(private readonly DB $db){}
 public function post(int $counterId,string $date,string $direction,string $amount,string $docType,int $docId,int $userId): int {
  if(!in_array($direction,['IN','OUT'],true)||bccomp($amount,'0.00',2)<=0) throw new \InvalidArgumentException('Invalid cash entry.');
  $s=$this->db->fetchOne('SELECT id FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL ORDER BY id DESC LIMIT 1',['c'=>$counterId]);
  if(!$s) throw new \InvalidArgumentException('No open cash session for the selected counter.');
  $this->db->execute('INSERT INTO cash_entries(counter_id,session_id,entry_date,direction,amount,doc_type,doc_id,created_by) VALUES(:c,:s,:d,:dir,:a,:t,:i,:u)',[
   'c'=>$counterId,'s'=>$s['id'],'d'=>$date,'dir'=>$direction,'a'=>$amount,'t'=>$docType,'i'=>$docId,'u'=>$userId]);
  return (int)$this->db->pdo()->lastInsertId();
 }
}