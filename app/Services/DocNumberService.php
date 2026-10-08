<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class DocNumberService {
 public function __construct(private readonly DB $db){}
 public function next(string $type): string {
  $row=$this->db->fetchOne('SELECT id,prefix,last_value FROM doc_sequences WHERE doc_type=:t ORDER BY id LIMIT 1 FOR UPDATE',['t'=>$type]);
  if(!$row){$prefix=strtoupper(substr($type,0,3)).'-';$this->db->execute('INSERT INTO doc_sequences(doc_type,prefix,last_value) VALUES(:t,:p,0)',['t'=>$type,'p'=>$prefix]);$row=$this->db->fetchOne('SELECT id,prefix,last_value FROM doc_sequences WHERE doc_type=:t ORDER BY id LIMIT 1 FOR UPDATE',['t'=>$type]);}
  $n=(int)$row['last_value']+1;$this->db->execute('UPDATE doc_sequences SET last_value=:n WHERE id=:id',['n'=>$n,'id'=>$row['id']]);
  return (string)$row['prefix'].str_pad((string)$n,6,'0',STR_PAD_LEFT);
 }
}