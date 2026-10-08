<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ExpenseService;

final class ExpenseController
{
    public function __construct(private readonly ExpenseService $service,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('expenses/index',['pageTitle'=>'Expenses','categories'=>$this->service->categories(),'today'=>date('Y-m-d'),'user'=>$this->auth->user(),'_base_path'=>base_path()]);}
    public function data():Response{$q=$this->request->query();return Response::json(['ok'=>true,'data'=>$this->service->history($q,min(100,max(10,(int)($q['limit']??50))),max(0,(int)($q['offset']??0)))]);}
    public function categories():Response{return Response::json(['ok'=>true,'data'=>$this->service->categories()]);}
    public function store():Response{try{$r=$this->service->post($this->request->input(),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>$r,'message'=>'Expense posted.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function void():Response{try{$d=$this->request->input();$this->service->void((int)($d['id']??0),(int)$this->auth->user()['id'],(string)($d['reason']??''));return Response::json(['ok'=>true,'message'=>'Expense voided.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
}
