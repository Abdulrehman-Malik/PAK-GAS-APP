<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class UserService
{
    public function __construct(private readonly DB $db,private readonly AuditService $audit){}
    public function roles():array{return $this->db->fetchAll('SELECT id,code,name,active FROM roles ORDER BY name');}
    public function permissions():array{return $this->db->fetchAll('SELECT id,code,name,module FROM permissions ORDER BY module,code');}
    public function users():array{return $this->db->fetchAll('SELECT u.id,u.username,u.full_name,u.active,u.force_password_change,r.id role_id,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id ORDER BY u.active DESC,u.username');}
    public function save(array $d,int $uid):int{
      $username=trim((string)($d['username']??''));$name=trim((string)($d['full_name']??''));$role=(int)($d['role_id']??0);$password=(string)($d['password']??'');
      if($username===''||$name===''||$role<1)throw new \InvalidArgumentException('Username, full name and role are required.');
      $this->db->transaction(function()use($username,$name,$role,$password,$d,$uid,&$id):void{
        $existing=$this->db->fetchOne('SELECT * FROM users WHERE username=:u FOR UPDATE',['u'=>$username]);
        if($existing){
          $id=(int)$existing['id'];
          $sql='UPDATE users SET full_name=:name,role_id=:role,active=:active,updated_by=:user';
          $params=['name'=>$name,'role'=>$role,'active'=>(int)($d['active']??1),'user'=>$uid,'id'=>$id];
          if($password!==''){$sql.=',password_hash=:hash,force_password_change=1';$params['hash']=password_hash($password,PASSWORD_DEFAULT);}
          $sql.=' WHERE id=:id';$this->db->execute($sql,$params);
        }else{
          if($password==='')throw new \InvalidArgumentException('Password is required for a new user.');
          $this->db->execute('INSERT INTO users(username,full_name,password_hash,role_id,default_counter_id,active,force_password_change,created_by,updated_by) VALUES(:u,:name,:hash,:role,:counter,1,1,:user,:user)',[
            'u'=>$username,'name'=>$name,'hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'counter'=>($d['default_counter_id']??null)?(int)$d['default_counter_id']:null,'user'=>$uid
          ]);$id=$this->db->lastInsertId();
        }
        $this->audit->record($uid,'SAVE','users',$id,null,['username'=>$username,'role_id'=>$role],null);
      });return $id;
    }
    public function permissionsForRole(int $roleId):array{return $this->db->fetchAll('SELECT permission_id FROM role_permissions WHERE role_id=:r',['r'=>$roleId]);}
    public function saveRolePermissions(int $roleId,array $permissionIds,int $uid):void{
      $this->db->transaction(function()use($roleId,$permissionIds,$uid):void{
       $role=$this->db->fetchOne('SELECT id FROM roles WHERE id=:id FOR UPDATE',['id'=>$roleId]);if(!$role)throw new \InvalidArgumentException('Role not found.');
       $this->db->execute('DELETE FROM role_permissions WHERE role_id=:r',['r'=>$roleId]);
       foreach(array_unique(array_map('intval',$permissionIds)) as $pid){if($pid>0)$this->db->execute('INSERT IGNORE INTO role_permissions(role_id,permission_id) VALUES(:r,:p)',['r'=>$roleId,'p'=>$pid]);}
       $this->audit->record($uid,'UPDATE','roles',$roleId,null,['permission_ids'=>$permissionIds],null);
      });
    }
}
