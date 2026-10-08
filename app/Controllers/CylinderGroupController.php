<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CylinderGroupRepository;
use App\Services\AuditService;
use App\Services\CodeGenerator;

final class CylinderGroupController
{
    public function __construct(
        private readonly CylinderGroupRepository $repo,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator,
        private readonly AuditService $audit,
        private readonly CodeGenerator $codes
    ) {}

    public function index(): Response
    {
        return View::render('cylinder-groups/index', [
            'pageTitle'=>'Cylinder Groups',
            'user'=>$this->auth->user(),
            '_base_path'=>base_path(),
        ]);
    }

    public function data(): Response
    {
        $i=$this->request->input();
        return Response::json(['ok'=>true,'data'=>$this->repo->paginate(trim((string)($i['search']??'')),min(100,max(10,(int)($i['limit']??25))),max(0,(int)($i['offset']??0)))]);
    }

    public function store(): Response
    {
        $d=$this->request->input();
        $e=$this->validator->validate($d,['name'=>['required','max:150'],'capacity_kg'=>['required'],'cylinder_price'=>['required']]);
        if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);
        $capacity=(string)$d['capacity_kg'];
        if(bccomp($capacity,'0.000',3)<=0)return Response::json(['ok'=>false,'message'=>'Capacity must be greater than zero.'],422);

        $mode=strtoupper((string)($d['code_mode']??'MANUAL'));
        if($mode==='AUTO'){$d['code']=$this->codes->nextGroupCode();}else{$d['code']=strtoupper(trim((string)($d['code']??'')));if($d['code']==='')return Response::json(['ok'=>false,'message'=>'Code is required in manual mode.'],422);}
        $u=$this->auth->user();$d['uid']=(int)$u['id'];$d['notes']=$d['notes']??null;

        try{$id=$this->repo->create($d);$this->audit->record((int)$u['id'],'CREATE','cylinder_groups',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id,'code'=>$d['code']],'message'=>'Cylinder group saved.']);}
        catch(\Throwable $x){return Response::json(['ok'=>false,'message'=>'Code already exists or data is invalid.'],422);}
    }

    public function delete(int $id): Response
    {
        $u=$this->auth->user();
        try{
            $count=$this->repo->childCount($id);
            if($count>0)throw new \InvalidArgumentException('Cannot delete this group because it is used by '.$count.' related record(s). Deactivate it instead.');
            $this->repo->delete($id);
            $this->audit->record((int)$u['id'],'DELETE','cylinder_groups',$id,null,null,$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Cylinder group deleted.']);
        }catch(\Throwable $x){return Response::json(['ok'=>false,'message'=>$x->getMessage()],422);}
    }
}