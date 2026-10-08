<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\CylinderRepository;
use App\Services\AuditService;
use App\Services\CodeGenerator;
use App\Services\MasterProtectionService;
use App\Services\StockService;

final class CylinderController
{
    public function __construct(
        private readonly CylinderRepository $repo,
        private readonly CodeGenerator $codes,
        private readonly StockService $stock,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator,
        private readonly AuditService $audit,
        private readonly MasterProtectionService $protection,
        private readonly DB $db
    ) {
    }

    public function index():Response{return View::render('cylinders/index',['pageTitle'=>'Cylinders','user'=>$this->auth->user(),'_base_path'=>base_path()]);}
    public function data():Response{
        $i=$this->request->input();
        return Response::json(['ok'=>true,'data'=>$this->repo->paginate(trim((string)($i['search']??'')),(int)($i['group_id']??0),min(100,max(10,(int)($i['limit']??25))),max(0,(int)($i['offset']??0)))]);
    }

    public function store():Response
    {
        $d=$this->request->input();
        $e=$this->validator->validate($d,['group_id'=>['required'],'gas_kg'=>['required'],'condition_code'=>['required']]);
        if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);

        $user=$this->auth->user();
        try{
            $result=$this->db->transaction(function()use($d,$user):array{
                $group=$this->repo->rawGroup((int)$d['group_id']);
                if(!$group)throw new \InvalidArgumentException('Cylinder group not found.');
                $gas=(string)$d['gas_kg'];
                if(bccomp($gas,'0.000',3)<0||bccomp($gas,(string)$group['capacity_kg'],3)>0)throw new \InvalidArgumentException('Gas must be between 0 and cylinder capacity.');
                $mode=strtoupper((string)($d['code_mode']??'AUTO'));
                $code=$this->codes->nextCylinderCode((int)$group['id'],(string)$group['code'],$mode,(string)($d['code']??''));
                $id=$this->stock->createManualCylinder((int)$group['id'],$code,$gas,strtoupper((string)$d['condition_code']),(int)$user['id']);
                $this->audit->record((int)$user['id'],'CREATE','cylinders',$id,null,['code'=>$code,'group_id'=>$group['id'],'gas_kg'=>$gas,'condition_code'=>$d['condition_code']],$this->request->ip());
                return ['id'=>$id,'code'=>$code];
            });
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Cylinder saved.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function adjust():Response
    {
        try{
            $d=$this->request->input();
            $result=$this->stock->adjustGas(
                (int)($d['id']??0),
                (string)($d['gas_kg']??'0.000'),
                (string)($d['reason']??''),
                (int)$this->auth->user()['id']
            );
            $this->audit->record((int)$this->auth->user()['id'],'ADJUST','cylinders',(int)($d['id']??0),null,$result,$this->request->ip());
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Stock adjusted.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function update():Response
    {
        try{
            $d=$this->request->input();$id=(int)($d['id']??0);$row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Cylinder not found.');
            $data=[
                'condition_code'=>strtoupper((string)($d['condition_code']??$row['condition_code'])),
                'active'=>(int)($d['active']??$row['active']),
                'notes'=>$d['notes']??null,
                'uid'=>(int)$this->auth->user()['id']
            ];
            if(!in_array($data['condition_code'],['GOOD','DAMAGED'],true))throw new \InvalidArgumentException('Invalid condition.');
            $this->repo->update($id,$data);
            $this->audit->record((int)$this->auth->user()['id'],'UPDATE','cylinders',$id,$row,$data,$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Cylinder updated.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function history():Response
    {
        return Response::json(['ok'=>true,'data'=>$this->repo->history((int)($this->request->query()['id']??0))]);
    }

    public function delete():Response
    {
        try{
            $id=(int)($this->request->input()['id']??0);$row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Cylinder not found.');
            $this->protection->assertDeleteAllowed('Cylinder '.$row['code'],$this->protection->cylinderUsage($id));
            $this->repo->delete($id);
            $this->audit->record((int)$this->auth->user()['id'],'DELETE','cylinders',$id,$row,null,$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Cylinder deleted.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function deactivate():Response
    {
        try{
            $id=(int)($this->request->input()['id']??0);$row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Cylinder not found.');
            $this->repo->setActive($id,0,(int)$this->auth->user()['id']);
            $this->audit->record((int)$this->auth->user()['id'],'DEACTIVATE','cylinders',$id,$row,['active'=>0],$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Cylinder deactivated.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
