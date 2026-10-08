<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\PurchaseService;

final class PurchaseController
{
    public function __construct(private readonly DB $db, private readonly Auth $auth, private readonly Request $request, private readonly PurchaseService $service) {}
    public function index(): Response
    {
        return View::render('purchases/index',['pageTitle'=>'Purchases','suppliers'=>$this->db->fetchAll("SELECT id,code,name FROM parties WHERE party_type='SUPPLIER' AND active=1 ORDER BY name"),'groups'=>$this->db->fetchAll('SELECT id,code,name,capacity_kg FROM cylinder_groups WHERE active=1 ORDER BY code'),'cylinders'=>$this->db->fetchAll("SELECT c.id,c.code,c.group_id,c.gas_kg,cg.capacity_kg FROM cylinders c JOIN cylinder_groups cg ON cg.id=c.group_id WHERE c.active=1 AND c.location='SHOP' AND c.condition_code='GOOD' ORDER BY c.code"),'user'=>$this->auth->user()]);
    }
    public function data(): Response
    {
        $rows=$this->db->fetchAll("SELECT p.*,s.code supplier_code,s.name supplier_name FROM purchases p JOIN parties s ON s.id=p.supplier_id ORDER BY p.id DESC LIMIT 200");
        return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);
    }
    public function store(): Response
    {
        try{return Response::json(['ok'=>true,'data'=>$this->service->post($this->request->input(),(int)$this->auth->user()['id']),'message'=>'Purchase posted.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function void(int $id): Response
    {
        try{$this->service->void($id,(int)$this->auth->user()['id'],(string)($this->request->input()['reason']??''));return Response::json(['ok'=>true,'message'=>'Purchase voided.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
