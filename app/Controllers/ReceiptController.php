<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ReceiptService;

final class ReceiptController
{
    public function __construct(
        private readonly DB $db,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly ReceiptService $service
    ) {}

    public function index(): Response
    {
        return View::render('receipts/index', [
            'pageTitle'=>'Receipts',
            'customers'=>$this->db->fetchAll("SELECT id,code,name FROM parties WHERE party_type='CUSTOMER' AND active=1 ORDER BY name"),
            'user'=>$this->auth->user()
        ]);
    }

    public function data(): Response
    {
        $q=$this->request->query();
        $rows=$this->db->fetchAll("SELECT r.id,r.doc_no,r.receipt_date,r.amount,r.method,r.source,r.status,p.code customer_code,p.name customer_name FROM receipts r JOIN parties p ON p.id=r.party_id WHERE r.receipt_date BETWEEN :from AND :to ORDER BY r.id DESC LIMIT 200",['from'=>(string)($q['from']??date('Y-m-d')),'to'=>(string)($q['to']??date('Y-m-d'))]);
        return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);
    }

    public function print(int $id): Response
    {
        $receipt=$this->db->fetchOne("SELECT r.*,p.code customer_code,p.name customer_name FROM receipts r JOIN parties p ON p.id=r.party_id WHERE r.id=:id",['id'=>$id]);
        if(!$receipt)return Response::html(View::errorPage('Receipt not found.',404),404);
        return View::render('print/receipt',['receipt'=>$receipt,'appName'=>\App\Core\Config::load(base_path())['app_name'],'user'=>$this->auth->user()],null);
    }

    public function store(): Response
    {
        try{return Response::json(['ok'=>true,'data'=>$this->service->post($this->request->input(),(int)$this->auth->user()['id']),'message'=>'Receipt posted.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function void(int $id): Response
    {
        try{$this->service->void($id,(int)$this->auth->user()['id'],(string)($this->request->input()['reason']??''));return Response::json(['ok'=>true,'message'=>'Receipt voided.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
