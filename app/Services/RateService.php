<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class RateService {
 public function __construct(private readonly DB $db){}
 public function resolve(int $groupId,string $date): array {
  $r=$this->db->fetchOne('SELECT gas_rate,cylinder_price FROM rates WHERE group_id=:g AND effective_date<=:d AND active=1 ORDER BY effective_date DESC,id DESC LIMIT 1',['g'=>$groupId,'d'=>$date]);
  if(!$r) throw new \InvalidArgumentException('No effective rate configured for this cylinder group and date.');
  return $r;
 }
}