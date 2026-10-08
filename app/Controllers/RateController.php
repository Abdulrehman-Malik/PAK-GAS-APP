<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth; use App\Core\Request; use App\Core\Response; use App\Core\Validator; use App\Core\View; use App\Repositories\RateRepository; use App\Services\AuditService;
final class RateController {
 public function __construct(private readonly RateRepository $repo, private readonly Auth $auth, private readonly Request $request, private readonly Validator $validator, private readonly AuditService $audit) {}
 public function index(): Response { return View::render('rates/index',['pageTitle'=>'Rates','user'=>$this->auth->user(),'_base_path'=>base_path()]); }
 public function data(): Response { $i=$this->request->input(); return Response::json(['ok'=>true,'data'=>$this->repo->paginate((int)($i['group_id']??0),(string)($i['effective_date']??''),min(100,max(10,(int)($i['limit']??25))),max(0,(int)($i['offset']??0)))]); }
 public function store(): Response { $d=$this->request->input();$e=$this->validator->validate($d,['group_id'=>['required'],'effective_date'=>['required'],'gas_rate'=>['required'],'cylinder_price'=>['required']]);if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);$u=$this->auth->user();$d['uid']=(int)$u['id'];$d['notes']=$d['notes']??null;try{$id=$this->repo->create($d);$this->audit->record((int)$u['id'],'CREATE','rates',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Rate saved']);}catch(\Throwable){return Response::json(['ok'=>false,'message'=>'A rate already exists for this group and effective date.'],422);}}
}