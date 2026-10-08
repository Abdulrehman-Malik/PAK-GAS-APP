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
            $type=(string)($this->request->input()['type']??'');
            $file=$this->request->file('file');
            if($file===null)throw new \InvalidArgumentException('Please select an XLSX file.');
            $path=$this->imports->saveUpload($file,$type);
            $result=$type==='parties'
                ?$this->imports->previewParties($path)
                :$this->imports->previewOpening($path);
            $this->remember($path);
            return Response::json(['ok'=>true,'data'=>$result]);
        }catch(\Throwable $e){
            return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);
        }
    }

    public function commit(): Response
    {
        try{
            $input=$this->request->input();
            $type=(string)($input['type']??'');
            $rows=json_decode((string)($input['rows_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $result=$type==='parties'
                ?$this->imports->commitParties($rows,(int)$this->auth->user()['id'])
                :$this->imports->commitOpening($rows,(int)$this->auth->user()['id']);
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Import committed successfully.']);
        }catch(\Throwable $e){
            return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);
        }
    }

    private function remember(string $path): void
    {
        if(!isset($_SESSION['imports']))$_SESSION['imports']=[];
        array_unshift($_SESSION['imports'],['path'=>$path,'created_at'=>time()]);
        $_SESSION['imports']=array_slice($_SESSION['imports'],0,5);
    }
}
