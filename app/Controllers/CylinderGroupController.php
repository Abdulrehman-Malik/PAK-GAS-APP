<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth; use App\Core\Request; use App\Core\Response; use App\Core\Validator; use App\Core\View; use App\Repositories\CylinderGroupRepository; use App\Services\AuditService; use App\Services\MasterProtectionService;
final class CylinderGroupController {
 public function __construct(private readonly CylinderGroupRepository $repo, private readonly Auth $auth, private readonly Request $request, private readonly Validator $validator, private readonly AuditService $audit, private readonly MasterProtectionService $protection) {}
 public function index(): Response { return View::render('cylinder-groups/index',['pageTitle'=>'Cylinder Groups','user'=>$this->auth->user(),'_base_path'=>base_path()]); }
 public function data(): Response { $i=$this->request->input(); return Response::json(['ok'=>true,'data'=>$this->repo->paginate(trim((string)($i['search']??'')),min(100,max(10,(int)($i['limit']??25))),max(0,(int)($i['offset']??0)))]); }
 public function update(): Response
 {
  try{
   $d=$this->request->input();$id=(int)($d['id']??0);$row=$this->repo->find($id);
   if(!$row)throw new \InvalidArgumentException('Cylinder group not found.');
   $usage=$this->protection->groupUsage($id);
   if((int)($d['capacity_kg']!==(string)$row['capacity_kg'])>0 && ($usage['cylinders']??0)>0){
      throw new \InvalidArgumentException('Capacity cannot be changed after cylinders have been created for this group.');
   }
   $data=['name'=>trim((string)($d['name']??'')),'capacity_kg'=>(string)($d['capacity_kg']??$row['capacity_kg']),'cylinder_price'=>(string)($d['cylinder_price']??$row['cylinder_price']),'active'=>(int)($d['active']??1),'notes'=>$d['notes']??null,'uid'=>(int)$this->auth->user()['id']];
   if($data['name']==='')throw new \InvalidArgumentException('Group name is required.');
   if(bccomp($data['capacity_kg'],'0',3)<=0||bccomp($data['cylinder_price'],'0',2)<0)throw new \InvalidArgumentException('Capacity/price values are invalid.');
   $this->repo->update($id,$data);
   $this->audit->record((int)$this->auth->user()['id'],'UPDATE','cylinder_groups',$id,$row,$data,$this->request->ip());
   return Response::json(['ok'=>true,'message'=>'Cylinder group updated.']);
  }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
 }
 public function delete(): Response { try{$id=(int)($this->request->input()['id']??0);$row=$this->repo->find($id);if(!$row)throw new \InvalidArgumentException('Cylinder group not found.');$this->protection->assertDeleteAllowed('Cylinder group '.$row['code'],$this->protection->groupUsage($id));$this->repo->delete($id);$u=$this->auth->user();$this->audit->record((int)$u['id'],'DELETE','cylinder_groups',$id,$row,null,$this->request->ip());return Response::json(['ok'=>true,'message'=>'Cylinder group deleted.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
 public function deactivate(): Response { try{$id=(int)($this->request->input()['id']??0);$row=$this->repo->find($id);if(!$row)throw new \InvalidArgumentException('Cylinder group not found.');$this->repo->setActive($id,0,(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Cylinder group deactivated.']);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
 public function store(): Response { $d=$this->request->input(); $e=$this->validator->validate($d,['code'=>['required','max:50'],'name'=>['required','max:150'],'capacity_kg'=>['required'],'cylinder_price'=>['required']]); if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422); $u=$this->auth->user();$d['uid']=(int)$u['id'];$d['notes']=$d['notes']??null;try{$id=$this->repo->create($d);$this->audit->record((int)$u['id'],'CREATE','cylinder_groups',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Cylinder group saved']);}catch(\Throwable){return Response::json(['ok'=>false,'message'=>'Code already exists or data is invalid.'],422);}}
}