<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Services\PosService;
use App\Services\RateService;
use DateTimeImmutable;
use DateTimeZone;

final class PosController
{
    public function __construct(
        private readonly PosService $pos,
        private readonly RateService $rates,
        private readonly DB $db,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly Validator $validator
    ) {
    }

    public function index(): Response
    {
        $config=\App\Core\Config::load(base_path());
        $today=(new DateTimeImmutable('now',new DateTimeZone($config['timezone'])))->format('Y-m-d');
        return View::render('pos/index',['pageTitle'=>'Point of Sale','user'=>$this->auth->user(),'today'=>$today,'_base_path'=>base_path()]);
    }

    public function config(): Response
    {
        $rows=$this->db->fetchAll(
            "SELECT setting_key,setting_value FROM settings
             WHERE setting_group='sales'
               AND setting_key IN ('pos_transaction_types','pos_default_transaction_type','default_payment_method')"
        );
        $values=[];
        foreach($rows as $row)$values[(string)$row['setting_key']]=(string)$row['setting_value'];
        $types=array_values(array_filter(array_map('trim',explode(',',$values['pos_transaction_types']??'GAS_SALE,EMPTY_CYLINDER_SALE'))));
        $default=$values['pos_default_transaction_type']??($types[0]??'GAS_SALE');
        if(!in_array($default,$types,true))$default=$types[0]??'GAS_SALE';
        return Response::json(['ok'=>true,'data'=>[
            'transaction_types'=>$types,
            'default_transaction_type'=>$default,
            'default_payment_method'=>$values['default_payment_method']??'CASH',
        ]]);
    }

    public function cylinders():Response
    {
        $q=$this->request->query();
        $group=(int)($q['group_id']??0);
        $type=(string)($q['transaction_type']??'GAS_SALE');
        $date=(string)($q['txn_date']??date('Y-m-d'));
        $where=['c.active=1',"c.condition_code='GOOD'","c.location='SHOP'"];
        $params=[];
        if($group>0){$where[]='c.group_id=:group';$params['group']=$group;}
        if($type==='EMPTY_CYLINDER_SALE')$where[]='c.gas_kg=0';
        else $where[]='c.gas_kg>0';
        $rows=$this->db->fetchAll(
            'SELECT c.id,c.code,c.group_id,c.gas_kg,c.location,c.customer_id,c.condition_code,
                    cg.code group_code,cg.name group_name,cg.capacity_kg
             FROM cylinders c INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             WHERE '.implode(' AND ',$where).' ORDER BY c.code',
            $params
        );
        foreach($rows as &$row){
            if($type==='EMPTY_CYLINDER_SALE'){
                $rate=$this->rates->resolve((int)$row['group_id'],$date);
                $row['gas_rate']='';
                $row['cylinder_price']=$rate['cylinder_price'];
            }else{
                try{$rate=$this->rates->resolve((int)$row['group_id'],$date);$row['gas_rate']=$rate['gas_rate'];$row['cylinder_price']=$rate['cylinder_price'];}
                catch(\Throwable){$row['gas_rate']='';$row['cylinder_price']='';}
            }
        }
        unset($row);
        return Response::json(['ok'=>true,'data'=>$rows]);
    }

    public function customers():Response
    {
        $q=trim((string)($this->request->query()['q']??''));
        $rows=$this->db->fetchAll(
            "SELECT id,code,name,allow_credit,credit_limit,opening_balance
             FROM parties WHERE party_type='CUSTOMER' AND active=1
             AND (name LIKE :q OR code LIKE :q OR phone LIKE :q)
             ORDER BY name LIMIT 30",
            ['q'=>'%'.$q.'%']
        );
        return Response::json(['ok'=>true,'data'=>$rows]);
    }

    public function info():Response
    {
        try{return Response::json(['ok'=>true,'data'=>$this->pos->customerInfo((int)($this->request->query()['customer_id']??0))]);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function issued():Response
    {
        try{return Response::json(['ok'=>true,'data'=>$this->pos->issuedCylinders((int)($this->request->query()['customer_id']??0))]);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function historyPage():Response
    {
        return View::render('sales-history/index',['pageTitle'=>'Sales History','user'=>$this->auth->user(),'today'=>date('Y-m-d'),'_base_path'=>base_path()]);
    }

    public function history():Response
    {
        $q=$this->request->query();
        return Response::json(['ok'=>true,'data'=>$this->pos->history($q)]);
    }

    public function detail():Response
    {
        $detail=$this->pos->detail((int)($this->request->query()['id']??0));
        if(!$detail)return Response::json(['ok'=>false,'message'=>'Sale not found.'],404);
        return Response::json(['ok'=>true,'data'=>$detail]);
    }

    public function store():Response
    {
        try{
            $d=$this->request->input();
            $user=$this->auth->user();
            $lines=json_decode((string)($d['lines_json']??'[]'),true,512,JSON_THROW_ON_ERROR);
            $result=$this->pos->post([
                'txn_date'=>(string)($d['txn_date']??date('Y-m-d')),
                'transaction_type'=>(string)($d['transaction_type']??'GAS_SALE'),
                'customer_id'=>(int)($d['customer_id']??0),
                'counter_id'=>(int)($d['counter_id']??($user['default_counter_id']??0)),
                'method'=>strtoupper((string)($d['method']??'CASH')),
                'received_amount'=>(string)($d['received_amount']??'0.00'),
                'cheque_no'=>$d['cheque_no']??null,
                'cheque_bank'=>$d['cheque_bank']??null,
                'cheque_date'=>$d['cheque_date']??null,
                'narration'=>$d['narration']??null,
                'lines'=>$lines
            ],(int)$user['id']);
            return Response::json(['ok'=>true,'data'=>$result,'message'=>'Sale posted.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function void():Response
    {
        try{
            $d=$this->request->input();
            $this->pos->void((int)($d['id']??0),(int)$this->auth->user()['id'],(string)($d['reason']??''));
            return Response::json(['ok'=>true,'message'=>'Sale voided.']);
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
