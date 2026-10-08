<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class LedgerService {
 public function __construct(private readonly DB $db){}
 public function post(int $partyId,string $date,string $docType,int $docId,string $debit,string $credit,string $narration,int $userId): int {
  if(bccomp($debit,'0.00',2)<0||bccomp($credit,'0.00',2)<0) throw new \InvalidArgumentException('Ledger amounts cannot be negative.');
  $this->db->execute('INSERT INTO ledger_entries(party_id,entry_date,doc_type,doc_id,debit,credit,narration,created_by) VALUES(:p,:d,:t,:i,:dr,:cr,:n,:u)',['p'=>$partyId,'d'=>$date,'t'=>$docType,'i'=>$docId,'dr'=>$debit,'cr'=>$credit,'n'=>$narration,'u'=>$userId]);
  return (int)$this->db->pdo()->lastInsertId();
 }
 public function balance(int $partyId): string {
  $r=$this->db->fetchOne('SELECT opening_balance + COALESCE((SELECT SUM(debit-credit) FROM ledger_entries WHERE party_id=:p),0) balance FROM parties WHERE id=:p',['p'=>$partyId]);
  return (string)($r['balance']??'0.00');
 }
}