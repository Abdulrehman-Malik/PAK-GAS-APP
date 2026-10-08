<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Request;use App\Core\Response;use App\Core\Validator;use App\Core\View;use App\Repositories\StockBatchRepository;use App\Services\AuditService;use App\Services\StockService;
final class OpeningStockController {
 public function __construct(private readonly StockBatchRepository $repo,private readonly StockService $stock,private readonly Auth $auth,private readonly Request $request,private readonly Validator $validator,private readonly AuditService $audit){}
 public function index():Response{return View::render('opening-stock/index',['pageTitle'=>'Opening Stock','user'=>$this->auth->user(),'_base_path'=>base_path()]);}
 public function data():Response{$q=$this->request->query();return Response::json(['ok'=>true,'data'=>$this->repo->paginate((string)($q['from']??''),(string)($q['to']??''),min(100,max(10,(int)($q['limit']??25))),max(0,(int)($q['offset']??0)))]);}
 public function void(): Response
 {
  $d=$this->request->input();
  $id=(int)($d['id']??0);
  $reason=trim((string)($d['reason']??''));
  if($id<1 || $reason==='') return Response::json(['ok'=>false,'message'=>'Batch ID and void reason are required.'],422);
  $user=$this->auth->user();
  try {
   $this->stock->voidOpeningBatch($id,(int)$user['id'],$reason);
   $this->audit->record((int)$user['id'],'VOID','stock_batches',$id,null,['reason'=>$reason],$this->request->ip());
   return Response::json(['ok'=>true,'message'=>'Opening stock batch voided']);
  } catch(\Throwable $x) {
   return Response::json(['ok'=>false,'message'=>$x->getMessage()],422);
  }
 }
 public function store():Response {
  $d=$this->request->input();$e=$this->validator->validate($d,['batch_date'=>['required'],'group_id'=>['required'],'actual_gas'=>['required'],'location'=>['required'],'condition_code'=>['required'],'quantity'=>['required']]);if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);
  $user=$this->auth->user();$codes=[];$raw=trim((string)($d['codes']??''));if($raw!=='')$codes=preg_split('/[\s,]+/',$raw,-1,PREG_SPLIT_NO_EMPTY)?:[];
  try{$id=$this->stock->createOpeningBatch([['batch_date'=>$d['batch_date'],'group_id'=>(int)$d['group_id'],'actual_gas'=>(string)$d['actual_gas'],'location'=>(string)$d['location'],'customer_id'=>($d['customer_id']??'')!==''?(int)$d['customer_id']:null,'condition_code'=>(string)$d['condition_code'],'quantity'=>(int)$d['quantity'],'code_mode'=>(string)($d['code_mode']??'AUTO'),'codes'=>$codes]],(int)$user['id']);$this->audit->record((int)$user['id'],'CREATE','stock_batches',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Opening stock posted']);}catch(\Throwable $x){return Response::json(['ok'=>false,'message'=>$x->getMessage()],422);}
 }
}