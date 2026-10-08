<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ExpenseService;

final class ExpenseController
{
    public function __construct(private readonly DB $db,private readonly Auth $auth,private readonly Request $request,private readonly ExpenseService $service){}
    public function index():Response{return View::render('expenses/index',['pageTitle'=>'Expenses','categories'=>$this->db->fetchAll('SELECT id,name FROM expense_categories WHERE active=1 ORDER BY name'),'user'=>$this->auth->user()]);}
    public function data():Response{$rows=$this->db->fetchAll("SELECT e.*,c.name category_name FROM expenses e JOIN expense_categories c ON c.id=e.category_id ORDER BY e.id DESC LIMIT 300");return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);}
    public function store():Response{try{$id=$this->service->post($this->request->input(),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Expense posted.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function void(int $id):Response{try{$this->service->void($id,(int)$this->auth->user()['id'],(string)($this->request->input()['reason']??''));return Response::json(['ok'=>true,'message'=>'Expense voided.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
}
