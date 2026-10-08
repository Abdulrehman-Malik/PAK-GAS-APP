<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\PartyRepository;
use App\Services\AuditService;
use App\Services\CodeGenerator;
use App\Services\MasterProtectionService;

final class PartyController
{
    public function __construct(
        private readonly PartyRepository $repo,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator,
        private readonly AuditService $audit,
        private readonly MasterProtectionService $protection
    ) {
    }

    public function index():Response
    {
        return View::render('parties/index',['pageTitle'=>'Parties','user'=>$this->auth->user(),'_base_path'=>base_path()]);
    }

    public function data():Response
    {
        $i=$this->request->input();
        $r=$this->repo->paginate(
            (string)($i['type']??'CUSTOMER'),
            trim((string)($i['search']??'')),
            min(100,max(10,(int)($i['limit']??25))),
            max(0,(int)($i['offset']??0))
        );
        return Response::json(['ok'=>true,'data'=>$r]);
    }

    public function store():Response
    {
        $d=$this->request->input();
        $e=$this->validator->validate($d,[
            'code'=>['required','max:50'],
            'party_type'=>['required'],
            'name'=>['required','max:150'],
            'credit_limit'=>['required'],
            'opening_balance'=>['required']
        ]);
        if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);

        try{
            $type=strtoupper((string)$d['party_type']);
            if(!in_array($type,['CUSTOMER','SUPPLIER','REFEREE'],true))throw new \InvalidArgumentException('Invalid party type.');
            $allow=(int)($d['allow_credit']??0);
            $limit=$allow?(string)$d['credit_limit']:'0.00';
            $u=$this->auth->user();
            $data=[
                'code'=>strtoupper(trim((string)$d['code'])),
                'party_type'=>$type,
                'name'=>trim((string)$d['name']),
                'phone'=>$d['phone']??null,
                'address'=>$d['address']??null,
                'allow_credit'=>$allow,
                'credit_limit'=>$limit,
                'opening_balance'=>(string)$d['opening_balance'],
                'notes'=>$d['notes']??null,
                'uid'=>(int)$u['id']
            ];
            if(bccomp($data['credit_limit'],'0.00',2)<0)throw new \InvalidArgumentException('Credit limit cannot be negative.');
            $id=$this->repo->create($data);
            $this->audit->record((int)$u['id'],'CREATE','parties',$id,null,$data,$this->request->ip());
            return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Party saved.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function update():Response
    {
        try{
            $d=$this->request->input();
            $id=(int)($d['id']??0);
            $row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Party not found.');

            if($row['party_type']!==strtoupper((string)($d['party_type']??$row['party_type']))){
                throw new \InvalidArgumentException('Party type cannot be changed after creation.');
            }

            $allow=(int)($d['allow_credit']??0);
            $data=[
                'name'=>trim((string)($d['name']??'')),
                'phone'=>$d['phone']??null,
                'address'=>$d['address']??null,
                'allow_credit'=>$allow,
                'credit_limit'=>$allow?(string)($d['credit_limit']??'0.00'):'0.00',
                'notes'=>$d['notes']??null,
                'active'=>(int)($d['active']??1),
                'uid'=>(int)$this->auth->user()['id']
            ];
            if($data['name']==='')throw new \InvalidArgumentException('Party name is required.');
            if(bccomp($data['credit_limit'],'0.00',2)<0)throw new \InvalidArgumentException('Credit limit cannot be negative.');

            $this->repo->update($id,$data);
            $this->audit->record((int)$this->auth->user()['id'],'UPDATE','parties',$id,$row,$data,$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Party updated.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function delete():Response
    {
        try{
            $id=(int)($this->request->input()['id']??0);
            $row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Party not found.');
            $this->protection->assertDeleteAllowed('Party '.$row['code'],$this->protection->partyUsage($id));
            $this->repo->delete($id);
            $this->audit->record((int)$this->auth->user()['id'],'DELETE','parties',$id,$row,null,$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Party deleted.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function deactivate():Response
    {
        try{
            $id=(int)($this->request->input()['id']??0);
            $row=$this->repo->find($id);
            if(!$row)throw new \InvalidArgumentException('Party not found.');
            $this->repo->setActive($id,0,(int)$this->auth->user()['id']);
            $this->audit->record((int)$this->auth->user()['id'],'DEACTIVATE','parties',$id,$row,['active'=>0],$this->request->ip());
            return Response::json(['ok'=>true,'message'=>'Party deactivated.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
