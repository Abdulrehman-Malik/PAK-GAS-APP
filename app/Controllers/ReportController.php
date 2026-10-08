<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ReportService;

final class ReportController
{
    public function __construct(private readonly ReportService $reports,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('reports/index',['pageTitle'=>'Reports','user'=>$this->auth->user(),'today'=>date('Y-m-d'),'_base_path'=>base_path()]);}
    public function data():Response{
        try{
            $q=$this->request->query();$type=(string)($q['type']??'stock_summary');unset($q['type']);
            return Response::json(['ok'=>true,'data'=>$this->reports->run($type,$q)]);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function export():Response{
        try{
            $q=$this->request->query();$type=(string)($q['type']??'stock_summary');unset($q['type']);
            return Response::binary($this->reports->csv($type,$q),'text/csv; charset=UTF-8');
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
