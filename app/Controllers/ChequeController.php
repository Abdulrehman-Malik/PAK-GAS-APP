<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ChequeService;

final class ChequeController
{
    public function __construct(private readonly ChequeService $service,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('cheques/index',['pageTitle'=>'Cheque Register','user'=>$this->auth->user(),'today'=>date('Y-m-d'),'_base_path'=>base_path()]);}
    public function data():Response{
        $q=$this->request->query();$r=$this->service->list((string)($q['status']??''),(string)($q['direction']??''),min(100,max(10,(int)($q['limit']??50))),max(0,(int)($q['offset']??0)));
        return Response::json(['ok'=>true,'data'=>$r]);
    }
    public function clear():Response{
        try{$d=$this->request->input();$this->service->clear((int)$d['id'],(string)($d['date']??date('Y-m-d')),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Cheque cleared.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function bounce():Response{
        try{$d=$this->request->input();$this->service->bounce((int)$d['id'],(string)($d['reason']??''),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Cheque bounced.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
