<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ImportService
{
    public function __construct(
        private readonly DB $db,
        private readonly XlsxService $xlsx,
        private readonly StockService $stock,
        private readonly AuditService $audit
    ) {
    }

    public function saveUpload(array $file,string $type,int $userId):string
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new \InvalidArgumentException('File upload failed.');
        $name=(string)($file['name']??'');
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='xlsx')throw new \InvalidArgumentException('Only XLSX files are supported.');
        $tmp=(string)($file['tmp_name']??'');
        if(!is_uploaded_file($tmp)&&PHP_SAPI!=='cli')throw new \InvalidArgumentException('Invalid uploaded file.');

        $targetDir=base_path('storage/imports');
        if(!is_dir($targetDir)&&!mkdir($targetDir,0775,true)&&!is_dir($targetDir))throw new \RuntimeException('Unable to create import storage.');
        $target=$targetDir.'/'.bin2hex(random_bytes(16)).'.xlsx';
        if(!move_uploaded_file($tmp,$target))throw new \RuntimeException('Unable to store uploaded file.');

        return $target;
    }

    public function previewOpening(string $path,int $userId):array
    {
        $rows=$this->xlsx->read($path);
        if($rows===[])throw new \InvalidArgumentException('The worksheet is empty.');
        $headers=array_map([$this,'normalize'],array_shift($rows));
        $required=['batch_date','group_code','group_name','capacity_kg','quantity','actual_gas','location','customer_code','condition_code','code_mode','codes'];
        foreach($required as $header)if(!in_array($header,$headers,true))throw new \InvalidArgumentException('Missing required column: '.$header);

        $result=[];
        $errors=[];
        foreach($rows as $index=>$raw){
            if(count(array_filter($raw,static fn($v)=>(string)$v!==''))===0)continue;
            $row=[];
            foreach($headers as $i=>$header)$row[$header]=trim((string)($raw[$i]??''));
            $rowNumber=$index+2;
            try{
                $result[]=$this->normalizeOpeningRow($row,$rowNumber);
            }catch(\Throwable $e){
                $errors[]=['row'=>$rowNumber,'message'=>$e->getMessage(),'data'=>$row];
            }
        }

        return ['rows'=>$result,'errors'=>$errors,'rows_total'=>count($result)+count($errors),'rows_ok'=>count($result),'rows_error'=>count($errors)];
    }

    public function commitOpening(array $rows,int $userId):array
    {
        if($rows===[])throw new \InvalidArgumentException('No valid opening-stock rows to commit.');

        return $this->db->transaction(function()use($rows,$userId):array{
            $dates=array_values(array_unique(array_map(static fn(array $row):string=>(string)$row['batch_date'],$rows)));
            if(count($dates)!==1)throw new \InvalidArgumentException('All rows in one opening-stock import must use the same batch date.');

            $prepared=[];
            foreach($rows as $row){
                $groupCode=strtoupper(trim((string)$row['group_code']));
                $group=$this->db->fetchOne(
                    'SELECT id,code,capacity_kg FROM cylinder_groups WHERE UPPER(code)=:code AND active=1 FOR UPDATE',
                    ['code'=>$groupCode]
                );
                if(!$group){
                    $this->db->execute(
                        "INSERT INTO cylinder_groups(code,name,capacity_kg,cylinder_price,active,notes,created_by,updated_by)
                         VALUES(:code,:name,:capacity,0.00,1,'Created by opening-stock import',:user,:user)",
                        [
                            'code'=>$groupCode,'name'=>$row['group_name'],'capacity'=>$row['capacity_kg'],'user'=>$userId
                        ]
                    );
                    $group=$this->db->fetchOne(
                        'SELECT id,code,capacity_kg FROM cylinder_groups WHERE code=:code FOR UPDATE',
                        ['code'=>$groupCode]
                    );
                }
                if(!$group||bccomp((string)$group['capacity_kg'],(string)$row['capacity_kg'],3)!==0){
                    throw new \InvalidArgumentException('Group '.$groupCode.' capacity does not match imported capacity.');
                }

                $customerId=null;
                if(strtoupper((string)$row['location'])==='ISSUED'){
                    $customer=$this->db->fetchOne(
                        "SELECT id FROM parties WHERE party_type='CUSTOMER' AND code=:code AND active=1",
                        ['code'=>strtoupper(trim((string)$row['customer_code']))]
                    );
                    if(!$customer)throw new \InvalidArgumentException('Customer '.$row['customer_code'].' not found.');
                    $customerId=(int)$customer['id'];
                }

                $codes=preg_split('/[\s,]+/',trim((string)$row['codes']),-1,PREG_SPLIT_NO_EMPTY)?:[];

                $prepared[]=[
                    'batch_date'=>(string)$row['batch_date'],
                    'group_id'=>(int)$group['id'],
                    'actual_gas'=>(string)$row['actual_gas'],
                    'location'=>strtoupper((string)$row['location'])==='ISSUED'?'ISSUED':'SHOP',
                    'customer_id'=>$customerId,
                    'condition_code'=>strtoupper((string)$row['condition_code']),
                    'quantity'=>(int)$row['quantity'],
                    'code_mode'=>strtoupper((string)$row['code_mode']),
                    'codes'=>$codes
                ];
            }

            $batchId=$this->stock->createOpeningBatch($prepared,$userId,'IMPORT','Opening stock Excel import');
            $importId=$this->createImportRecord('OPENING_STOCK','opening-stock-import.xlsx',count($rows),count($rows),0,'COMMITTED',$userId);
            $this->audit->record($userId,'IMPORT','stock_batches',$batchId,null,['import_id'=>$importId,'rows'=>count($rows)],null);

            return ['batch_id'=>$batchId,'rows'=>count($rows),'import_id'=>$importId];
        });
    }

    private function createImportRecord(string $type,string $filename,int $total,int $ok,int $error,string $status,int $userId):int
    {
        $this->db->execute(
            'INSERT INTO import_batches(import_type,filename,rows_total,rows_ok,rows_error,status,created_by)
             VALUES(:type,:filename,:total,:ok,:error,:status,:user)',
            ['type'=>$type,'filename'=>$filename,'total'=>$total,'ok'=>$ok,'error'=>$error,'status'=>$status,'user'=>$userId]
        );
        return (int)$this->db->pdo()->lastInsertId();
    }

    public function previewParties(string $path):array
    {
        $rows=$this->xlsx->read($path);
        if($rows===[])throw new \InvalidArgumentException('The worksheet is empty.');
        $headers=array_map([$this,'normalize'],array_shift($rows));
        $required=['code','party_type','name','phone','address','allow_credit','credit_limit','opening_balance'];
        foreach($required as $header)if(!in_array($header,$headers,true))throw new \InvalidArgumentException('Missing required column: '.$header);

        $valid=[];$errors=[];$seen=[];
        foreach($rows as $index=>$raw){
            if(count(array_filter($raw,static fn($v)=>(string)$v!==''))===0)continue;
            $row=[];foreach($headers as $i=>$header)$row[$header]=trim((string)($raw[$i]??''));
            try{
                $code=strtoupper($row['code']);
                if($code===''||$row['name']==='')throw new \InvalidArgumentException('Code and name are required.');
                $type=strtoupper($row['party_type']);
                if(!in_array($type,['CUSTOMER','SUPPLIER','REFEREE'],true))throw new \InvalidArgumentException('Invalid party type.');
                if(isset($seen[$code]))throw new \InvalidArgumentException('Duplicate code in import.');
                $seen[$code]=true;
                if($this->db->fetchOne('SELECT id FROM parties WHERE UPPER(code)=:code',['code'=>$code]))throw new \InvalidArgumentException('Party code already exists.');
                $valid[]=[
                    'code'=>$code,'party_type'=>$type,'name'=>$row['name'],'phone'=>$row['phone']?:null,
                    'address'=>$row['address']?:null,'allow_credit'=>in_array($row['allow_credit'],['1','true','yes','YES'],true)?1:0,
                    'credit_limit'=>$row['credit_limit']===''?'0.00':$row['credit_limit'],
                    'opening_balance'=>$row['opening_balance']===''?'0.00':$row['opening_balance'],
                    'notes'=>null
                ];
            }catch(\Throwable $e){$errors[]=['row'=>$index+2,'message'=>$e->getMessage(),'data'=>$row];}
        }
        return ['rows'=>$valid,'errors'=>$errors,'rows_total'=>count($valid)+count($errors),'rows_ok'=>count($valid),'rows_error'=>count($errors)];
    }

    public function commitParties(array $rows,int $userId):array
    {
        if($rows===[])throw new \InvalidArgumentException('No valid parties to import.');
        return $this->db->transaction(function()use($rows,$userId):array{
            foreach($rows as $row){
                $this->db->execute(
                    'INSERT INTO parties(code,party_type,name,phone,address,allow_credit,credit_limit,opening_balance,active,notes,created_by,updated_by)
                     VALUES(:code,:type,:name,:phone,:address,:allow,:limit,:opening,1,:notes,:user,:user)',
                    [
                        'code'=>$row['code'],'type'=>$row['party_type'],'name'=>$row['name'],'phone'=>$row['phone'],
                        'address'=>$row['address'],'allow'=>$row['allow_credit'],'limit'=>$row['credit_limit'],
                        'opening'=>$row['opening_balance'],'notes'=>$row['notes'],'user'=>$userId
                    ]
                );
                $id=(int)$this->db->pdo()->lastInsertId();
                $this->audit->record($userId,'IMPORT','parties',$id,null,$row,null);
            }
            return ['rows'=>count($rows)];
        });
    }

    public function template(string $type):string
    {
        if($type==='opening_stock'){
            $rows=[
                ['batch_date','group_code','group_name','capacity_kg','quantity','actual_gas','location','customer_code','condition_code','code_mode','codes'],
                [date('Y-m-d'),'C','15 KG',15,3,15,'SHOP','','GOOD','AUTO','']
            ];
        }elseif($type==='parties'){
            $rows=[['code','party_type','name','phone','address','allow_credit','credit_limit','opening_balance'],['CUST001','CUSTOMER','Example Customer','','',1,'5000.00','0.00']];
        }else{
            throw new \InvalidArgumentException('Unknown import template.');
        }

        $path=base_path('storage/imports/'.bin2hex(random_bytes(12)).'-'.$type.'-template.xlsx');
        $this->xlsx->write($rows,$path,ucwords(str_replace('_',' ',$type)));
        return $path;
    }

    private function normalizeOpeningRow(array $row,int $rowNumber):array
    {
        if($row['batch_date']===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$row['batch_date']))throw new \InvalidArgumentException('Invalid batch date.');
        $row['group_code']=strtoupper($row['group_code']);
        if($row['group_code']===''||$row['group_name']===''||$row['capacity_kg']==='')throw new \InvalidArgumentException('Group code, name and capacity are required.');
        if(!is_numeric($row['capacity_kg'])||bccomp($row['capacity_kg'],'0',3)<=0)throw new \InvalidArgumentException('Capacity must be positive.');
        $row['quantity']=(int)$row['quantity'];if($row['quantity']<1||$row['quantity']>1000)throw new \InvalidArgumentException('Quantity must be between 1 and 1000.');
        if(!in_array(strtoupper($row['location']),['SHOP','ISSUED'],true))throw new \InvalidArgumentException('Location must be SHOP or ISSUED.');
        if(strtoupper($row['location'])==='ISSUED'&&trim($row['customer_code'])==='')throw new \InvalidArgumentException('Customer code is required for ISSUED.');
        $row['condition_code']=strtoupper($row['condition_code']?:'GOOD');if(!in_array($row['condition_code'],['GOOD','DAMAGED'],true))throw new \InvalidArgumentException('Invalid condition.');
        $row['code_mode']=strtoupper($row['code_mode']?:'AUTO');if(!in_array($row['code_mode'],['AUTO','MANUAL'],true))throw new \InvalidArgumentException('Invalid code mode.');
        if(!is_numeric($row['actual_gas'])||bccomp($row['actual_gas'],'0',3)<0||bccomp($row['actual_gas'],$row['capacity_kg'],3)>0)throw new \InvalidArgumentException('Actual gas is outside capacity.');
        if($row['code_mode']==='MANUAL'){
            $codes=preg_split('/[\s,]+/',$row['codes'],-1,PREG_SPLIT_NO_EMPTY)?:[];
            if(count($codes)!==$row['quantity'])throw new \InvalidArgumentException('Manual mode requires one code per cylinder.');
            if(count(array_unique(array_map('strtoupper',$codes)))!==$row['quantity'])throw new \InvalidArgumentException('Manual codes must be unique.');
            $row['codes']=implode(',',array_map('strtoupper',$codes));
        }
        return $row;
    }

    private function normalize(string $value):string
    {
        $value=strtolower(trim($value));
        return preg_replace('/[^a-z0-9]+/','_',$value)??$value;
    }
}
