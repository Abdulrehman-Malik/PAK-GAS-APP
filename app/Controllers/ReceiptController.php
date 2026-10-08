<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ReceiptService;

final class ReceiptController
{
    public function __construct(private readonly ReceiptService $service, private readonly Auth $auth, private readonly Request $request) {}

    public function index(): Response
    {
        return View::render('receipts/index',[
            'pageTitle'=>'Receipts','user'=>$this->auth->user(),'today'=>date('Y-m-d'),'_base_path'=>base_path()
        ]);
    }

    public function data(): Response
    {
        $q=$this->request->query();
        $where=['1=1'];$params=[];
        if(($q['from']??'')!==''){$where[]='r.receipt_date>=:from';$params['from']=$q['from'];}
        if(($q['to']??'')!==''){$where[]='r.receipt_date<=:to';$params['to']=$q['to'];}
        if((int)($q['party_id']??0)>0){$where[]='r.party_id=:party';$params['party']=(int)$q['party_id'];}
        if(($q['status']??'')!==''){$where[]='r.status=:status';$params['status']=$q['status'];}
        if(($q['search']??'')!==''){$where[]='(r.doc_no LIKE :search OR p.code LIKE :search OR p.name LIKE :search)';$params['search']='%'.$q['search'].'%';}
        $limit=min(100,max(10,(int)($q['limit']??50)));$offset=max(0,(int)($q['offset']??0));$params['limit']=$limit;$params['offset']=$offset;
        $base='FROM receipts r INNER JOIN parties p ON p.id=r.party_id LEFT JOIN users u ON u.id=r.created_by WHERE '.implode(' AND ',$where);
        $total=(int)($this->service->dbCount($base,$params));
        return Response::json(['ok'=>true,'data'=>['rows'=>$this->service->list($where,$params,$limit,$offset),'total'=>$total]]);
    }

    public function store(): Response
    {
        try{
            $result=$this->service->post($this->request->input(),(int)$this->auth->user()['id']);
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Receipt posted.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function void(): Response
    {
        try{
            $d=$this->request->input();
            $this->service->void((int)($d['id']??0),(int)$this->auth->user()['id'],(string)($d['reason']??''));
            return Response::json(['ok'=>true,'message'=>'Receipt voided.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function customers(): Response
    {
        $q=trim((string)($this->request->query()['q']??''));
        $rows=$this->service->customers($q);
        return Response::json(['ok'=>true,'data'=>$rows]);
    }
}
