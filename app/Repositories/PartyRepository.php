<?php
declare(strict_types=1);
namespace App\Repositories;
use App\Core\DB;
final class PartyRepository
{
    public function __construct(private readonly DB $db) {}
    public function paginate(string $type, string $search, int $limit, int $offset): array
    {
        $where='party_type=:type'; $params=['type'=>$type,'limit'=>$limit,'offset'=>$offset];
        if($search!==''){ $where.=' AND (code LIKE :search OR name LIKE :search OR phone LIKE :search)'; $params['search']='%'.$search.'%'; }
        $total=(int)($this->db->fetchOne("SELECT COUNT(*) c FROM parties WHERE {$where}", $params)['c']??0);
        $rows=$this->db->fetchAll("SELECT * FROM parties WHERE {$where} ORDER BY active DESC,name LIMIT :limit OFFSET :offset",$params);
        return ['rows'=>$rows,'total'=>$total];
    }
    public function find(int $id): ?array { return $this->db->fetchOne('SELECT * FROM parties WHERE id=:id',['id'=>$id]); }
    public function create(array $d): int { $this->db->execute('INSERT INTO parties(code,party_type,name,phone,address,allow_credit,credit_limit,opening_balance,active,notes,created_by,updated_by) VALUES(:code,:party_type,:name,:phone,:address,:allow_credit,:credit_limit,:opening_balance,1,:notes,:uid,:uid)',$d); return $this->db->lastInsertId(); }
    public function update(int $id,array $d): void { $d['id']=$id; $this->db->execute('UPDATE parties SET name=:name,phone=:phone,address=:address,allow_credit=:allow_credit,credit_limit=:credit_limit,notes=:notes,active=:active,updated_by=:uid WHERE id=:id',$d); }
}
