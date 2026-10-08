<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\UserService;

final class UserController
{
    public function __construct(private readonly DB $db,private readonly Auth $auth,private readonly Request $request,private readonly UserService $service){}
    public function index():Response{return View::render('users/index',['pageTitle'=>'Users & Roles','roles'=>$this->service->roles(),'permissions'=>$this->service->permissions(),'users'=>$this->service->users(),'counters'=>$this->db->fetchAll('SELECT id,name FROM counters WHERE active=1 ORDER BY name'),'user'=>$this->auth->user()]);}
    public function save():Response{try{$id=$this->service->save($this->request->input(),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'User saved.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function rolePermissions(int $role):Response{return Response::json(['ok'=>true,'data'=>$this->service->permissionsForRole($role)]);}
    public function saveRolePermissions(int $role):Response{try{$ids=$this->request->input()['permission_ids']??[];if(!is_array($ids))$ids=[$ids];$this->service->saveRolePermissions($role,$ids,(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Role permissions saved.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
}
