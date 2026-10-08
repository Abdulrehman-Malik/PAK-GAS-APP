<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\PurchaseService;

final class PurchaseController
{
    public function __construct(private readonly PurchaseService $service, private readonly Auth $auth, private readonly Request $request) {}
    public function index():Response{return View::render('purchases/index',['pageTitle'=>'Purchases','user'=>$this->auth->user(),'today'=>date('Y-m-d'),'_base_path'=>base_path()]);}
    public function data():Response{
        $q=$this->request->query();$limit=min(100,max(10,(int)($q['limit']??50)));$offset=max(0,(int)($q['offset']??0));
        return Response::json(['ok'=>true,'data'=>$this->service->history($q,$limit,$offset)]);
    }
    public function store():Response{
        try{
            $input=$this->request->input();
            $input['lines']=json_decode((string)($input['lines_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $r=$this->service->post($input,(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>$r,'message'=>'Purchase posted.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function void():Response{
        try{$d=$this->request->input();$this->service->void((int)($d['id']??0),(int)$this->auth->user()['id'],(string)($d['reason']??''));return Response::json(['ok'=>true,'message'=>'Purchase voided.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function suppliers():Response{$q=trim((string)($this->request->query()['q']??''));return Response::json(['ok'=>true,'data'=>$this->service->suppliers($q)]);}
    public function cylinders():Response{return Response::json(['ok'=>true,'data'=>$this->service->shopCylinders($this->request->query())]);}
    public function groups():Response{return Response::json(['ok'=>true,'data'=>$this->service->groups()]);}
}
