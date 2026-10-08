<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\AuditService;
use App\Services\ImportService;
use App\Services\XlsxService;
use App\Services\StockService;
use App\Repositories\StockBatchRepository;

final class OpeningStockController
{
    public function __construct(
        private readonly StockBatchRepository $repo,
        private readonly StockService $stock,
        private readonly DB $db,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator,
        private readonly AuditService $audit,
        private readonly ImportService $import,
        private readonly XlsxService $xlsx
    ) {}

    public function index():Response{
        return View::render('opening-stock/index',[
            'pageTitle'=>'Opening Stock',
            'groups'=>$this->db->fetchAll('SELECT id,code,name,capacity_kg FROM cylinder_groups WHERE active=1 ORDER BY code'),
            'customers'=>$this->db->fetchAll("SELECT id,code,name FROM parties WHERE party_type='CUSTOMER' AND active=1 ORDER BY name"),
            'user'=>$this->auth->user(),
            '_base_path'=>base_path()
        ]);
    }
    private function dbGroups():array{return $this->stockDb()->fetchAll('SELECT id,code,name,capacity_kg FROM cylinder_groups WHERE active=1 ORDER BY code');}
    private function dbCustomers():array{return $this->stockDb()->fetchAll("SELECT id,code,name FROM parties WHERE party_type='CUSTOMER' AND active=1 ORDER BY name");}
    private function stockDb():\App\Core\DB{return $GLOBALS['db'];}

    public function template():Response{
        $path=base_path('storage/imports/opening_stock_template.xlsx');
        if(!is_dir(dirname($path)))mkdir(dirname($path),0775,true);
        $this->xlsx->write($path,
            ['group_code','group_name','capacity','cylinder_code','quantity','actual_gas','location','customer_code','condition','date','code_mode'],
            [
                ['group_code'=>'C','group_name'=>'15 KG Cylinder','capacity'=>'15.000','cylinder_code'=>'','quantity'=>'3','actual_gas'=>'15.000','location'=>'SHOP','customer_code'=>'','condition'=>'GOOD','date'=>date('Y-m-d'),'code_mode'=>'AUTO']
            ]
        );
        return Response::binary((string)file_get_contents($path),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','opening_stock_template.xlsx');
    }

    public function importPreview():Response{
        try{
            $file=$this->request->files()['file']??null;
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('Excel file is required.');
            $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
            if($ext!=='xlsx')throw new \InvalidArgumentException('Only .xlsx files are supported.');
            $preview = $this->import->openingPreview((string) $file['tmp_name']);
            $token = $this->import->createPreview(
                (int) $this->auth->user()['id'],
                (string) $file['name'],
                $preview
            );
            return Response::json([
                'ok' => true,
                'data' => [
                    'rows_total' => $preview['rows_total'],
                    'valid' => $preview['valid'],
                    'errors' => $preview['errors'],
                    'token' => $token,
                ],
            ]);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function importCommit():Response{
        try{
            $d=$this->request->input();
            $token = trim((string) ($d['token'] ?? ''));
            if ($token === '') {
                throw new \InvalidArgumentException('Import preview token is required.');
            }
            $result = $this->import->openingCommitPreview($token, (int) $this->auth->user()['id']);
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Excel opening-stock import committed.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function data():Response{$q=$this->request->query();return Response::json(['ok'=>true,'data'=>$this->repo->paginate((string)($q['from']??''),(string)($q['to']??''),min(100,max(10,(int)($q['limit']??25))),max(0,(int)($q['offset']??0)))]);}
    public function void():Response{
      $d=$this->request->input();$id=(int)($d['id']??0);$reason=trim((string)($d['reason']??''));
      if($id<1||$reason==='')return Response::json(['ok'=>false,'message'=>'Batch ID and void reason are required.'],422);
      try{$u=$this->auth->user();$this->stock->voidOpeningBatch($id,(int)$u['id'],$reason);$this->audit->record((int)$u['id'],'VOID','stock_batches',$id,null,['reason'=>$reason],$this->request->ip());return Response::json(['ok'=>true,'message'=>'Opening stock batch voided.']);}
      catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function store():Response{
      $d=$this->request->input();$e=$this->validator->validate($d,['batch_date'=>['required'],'group_id'=>['required'],'actual_gas'=>['required'],'location'=>['required'],'condition_code'=>['required'],'quantity'=>['required']]);if($e)return Response::json(['ok'=>false,'message'=>'Validation failed','errors'=>$e],422);
      $u=$this->auth->user();$codes=[];$raw=trim((string)($d['codes']??''));if($raw!=='')$codes=preg_split('/[\s,]+/',$raw,-1,PREG_SPLIT_NO_EMPTY)?:[];
      try{$id=$this->stock->createOpeningBatch([['batch_date'=>$d['batch_date'],'group_id'=>(int)$d['group_id'],'actual_gas'=>(string)$d['actual_gas'],'location'=>(string)$d['location'],'customer_id'=>($d['customer_id']??'')!==''?(int)$d['customer_id']:null,'condition_code'=>(string)$d['condition_code'],'quantity'=>(int)$d['quantity'],'code_mode'=>(string)($d['code_mode']??'AUTO'),'codes'=>$codes]],(int)$u['id']);$this->audit->record((int)$u['id'],'CREATE','stock_batches',$id,null,$d,$this->request->ip());return Response::json(['ok'=>true,'data'=>['id'=>$id],'message'=>'Opening stock posted.']);}catch(\Throwable $x){return Response::json(['ok'=>false,'message'=>$x->getMessage()],422);}
    }
}
