<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\UserService;

final class UserController
{
    public function __construct(private readonly UserService $service,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('users/index',['pageTitle'=>'Users & Roles','users'=>$this->service->history(),'roles'=>$this->service->roles(),'permissions'=>$this->service->permissions(),'counters'=>$this->service->counters(),'_base_path'=>base_path(),'user'=>$this->auth->user()]);}
    public function data():Response{return Response::json(['ok'=>true,'data'=>['users'=>$this->service->history(),'roles'=>$this->service->roles(),'permissions'=>$this->service->permissions()]]);}
    public function store():Response{
        try{$id=$this->service->create($this->request->input(),(int)$this->auth->user()['id'],$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'User created.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function permissions():Response{
        try{$d=$this->request->input();$this->service->setRolePermissions((int)$d['role_id'],(array)($d['permission_ids']??[]),(int)$this->auth->user()['id'],$this->request->ip());return Response::json(['ok'=>true,'message'=>'Role permissions saved.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function rolePermissions():Response{return Response::json(['ok'=>true,'data'=>$this->service->rolePermissions((int)($this->request->query()['role_id']??0))]);}
}
