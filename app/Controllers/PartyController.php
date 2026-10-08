<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth; use App\Core\Request; use App\Core\Response; use App\Core\Validator; use App\Core\View; use App\Repositories\PartyRepository; use App\Services\AuditService;
use App\Services\MasterProtectionService;
final class PartyController {
 public function __construct(private readonly PartyRepository $repo, private readonly Auth $auth, private readonly Request $request, private readonly Validator $validator, private readonly AuditService $audit, private readonly MasterProtectionService $protection) {}
 public function index(): Response { return View::render('parties/index',['pageTitle'=>'Parties','user'=>$this->auth->user(),'_base_path'=>base_path()]); }
 public function data(): Response { $i=$this->request->input(); $r=$this->repo->paginate((string)($i['type']??'CUSTOMER'),trim((string)($i['search']??'')),min(100,max(10,(int)($i['limit']??25))),max(0,(int)($i['offset']??0))); return Response::json(['ok'=>true,'data'=>$r]); }
 public function store(): Response { $d=$this->request->input(); $e=$this->validator->validate($d,['code'=>['required','max:50'],'party_type'=>['required'],'name'=>['required','max:150'],'credit_limit'=>['required'],'opening_balance'=>['required']]); if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422); $u=$this->auth->user(); $d['uid']=(int)$u['id']; $d['phone']=$d['phone']??null; $d['address']=$d['address']??null; $d['notes']=$d['notes']??null; $d['allow_credit']=(int)($d['allow_credit']??0); try{$id=$this->repo->create($d);$this->audit->record((int)$u['id'],'CREATE','parties',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Party saved']);}catch(\Throwable){return Response::json(['ok'=>false,'message'=>'Party code already exists or data is invalid.'],422);}}
}