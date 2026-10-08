<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class UserService
{
    public function __construct(private readonly DB $db,private readonly AuditService $audit){}

    public function history():array
    {
        return $this->db->fetchAll(
            'SELECT u.id,u.username,u.full_name,u.active,u.force_password_change,u.default_counter_id,
                    r.id role_id,r.code role_code,r.name role_name,c.name counter_name
             FROM users u INNER JOIN roles r ON r.id=u.role_id
             LEFT JOIN counters c ON c.id=u.default_counter_id
             ORDER BY u.active DESC,u.username'
        );
    }

    public function roles():array{return $this->db->fetchAll('SELECT * FROM roles WHERE active=1 ORDER BY name');}
    public function permissions():array{return $this->db->fetchAll('SELECT * FROM permissions ORDER BY module,code');}

    public function rolePermissions(int $roleId):array
    {
        return array_map(
            static fn(array $r):int=>(int)$r['permission_id'],
            $this->db->fetchAll('SELECT permission_id FROM role_permissions WHERE role_id=:role',['role'=>$roleId])
        );
    }

    public function create(array $input,int $actorId,string $ip):int
    {
        $username=trim((string)($input['username']??''));
        $name=trim((string)($input['full_name']??''));
        $password=(string)($input['password']??'');
        $roleId=(int)($input['role_id']??0);
        $counterId=(int)($input['default_counter_id']??0);
        if($username===''||$name===''||strlen($password)<8||$roleId<1) throw new \InvalidArgumentException('Username, name, role and a password of at least 8 characters are required.');
        $this->db->execute(
            'INSERT INTO users(username,full_name,password_hash,role_id,default_counter_id,active,force_password_change)
             VALUES(:username,:name,:hash,:role,:counter,1,1)',
            ['username'=>$username,'name'=>$name,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$roleId,'counter'=>$counterId>0?$counterId:null]
        );
        $id=(int)$this->db->pdo()->lastInsertId();
        $this->audit->record($actorId,'CREATE','users',$id,null,['username'=>$username,'full_name'=>$name,'role_id'=>$roleId,'default_counter_id'=>$counterId],$ip);
        return $id;
    }

    public function setRolePermissions(int $roleId,array $permissionIds,int $actorId,string $ip):void
    {
        $this->db->transaction(function()use($roleId,$permissionIds,$actorId,$ip):void{
            $role=$this->db->fetchOne('SELECT * FROM roles WHERE id=:id FOR UPDATE',['id'=>$roleId]);
            if(!$role) throw new \InvalidArgumentException('Role not found.');
            $valid=$this->db->fetchAll('SELECT id FROM permissions');
            $allowed=array_map(static fn(array $r):int=>(int)$r['id'],$valid);
            $permissionIds=array_values(array_unique(array_map('intval',$permissionIds)));
            foreach($permissionIds as $id)if(!in_array($id,$allowed,true))throw new \InvalidArgumentException('Invalid permission selected.');
            $this->db->execute('DELETE FROM role_permissions WHERE role_id=:role',['role'=>$roleId]);
            foreach($permissionIds as $permissionId){
                $this->db->execute('INSERT INTO role_permissions(role_id,permission_id) VALUES(:role,:permission)',['role'=>$roleId,'permission'=>$permissionId]);
            }
            $this->audit->record($actorId,'UPDATE','roles',$roleId,null,['permission_ids'=>$permissionIds],$ip);
        });
    }

    public function counters():array{return $this->db->fetchAll('SELECT id,name FROM counters WHERE active=1 ORDER BY name');}
}
