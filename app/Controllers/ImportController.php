<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\ImportService;

final class ImportController
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly Auth $auth,
        private readonly Request $request
    ) {
    }

    public function template(): Response
    {
        $type=(string)($this->request->query()['type']??'');
        $path=$this->imports->template($type);
        $body=(string)file_get_contents($path);
        @unlink($path);

        $name=$type==='parties'?'parties-template.xlsx':'opening-stock-template.xlsx';
        header('Content-Disposition: attachment; filename="'.$name.'"');
        return Response::binary($body,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function preview(): Response
    {
        try{
            $input=$this->request->input();
            $type=(string)($input['type']??'');
            if(!in_array($type,['parties','opening_stock'],true)){
                throw new \InvalidArgumentException('Unknown import type.');
            }

            $file=$this->request->file('file');
            if($file===null)throw new \InvalidArgumentException('Please select an XLSX file.');

            $path=$this->imports->saveUpload($file,$type);
            $result=$type==='parties'
                ?$this->imports->previewParties($path)
                :$this->imports->previewOpening($path);

            if(isset($_SESSION['import_previews'])&&!is_array($_SESSION['import_previews'])){
                $_SESSION['import_previews']=[];
            }
            if(!isset($_SESSION['import_previews']))$_SESSION['import_previews']=[];

            $token=bin2hex(random_bytes(20));
            $_SESSION['import_previews'][$token]=[
                'type'=>$type,
                'path'=>$path,
                'rows'=>$result['rows'],
                'rows_total'=>$result['rows_total'],
                'rows_ok'=>$result['rows_ok'],
                'rows_error'=>$result['rows_error'],
                'created_at'=>time()
            ];

            foreach($_SESSION['import_previews'] as $key=>$preview){
                if((int)($preview['created_at']??0)<time()-1800)unset($_SESSION['import_previews'][$key]);
            }

            return Response::json([
                'ok'=>true,
                'data'=>[
                    'token'=>$token,
                    'rows'=>$result['rows'],
                    'errors'=>$result['errors'],
                    'rows_total'=>$result['rows_total'],
                    'rows_ok'=>$result['rows_ok'],
                    'rows_error'=>$result['rows_error']
                ]
            ]);
        }catch(\Throwable $e){
            return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);
        }
    }

    public function commit(): Response
    {
        try{
            $input=$this->request->input();
            $token=(string)($input['token']??'');
            if($token===''||!isset($_SESSION['import_previews'][$token])){
                throw new \InvalidArgumentException('Import preview has expired. Please preview the file again.');
            }

            $preview=$_SESSION['import_previews'][$token];
            if((int)$preview['created_at']<time()-1800){
                unset($_SESSION['import_previews'][$token]);
                throw new \InvalidArgumentException('Import preview has expired. Please preview the file again.');
            }
            if((int)$preview['rows_error']>0)throw new \InvalidArgumentException('Fix all preview errors before committing the import.');

            $type=(string)$preview['type'];
            $rows=$preview['rows'];
            $result=$type==='parties'
                ?$this->imports->commitParties($rows,(int)$this->auth->user()['id'])
                :$this->imports->commitOpening($rows,(int)$this->auth->user()['id']);

            @unlink((string)$preview['path']);
            unset($_SESSION['import_previews'][$token]);

            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Import committed successfully.']);
        }catch(\Throwable $e){
            return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);
        }
    }
}
