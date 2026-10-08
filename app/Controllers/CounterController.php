<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\DB;use App\Core\Request;use App\Core\Response;use App\Services\CounterService;
final class CounterController {
 public function __construct(private readonly CounterService $service,private readonly Auth $auth,private readonly Request $request){}
 public function index():Response{return \App\Core\View::render('counter/index',['pageTitle'=>'Cash Counter','counters'=>$this->service->active()]);}
 public function open():Response{try{$d=$this->request->input();$u=$this->auth->user();$id=$this->service->open((int)$d['counter_id'],(string)($d['opening_cash']??'0'),(int)$u['id']);return Response::json(['ok'=>true,'id'=>$id,'message'=>'Counter opened.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
 public function close():Response{try{$d=$this->request->input();$u=$this->auth->user();$r=$this->service->close((int)$d['counter_id'],(string)$d['counted_cash'],(int)$u['id']);return Response::json(['ok'=>true,'data'=>$r,'message'=>'Counter closed.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
 public function status():Response{$id=(int)($this->request->query()['counter_id']??0);return Response::json(['ok'=>true,'data'=>$this->service->current($id)]);}
}