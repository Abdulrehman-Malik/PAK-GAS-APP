<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\CounterService;

final class CounterController
{
    public function __construct(private readonly CounterService $service,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('counter/index',['pageTitle'=>'Cash Counter','counters'=>$this->service->active(),'user'=>$this->auth->user()]);}
    public function create():Response{try{$d=$this->request->input();$id=$this->service->create((string)$d['name'],(string)($d['opening_cash']??'0.00'),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Counter created.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function edit():Response{try{$d=$this->request->input();$this->service->update((int)$d['id'],(string)$d['name'],(string)($d['opening_cash']??'0.00'),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Counter updated.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function deactivate():Response{try{$this->service->deactivate((int)$this->request->input()['id'],(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Counter deactivated.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function delete():Response{try{$this->service->delete((int)$this->request->input()['id'],(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Counter deleted.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}

    public function open():Response{try{$d=$this->request->input();$u=$this->auth->user();$id=$this->service->open((int)$d['counter_id'],(string)($d['opening_cash']??'0'),(int)$u['id']);return Response::json(['ok'=>true,'id'=>$id,'message'=>'Counter opened.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function close():Response{try{$d=$this->request->input();$u=$this->auth->user();$r=$this->service->close((int)$d['counter_id'],(string)$d['counted_cash'],(int)$u['id']);return Response::json(['ok'=>true,'data'=>$r,'message'=>'Counter closed.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function status():Response{try{$id=(int)($this->request->query()['counter_id']??0);return Response::json(['ok'=>true,'data'=>$this->service->current($id)]);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function manual():Response{try{$d=$this->request->input();$id=$this->service->manualEntry((int)$d['counter_id'],strtoupper((string)$d['direction']),(string)$d['amount'],(string)($d['reason']??''),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Cash adjustment posted.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function transfer():Response{try{$d=$this->request->input();$id=$this->service->transfer((int)$d['from_counter_id'],(int)$d['to_counter_id'],(string)$d['amount'],(string)($d['transfer_date']??date('Y-m-d')),trim((string)($d['notes']??'')),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Cash transferred.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
}
