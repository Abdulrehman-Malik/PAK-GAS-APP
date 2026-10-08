<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class CounterService {
 public function __construct(private readonly DB $db){}
 public function open(int $counterId,string $openingCash,int $userId): int {
  if(bccomp($openingCash,'0.00',2)<0) throw new \InvalidArgumentException('Opening cash cannot be negative.');
  return $this->db->transaction(function()use($counterId,$openingCash,$userId){$c=$this->db->fetchOne('SELECT * FROM counters WHERE id=:id AND active=1 FOR UPDATE',['id'=>$counterId]);if(!$c)throw new \InvalidArgumentException('Counter not found.');$open=$this->db->fetchOne('SELECT id FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL FOR UPDATE',['c'=>$counterId]);if($open)throw new \InvalidArgumentException('Counter already has an open session.');$this->db->execute('INSERT INTO counter_sessions(counter_id,opened_by,opening_cash) VALUES(:c,:u,:a)',['c'=>$counterId,'u'=>$userId,'a'=>$openingCash]);return (int)$this->db->pdo()->lastInsertId();});
 }
 public function close(int $counterId,string $countedCash,int $userId): array {
  return $this->db->transaction(function()use($counterId,$countedCash,$userId){$s=$this->db->fetchOne('SELECT * FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL FOR UPDATE',['c'=>$counterId]);if(!$s)throw new \InvalidArgumentException('No open counter session.');$r=$this->db->fetchOne("SELECT :opening + COALESCE(SUM(CASE WHEN direction='IN' THEN amount ELSE -amount END),0) expected FROM cash_entries WHERE session_id=:s",['opening'=>$s['opening_cash'],'s'=>$s['id']]);$expected=(string)($r['expected']??$s['opening_cash']);$variance=bcsub($countedCash,$expected,2);$this->db->execute('UPDATE counter_sessions SET closed_at=NOW(),closed_by=:u,counted_cash=:counted,expected_cash=:expected,variance=:variance WHERE id=:id',['u'=>$userId,'counted'=>$countedCash,'expected'=>$expected,'variance'=>$variance,'id'=>$s['id']]);return ['expected'=>$expected,'counted'=>$countedCash,'variance'=>$variance];});
 }
 public function manualEntry(int $counterId,string $date,string $direction,string $amount,string $reason,int $userId):int {
  $reason=trim($reason);
  if(!in_array($direction,['IN','OUT'],true)||bccomp($amount,'0.00',2)<=0) throw new \InvalidArgumentException('Invalid manual cash entry.');
  if($reason==='') throw new \InvalidArgumentException('Reason is required.');
  return $this->db->transaction(function()use($counterId,$date,$direction,$amount,$reason,$userId):int{
   $s=$this->db->fetchOne('SELECT id FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL FOR UPDATE',['c'=>$counterId]);
   if(!$s)throw new \InvalidArgumentException('No open counter session.');
   $this->db->execute('INSERT INTO cash_entries(counter_id,session_id,entry_date,direction,amount,doc_type,doc_id,reason,created_by) VALUES(:c,:s,:d,:dir,:a,\'MANUAL\',NULL,:reason,:u)',[
    'c'=>$counterId,'s'=>$s['id'],'d'=>$date,'dir'=>$direction,'a'=>$amount,'reason'=>$reason,'u'=>$userId
   ]);
   return (int)$this->db->lastInsertId();
  });
 }
 public function summary(int $counterId):?array{
  return $this->db->fetchOne("SELECT s.*,COALESCE(SUM(CASE WHEN ce.direction='IN' THEN ce.amount ELSE 0 END),0) cash_in,COALESCE(SUM(CASE WHEN ce.direction='OUT' THEN ce.amount ELSE 0 END),0) cash_out,(s.opening_cash+COALESCE(SUM(CASE WHEN ce.direction='IN' THEN ce.amount ELSE -ce.amount END),0)) expected FROM counter_sessions s LEFT JOIN cash_entries ce ON ce.session_id=s.id WHERE s.id=(SELECT id FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL ORDER BY id DESC LIMIT 1) GROUP BY s.id",['c'=>$counterId]);
 }

 public function active():array{return $this->db->fetchAll('SELECT * FROM counters WHERE active=1 ORDER BY name');}
 public function current(int $counterId):?array{return $this->db->fetchOne('SELECT * FROM counter_sessions WHERE counter_id=:c AND closed_at IS NULL ORDER BY id DESC LIMIT 1',['c'=>$counterId]);}
}