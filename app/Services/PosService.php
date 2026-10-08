<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\DB;
final class PosService {
 public function __construct(private readonly DB $db,private readonly DocNumberService $docs,private readonly LedgerService $ledger,private readonly CashService $cash){}
 public function post(array $input,int $userId): array {
  $date=(string)$input['txn_date'];$customerId=(int)$input['customer_id'];$counterId=(int)($input['counter_id']??0);$method=(string)($input['method']??'CASH');$received=(string)($input['received_amount']??'0.00');$lines=$input['lines']??[];
  if($customerId<1||$lines===[]) throw new \InvalidArgumentException('Customer and at least one cylinder are required.');
  return $this->db->transaction(function()use($date,$customerId,$counterId,$method,$received,$lines,$userId):array{
   $party=$this->db->fetchOne('SELECT * FROM parties WHERE id=:id AND party_type=\'CUSTOMER\' AND active=1 FOR UPDATE',['id'=>$customerId]);
   if(!$party) throw new \InvalidArgumentException('Customer is invalid or inactive.');
   $issue=0;$sold=0;$returned=0;$saleLines=[];$movement=[];
   foreach($lines as $line){
    $cid=(int)($line['cylinder_id']??0);$type=(string)($line['type']??'ISSUE');$rate=(string)($line['rate']??'0');$gas=(string)($line['gas_kg']??'0');$cprice=(string)($line['cylinder_price']??'0');
    $c=$this->db->fetchOne('SELECT c.*,cg.capacity_kg FROM cylinders c JOIN cylinder_groups cg ON cg.id=c.group_id WHERE c.id=:id AND c.active=1 FOR UPDATE',['id'=>$cid]);
    if(!$c||$c['condition_code']!=='GOOD') throw new \InvalidArgumentException('Cylinder is unavailable.');
    $before=(string)$c['gas_kg'];$cap=(string)$c['capacity_kg'];
    if(in_array($type,['ISSUE','SELL_FILLED'],true)&&bccomp($gas,'0',3)<=0) throw new \InvalidArgumentException('Gas quantity must be greater than zero.');
    if(bccomp($gas,'0',3)<0||bccomp($gas,$cap,3)>0) throw new \InvalidArgumentException('Gas quantity exceeds cylinder capacity.');
    if($type==='ISSUE'){if($c['location']!=='SHOP')throw new \InvalidArgumentException('Cylinder '.$c['code'].' is no longer available.');$amount=bcmul($gas,$rate,2);$issue=bcadd($issue,$amount,2);$to='CUSTOMER';$after=bcsub($before,$gas,3);}
    elseif($type==='SELL_FILLED'){if($c['location']!=='SHOP')throw new \InvalidArgumentException('Cylinder '.$c['code'].' is no longer available.');$amount=bcadd(bcmul($gas,$rate,2),$cprice,2);$sold=bcadd($sold,$amount,2);$to='SOLD';$after='0.000';}
    elseif($type==='SELL_EMPTY'){if($c['location']!=='SHOP'||bccomp($before,'0',3)!==0)throw new \InvalidArgumentException('Only an empty shop cylinder can be sold as Empty.');$amount=$cprice;$sold=bcadd($sold,$amount,2);$to='SOLD';$after='0.000';$gas='0.000';}
    elseif($type==='RETURN'){if($c['location']!=='CUSTOMER'||(int)$c['customer_id']!==$customerId)throw new \InvalidArgumentException('Cylinder is not held by this customer.');$amount=bcsub('0.00',bcmul($gas,$rate,2),2);$returned=bcadd($returned,bcmul($gas,$rate,2),2);$to='SHOP';$after=$gas;}
    else throw new \InvalidArgumentException('Unsupported sale line.');
    $saleLines[]=['type'=>$type,'cid'=>$cid,'gas'=>$gas,'rate'=>$rate,'cp'=>$cprice,'amount'=>$amount,'before'=>$before,'after'=>$after,'from'=>$c['location'],'to'=>$to,'customer'=>$to==='CUSTOMER'?$customerId:null];
   }
   $net=bcadd(bcsub(bcadd($issue,$sold,2),$returned,2),'0.00',2);
   $old=$this->ledger->balance($customerId);$new=bcadd($old,bcsub($net,$received,2),2);
   $enforce=(string)($this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='sales_credit' AND setting_key='credit_enforcement'")['setting_value']??'BLOCK');
   if($enforce==='BLOCK'&&bccomp($new,'0.00',2)>0){$allow=(int)$party['allow_credit'];$limit=(string)$party['credit_limit'];if(!$allow||bccomp($new,$limit,2)>0)throw new \InvalidArgumentException('Credit limit exceeded. Current balance would be '.$new.'.');}
   $doc=$this->docs->next('SALE');
   $this->db->execute('INSERT INTO sales(doc_no,txn_date,customer_id,counter_id,issue_total,cylinder_sale_total,return_total,net_amount,received_amount,balance_after,created_by) VALUES(:doc,:d,:c,:ctr,:i,:s,:r,:n,:rec,:bal,:u)',[
    'doc'=>$doc,'d'=>$date,'c'=>$customerId,'ctr'=>$counterId?:null,'i'=>$issue,'s'=>$sold,'r'=>$returned,'n'=>$net,'rec'=>$received,'bal'=>$new,'u'=>$userId]);
   $saleId=(int)$this->db->pdo()->lastInsertId();
   foreach($saleLines as $l){
    $this->db->execute('INSERT INTO sale_lines(sale_id,line_type,cylinder_id,gas_kg,rate,cylinder_price,amount) VALUES(:s,:t,:c,:g,:r,:cp,:a)',[
     's'=>$saleId,'t'=>$l['type'],'c'=>$l['cid'],'g'=>$l['gas'],'r'=>$l['rate'],'cp'=>$l['cp'],'a'=>$l['amount']]);
    $this->db->execute('UPDATE cylinders SET gas_kg=:gas,location=:loc,customer_id=:cust,updated_by=:u WHERE id=:id',['gas'=>$l['after'],'loc'=>$l['to'],'cust'=>$l['customer'],'u'=>$userId,'id'=>$l['cid']]);
    $this->db->execute('INSERT INTO cylinder_movements(cylinder_id,movement_type,before_gas_kg,after_gas_kg,from_location,to_location,customer_id,rate,source_document_type,source_document_id,created_by) VALUES(:c,:t,:b,:a,:f,:to,:cust,:r,\'SALE\',:s,:u)',[
     'c'=>$l['cid'],'t'=>$l['type']==='RETURN'?'RETURN':($l['type']==='ISSUE'?'ISSUE':'SALE_OUT'),'b'=>$l['before'],'a'=>$l['after'],'f'=>$l['from'],'to'=>$l['to'],'cust'=>$l['customer'],'r'=>$l['rate'],'s'=>$saleId,'u'=>$userId]);
   }
   if(bccomp($net,'0.00',2)>0)$this->ledger->post($customerId,$date,'SALE',$saleId,$net,'0.00','Sale '.$doc,$userId);
   if(bccomp($returned,'0.00',2)>0)$this->ledger->post($customerId,$date,'SALE_RETURN',$saleId,'0.00',$returned,'Return credit '.$doc,$userId);
   if(bccomp($received,'0.00',2)>0){
    $receipt=$this->docs->next('RECEIPT');$this->db->execute('INSERT INTO receipts(doc_no,receipt_date,party_id,amount,method,counter_id,source,sale_id,created_by) VALUES(:doc,:d,:p,:a,:m,:c,\'POS\',:s,:u)',[
     'doc'=>$receipt,'d'=>$date,'p'=>$customerId,'a'=>$received,'m'=>$method,'c'=>$counterId?:null,'s'=>$saleId,'u'=>$userId]);
    $receiptId=(int)$this->db->pdo()->lastInsertId();$this->ledger->post($customerId,$date,'RECEIPT',$receiptId,'0.00',$received,'Receipt '.$receipt,$userId);
    if($method==='CASH')$this->cash->post($counterId,$date,'IN',$received,'RECEIPT',$receiptId,$userId);
   }
   return ['id'=>$saleId,'doc_no'=>$doc,'net_amount'=>$net,'received_amount'=>$received,'balance_after'=>$new];
  });
 }
}