<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ImportService
{
    public function __construct(private readonly DB $db,private readonly XlsxService $xlsx,private readonly StockService $stock,private readonly AuditService $audit){}
    public function openingPreview(string $path):array{
      $rows=$this->xlsx->read($path);$errors=[];$valid=[];
      foreach($rows as $n=>$row){
        $line=$n+2;$groupCode=strtoupper(trim((string)($row['group_code']??'')));$groupName=trim((string)($row['group_name']??''));$capacity=(string)($row['capacity']??'');$gas=(string)($row['actual_gas']??'');$location=strtoupper(trim((string)($row['location']??'SHOP')));$customerCode=strtoupper(trim((string)($row['customer_code']??'')));$condition=strtoupper(trim((string)($row['condition']??'GOOD')));$date=(string)($row['date']??'');$code=strtoupper(trim((string)($row['cylinder_code']??'')));$qty=(int)($row['quantity']??0);
        $err=[];
        if($groupCode==='')$err[]='group_code is required';
        if(bccomp($capacity,'0.000',3)<=0)$err[]='capacity must be greater than zero';
        if(bccomp($gas,'0.000',3)<0||bccomp($gas,$capacity,3)>0)$err[]='actual_gas is outside capacity';
        if(!in_array($location,['SHOP','ISSUED'],true))$err[]='location must be SHOP or ISSUED';
        if($location==='ISSUED'&&$customerCode==='')$err[]='customer_code is required for ISSUED';
        if(!in_array($condition,['GOOD','DAMAGED'],true))$err[]='condition must be GOOD or DAMAGED';
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$err[]='date is invalid';
        $group=$this->db->fetchOne('SELECT id,capacity_kg FROM cylinder_groups WHERE code=:code',['code'=>$groupCode]);
        if($group&&bccomp((string)$group['capacity_kg'],$capacity,3)!==0)$err[]='capacity conflicts with existing group';
        if($location==='ISSUED'){
          $customer=$this->db->fetchOne("SELECT id FROM parties WHERE code=:code AND party_type='CUSTOMER' AND active=1",['code'=>$customerCode]);
          if(!$customer)$err[]='customer_code does not match an active customer';
        }
        $mode=strtoupper((string)($row['code_mode']??'AUTO'));if(!in_array($mode,['AUTO','MANUAL'],true))$mode='AUTO';
        if($mode==='MANUAL'&&$qty<1)$err[]='quantity is required in MANUAL mode';
        if($mode==='AUTO'&&$qty<1)$qty=1;
        if($err)$errors[]=['row'=>$line,'errors'=>$err,'data'=>$row];else $valid[]=['row'=>$line,'data'=>$row,'mode'=>$mode,'quantity'=>$qty];
      }
      return ['rows_total'=>count($rows),'valid'=>$valid,'errors'=>$errors];
    }
    public function openingCommit(array $validRows,int $userId,bool $validOnly=true):array{
      return $this->db->transaction(function()use($validRows,$userId,&$created):array{
       $created=0;$batches=[];
       foreach($validRows as $item){
        $row=$item['data'];$groupCode=strtoupper(trim((string)$row['group_code']));
        $group=$this->db->fetchOne('SELECT id,code FROM cylinder_groups WHERE code=:code FOR UPDATE',['code'=>$groupCode]);
        if(!$group){
          $this->db->execute('INSERT INTO cylinder_groups(code,name,capacity_kg,cylinder_price,active,created_by,updated_by) VALUES(:code,:name,:cap,0,1,:u,:u)',[
           'code'=>$groupCode,'name'=>trim((string)($row['group_name']??$groupCode)),'cap'=>(string)$row['capacity'],'u'=>$userId]);
          $group=$this->db->fetchOne('SELECT id,code FROM cylinder_groups WHERE code=:code FOR UPDATE',['code'=>$groupCode]);
        }
        $customer=null;if(strtoupper((string)$row['location'])==='ISSUED')$customer=$this->db->fetchOne("SELECT id FROM parties WHERE code=:code AND party_type='CUSTOMER' AND active=1",['code'=>strtoupper(trim((string)$row['customer_code']))]);
        $rows=[[
         'batch_date'=>(string)$row['date'],'group_id'=>(int)$group['id'],'actual_gas'=>(string)$row['actual_gas'],'location'=>strtoupper((string)$row['location']),
         'customer_id'=>$customer?(int)$customer['id']:null,'condition_code'=>strtoupper((string)($row['condition']??'GOOD')),
         'quantity'=>(int)$item['quantity'],'code_mode'=>(string)$item['mode'],
         'codes'=>array_values(array_filter(array_map('trim',preg_split('/[\s,]+/',(string)($row['cylinder_code']??''))?:[])))
        ]];
        $batches[]=$this->stock->createOpeningBatch($rows,$userId,'IMPORT','Excel import');
        $created+=(int)$item['quantity'];
       }
       $this->audit->record($userId,'IMPORT','opening_stock',null,null,['batches'=>$batches,'created'=>$created],null);
       return ['created'=>$created,'batches'=>$batches];
      });
    }
}
