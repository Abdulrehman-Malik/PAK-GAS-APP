<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

final class CylinderRepository
{
    public function __construct(private readonly DB $db) {}

    public function paginate(string $search,int $groupId,int $limit,int $offset):array{
        $where='1=1';$params=['limit'=>$limit,'offset'=>$offset];
        if($search!==''){$where.=' AND (c.code LIKE :search OR c.group_name LIKE :search)';$params['search']='%'.$search.'%';}
        if($groupId>0){$where.=' AND c.group_id=:group_id';$params['group_id']=$groupId;}
        $total=(int)($this->db->fetchOne("SELECT COUNT(*) c FROM v_cylinder_status c WHERE {$where}",$params)['c']??0);
        return ['rows'=>$this->db->fetchAll("SELECT * FROM v_cylinder_status c WHERE {$where} ORDER BY c.active DESC,c.code LIMIT :limit OFFSET :offset",$params),'total'=>$total];
    }
    public function find(int $id):?array{return $this->db->fetchOne('SELECT * FROM v_cylinder_status WHERE id=:id',['id'=>$id]);}
    public function rawGroup(int $id):?array{return $this->db->fetchOne('SELECT * FROM cylinder_groups WHERE id=:id',['id'=>$id]);}
    public function create(array $d):int{$this->db->execute('INSERT INTO cylinders(code,group_id,gas_kg,location,condition_code,active,notes,created_by,updated_by) VALUES(:code,:group_id,:gas_kg,"SHOP",:condition_code,1,:notes,:uid,:uid)',$d);return $this->db->lastInsertId();}
    public function updateMaster(int $id,array $d):void{$d['id']=$id;$this->db->execute('UPDATE cylinders SET condition_code=:condition_code,active=:active,notes=:notes,updated_by=:uid WHERE id=:id',$d);}
    public function movementCount(int $id):int{return (int)($this->db->fetchOne('SELECT COUNT(*) c FROM cylinder_movements WHERE cylinder_id=:id',['id'=>$id])['c']??0);}
    public function delete(int $id):void{$this->db->execute('DELETE FROM cylinders WHERE id=:id',['id'=>$id]);}
    public function history(int $id):array{return $this->db->fetchAll('SELECT m.*,u.full_name user_name FROM cylinder_movements m LEFT JOIN users u ON u.id=m.created_by WHERE m.cylinder_id=:id ORDER BY m.id DESC',['id'=>$id]);}
}
