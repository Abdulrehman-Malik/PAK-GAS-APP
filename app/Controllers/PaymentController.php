<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\PaymentService;

final class PaymentController
{
    public function __construct(private readonly DB $db, private readonly Auth $auth, private readonly Request $request, private readonly PaymentService $service) {}
    public function index(): Response
    {
        return View::render('payments/index',['pageTitle'=>'Supplier Payments','suppliers'=>$this->db->fetchAll("SELECT id,code,name FROM parties WHERE party_type='SUPPLIER' AND active=1 ORDER BY name"),'user'=>$this->auth->user()]);
    }
    public function data(): Response
    {
        $q=$this->request->query();
        $rows=$this->db->fetchAll("SELECT p.id,p.doc_no,p.payment_date,p.amount,p.method,p.source,p.status,pt.code supplier_code,pt.name supplier_name FROM payments p JOIN parties pt ON pt.id=p.party_id WHERE p.payment_date BETWEEN :from AND :to ORDER BY p.id DESC LIMIT 200",['from'=>(string)($q['from']??date('Y-m-d')),'to'=>(string)($q['to']??date('Y-m-d'))]);
        return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);
    }
    public function store(): Response
    {
        try{return Response::json(['ok'=>true,'data'=>$this->service->post($this->request->input(),(int)$this->auth->user()['id']),'message'=>'Payment posted.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function void(int $id): Response
    {
        try{$this->service->void($id,(int)$this->auth->user()['id'],(string)($this->request->input()['reason']??''));return Response::json(['ok'=>true,'message'=>'Payment voided.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
