<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class PosService
{
    public function __construct(
        private readonly DB $db,
        private readonly DocNumberService $docs,
        private readonly LedgerService $ledger,
        private readonly CashService $cash,
        private readonly RateService $rates,
        private readonly StockService $stock
    ) {
    }

    public function post(array $input, int $userId): array
    {
        $date=(string)($input['txn_date']??date('Y-m-d'));
        $customerId=(int)($input['customer_id']??0);
        $counterId=(int)($input['counter_id']??0);
        $method=strtoupper((string)($input['method']??'CASH'));
        $received=(string)($input['received_amount']??'0.00');
        $lines=$input['lines']??[];
        $txnType=(string)($input['transaction_type']??'GAS_SALE');

        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) throw new \InvalidArgumentException('Transaction date is invalid.');
        if(!in_array($txnType,['GAS_SALE','EMPTY_CYLINDER_SALE'],true)) throw new \InvalidArgumentException('Transaction type is not supported.');
        if(!in_array($method,['CASH','ONLINE','CHEQUE'],true)) throw new \InvalidArgumentException('Payment method is invalid.');
        if(bccomp($received,'0.00',2)<0) throw new \InvalidArgumentException('Received amount cannot be negative.');
        if($customerId<1||!is_array($lines)||$lines===[]) throw new \InvalidArgumentException('Customer and at least one line are required.');

        return $this->db->transaction(function()use($input,$date,$customerId,$counterId,$method,$received,$lines,$userId,$txnType):array{
            $party=$this->db->fetchOne(
                "SELECT * FROM parties WHERE id=:id AND party_type='CUSTOMER' AND active=1 FOR UPDATE",
                ['id'=>$customerId]
            );
            if(!$party) throw new \InvalidArgumentException('Customer is invalid or inactive.');

            $issue='0.00';
            $sold='0.00';
            $returned='0.00';
            $taxBase='0.00';
            $lineRows=[];
            $soldCodes=[];
            $issuedCount=0;

            foreach($lines as $line){
                if(!is_array($line)) throw new \InvalidArgumentException('Invalid sale line.');

                $type=(string)($line['type']??'ISSUE');
                if($txnType==='EMPTY_CYLINDER_SALE'){
                    $type='SELL_EMPTY';
                } elseif(!in_array($type,['ISSUE','SELL_FILLED','RETURN'],true)){
                    throw new \InvalidArgumentException('Invalid line type.');
                }

                $cid=(int)($line['cylinder_id']??0);
                $gas=(string)($line['gas_kg']??'0.000');
                $rate=(string)($line['rate']??'0.00');
                $cprice=(string)($line['cylinder_price']??'0.00');

                $cylinder=$this->db->fetchOne(
                    'SELECT c.*,cg.capacity_kg FROM cylinders c INNER JOIN cylinder_groups cg ON cg.id=c.group_id WHERE c.id=:id AND c.active=1 FOR UPDATE',
                    ['id'=>$cid]
                );
                if(!$cylinder||$cylinder['condition_code']!=='GOOD') throw new \InvalidArgumentException('Cylinder is unavailable.');

                $before=(string)$cylinder['gas_kg'];
                $capacity=(string)$cylinder['capacity_kg'];
                $resolved=null;

                if($type!=='RETURN'){
                    $resolved=$this->rates->resolve((int)$cylinder['group_id'],$date);
                    if(bccomp($rate,'0.00',2)<=0) $rate=(string)$resolved['gas_rate'];
                    if(bccomp($cprice,'0.00',2)<=0) $cprice=(string)$resolved['cylinder_price'];
                } else {
                    $issue=$this->db->fetchOne(
                        "SELECT gas_kg,rate,created_at
                         FROM sale_lines
                         WHERE cylinder_id=:cylinder AND line_type='ISSUE'
                         ORDER BY id DESC LIMIT 1",
                        ['cylinder'=>$cid]
                    );
                    if(!$issue) throw new \InvalidArgumentException('No prior issue record was found for '.$cylinder['code'].'.');
                    if(bccomp($rate,'0.00',2)<=0) $rate=(string)$issue['rate'];
                }

                if(bccomp($gas,'0.000',3)<0||bccomp($gas,$capacity,3)>0){
                    throw new \InvalidArgumentException('Gas quantity is outside capacity for '.$cylinder['code'].'.');
                }

                if($type==='ISSUE'){
                    if($cylinder['location']!=='SHOP') throw new \InvalidArgumentException('Cylinder '.$cylinder['code'].' is no longer available.');
                    if(bccomp($gas,'0.000',3)<=0) throw new \InvalidArgumentException('Gas quantity must be greater than zero.');
                    if(bccomp($gas,$before,3)>0) throw new \InvalidArgumentException('Gas quantity for '.$cylinder['code'].' cannot exceed available gas of '.$before.' kg.');
                    if(bccomp($rate,'0.00',2)<=0) throw new \InvalidArgumentException('Gas rate must be greater than zero.');
                    $amount=bcmul($gas,$rate,2);
                    $issue=bcadd($issue,$amount,2);
                    $taxBase=bcadd($taxBase,$amount,2);
                    $to='CUSTOMER';
                    $after=bcsub($before,$gas,3);
                    $issuedCount++;
                } elseif($type==='SELL_FILLED'){
                    if($cylinder['location']!=='SHOP') throw new \InvalidArgumentException('Cylinder '.$cylinder['code'].' is no longer available.');
                    if(bccomp($before,'0.000',3)<=0) throw new \InvalidArgumentException('Only a cylinder containing gas can be sold as Filled.');
                    if(bccomp($gas,$before,3)!==0) throw new \InvalidArgumentException('Filled-cylinder sale must sell the full available gas of '.$before.' kg.');
                    if(bccomp($rate,'0.00',2)<=0) throw new \InvalidArgumentException('Gas rate must be greater than zero.');
                    if(bccomp($cprice,'0.00',2)<=0) throw new \InvalidArgumentException('Cylinder price must be greater than zero.');
                    $gasAmount=bcmul($gas,$rate,2);
                    $amount=bcadd($gasAmount,$cprice,2);
                    $sold=bcadd($sold,$amount,2);
                    $taxBase=bcadd($taxBase,$gasAmount,2);
                    $to='SOLD';
                    $after='0.000';
                    $soldCodes[]=$cylinder['code'];
                } elseif($type==='SELL_EMPTY'){
                    if($cylinder['location']!=='SHOP'||bccomp($before,'0.000',3)!==0) throw new \InvalidArgumentException('Only an empty shop cylinder can be sold as Empty.');
                    if(bccomp($cprice,'0.00',2)<=0) throw new \InvalidArgumentException('Cylinder price must be greater than zero.');
                    $amount=$cprice;
                    $sold=bcadd($sold,$amount,2);
                    $to='SOLD';
                    $after='0.000';
                    $gas='0.000';
                    $soldCodes[]=$cylinder['code'];
                } else {
                    if($cylinder['location']!=='CUSTOMER'||(int)$cylinder['customer_id']!==$customerId) throw new \InvalidArgumentException('Cylinder '.$cylinder['code'].' is not held by this customer.');
                    if(bccomp($rate,'0.00',2)<=0 && bccomp($gas,'0.000',3)>0) throw new \InvalidArgumentException('Return rate must be greater than zero when gas is returned.');
                    $credit=bcmul($gas,$rate,2);
                    $amount=bcsub('0.00',$credit,2);
                    $returned=bcadd($returned,$credit,2);
                    $to='SHOP';
                    $after=$gas;
                }

                $lineRows[]=[
                    'type'=>$type,'cid'=>$cid,'gas'=>$gas,'rate'=>$rate,'cp'=>$cprice,'amount'=>$amount,
                    'before'=>$before,'after'=>$after,'from'=>$cylinder['location'],'to'=>$to,
                    'customer'=>$customerId,
                ];
            }

            $tax='0.00';
            if($this->taxEnabled()){
                $tax=bcmul($taxBase,$this->taxRate(),2);
                $tax=bcdiv($tax,'100',2);
            }

            $net=bcadd(bcsub(bcadd($issue,$sold,2),$returned,2),$tax,2);
            $effectiveReceived = ($method === 'CHEQUE' && $this->chequePostingMode() === 'ON_CLEARANCE') ? '0.00' : $received;
            $oldBalance=$this->ledger->balance($customerId);
            $newBalance=bcadd($oldBalance,bcsub($net,$effectiveReceived,2),2);

            if(!$this->allowAdvance()&&bccomp($newBalance,'0.00',2)<0){
                throw new \InvalidArgumentException('Advance payment is disabled for POS.');
            }

            $enforcement=$this->creditEnforcement();
            if($enforcement==='BLOCK'&&bccomp($newBalance,'0.00',2)>0){
                $allow=(int)$party['allow_credit'];
                $limit=(string)$party['credit_limit'];
                if(!$allow||bccomp($newBalance,$limit,2)>0){
                    throw new \InvalidArgumentException('Credit limit exceeded. Current balance would be '.$newBalance.'.');
                }
            }

            if($received!=='0.00'&&$method==='CASH'&&$counterId<1){
                throw new \InvalidArgumentException('A cash counter is required for cash payments.');
            }

            $doc=$this->docs->next('SALE');
            $this->db->execute(
                'INSERT INTO sales(doc_no,txn_date,customer_id,counter_id,issue_total,cylinder_sale_total,return_total,tax_amount,net_amount,received_amount,balance_after,created_by)
                 VALUES(:doc,:date,:customer,:counter,:issue,:sold,:returned,:tax,:net,:received,:balance,:user)',
                [
                    'doc'=>$doc,'date'=>$date,'customer'=>$customerId,'counter'=>$counterId>0?$counterId:null,
                    'issue'=>$issue,'sold'=>$sold,'returned'=>$returned,'tax'=>$tax,'net'=>$net,
                    'received'=>$received,'balance'=>$newBalance,'user'=>$userId
                ]
            );
            $saleId=(int)$this->db->pdo()->lastInsertId();

            foreach($lineRows as $line){
                $this->db->execute(
                    'INSERT INTO sale_lines(sale_id,line_type,cylinder_id,gas_kg,rate,cylinder_price,amount)
                     VALUES(:sale,:type,:cylinder,:gas,:rate,:price,:amount)',
                    [
                        'sale'=>$saleId,'type'=>$line['type'],'cylinder'=>$line['cid'],'gas'=>$line['gas'],
                        'rate'=>$line['rate'],'price'=>$line['cp'],'amount'=>$line['amount']
                    ]
                );

                $this->stock->move(
                    $line['cid'],
                    $line['type']==='RETURN'?'RETURN':($line['type']==='ISSUE'?'ISSUE':'SALE_OUT'),
                    $line['to'],
                    $line['after'],
                    $customerId,
                    $line['rate'],
                    'SALE',
                    $saleId,
                    $userId,
                    null
                );
            }

            if(bccomp($net,'0.00',2)>0){
                $this->ledger->post($customerId,$date,'SALE',$saleId,$net,'0.00','Sale '.$doc,$userId);
            }
            if(bccomp($returned,'0.00',2)>0){
                $this->ledger->post($customerId,$date,'SALE_RETURN',$saleId,'0.00',$returned,'Return credit '.$doc,$userId);
            }

            $receiptId=null;
            if(bccomp($received,'0.00',2)>0){
                $receiptDoc=$this->docs->next('RECEIPT');
                $chequeId=null;

                if($method==='CHEQUE'){
                    $chequeNo=trim((string)($input['cheque_no']??''));
                    if($chequeNo==='') throw new \InvalidArgumentException('Cheque number is required.');
                    $chequeDate=(string)($input['cheque_date']??$date);
                    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$chequeDate)) throw new \InvalidArgumentException('Cheque date is invalid.');

                    $this->db->execute(
                        "INSERT INTO cheques(direction,party_id,cheque_no,bank,cheque_date,amount,status,created_by)
                         VALUES('IN',:party,:no,:bank,:date,:amount,'PENDING',:user)",
                        [
                            'party'=>$customerId,'no'=>$chequeNo,'bank'=>trim((string)($input['cheque_bank']??''))?:null,
                            'date'=>$chequeDate,'amount'=>$received,'user'=>$userId
                        ]
                    );
                    $chequeId=(int)$this->db->pdo()->lastInsertId();
                }

                $this->db->execute(
                    "INSERT INTO receipts(doc_no,receipt_date,party_id,amount,method,counter_id,cheque_id,source,sale_id,narration,created_by)
                     VALUES(:doc,:date,:party,:amount,:method,:counter,:cheque,'POS',:sale,:narration,:user)",
                    [
                        'doc'=>$receiptDoc,'date'=>$date,'party'=>$customerId,'amount'=>$received,'method'=>$method,
                        'counter'=>$counterId>0?$counterId:null,'cheque'=>$chequeId,'sale'=>$saleId,
                        'narration'=>trim((string)($input['narration']??''))?:null,'user'=>$userId
                    ]
                );
                $receiptId=(int)$this->db->pdo()->lastInsertId();

                if($chequeId){
                    $this->db->execute('UPDATE cheques SET receipt_id=:receipt WHERE id=:id',['receipt'=>$receiptId,'id'=>$chequeId]);
                }

                if($method!=='CHEQUE'||$this->chequePostingMode()==='ON_RECEIPT'){
                    $this->ledger->post($customerId,$date,'RECEIPT',$receiptId,'0.00',$received,'Receipt '.$receiptDoc,$userId);
                }
                if($method==='CASH'){
                    $this->cash->post($counterId,$date,'IN',$received,'RECEIPT',$receiptId,$userId);
                }
            }

            return [
                'id'=>$saleId,'doc_no'=>$doc,'net_amount'=>$net,'tax_amount'=>$tax,'received_amount'=>$received,
                'balance_after'=>$newBalance,'issued_count'=>$issuedCount,'sold_count'=>count($soldCodes),
                'sold_codes'=>$soldCodes,'receipt_id'=>$receiptId
            ];
        });
    }

    public function issuedCylinders(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id,c.code,c.group_id,c.gas_kg,c.condition_code,cg.name group_name,cg.capacity_kg,
                    m.created_at issue_date,m.rate issue_rate
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             LEFT JOIN (
                SELECT m1.*
                FROM cylinder_movements m1
                INNER JOIN (
                    SELECT cylinder_id,MAX(id) id
                    FROM cylinder_movements
                    WHERE movement_type='ISSUE' AND customer_id=:customer
                    GROUP BY cylinder_id
                ) latest ON latest.id=m1.id
             ) m ON m.cylinder_id=c.id
             WHERE c.location='CUSTOMER' AND c.customer_id=:customer_id AND c.active=1
             ORDER BY m.created_at DESC,c.code",
            ['customer'=>$customerId,'customer_id'=>$customerId]
        );
    }

    public function detail(int $saleId): ?array
    {
        $sale=$this->db->fetchOne(
            'SELECT s.*,p.code customer_code,p.name customer_name,u.full_name user_name
             FROM sales s
             INNER JOIN parties p ON p.id=s.customer_id
             LEFT JOIN users u ON u.id=s.created_by
             WHERE s.id=:id',
            ['id'=>$saleId]
        );
        if(!$sale) return null;

        $sale['lines']=$this->db->fetchAll(
            'SELECT sl.*,c.code cylinder_code,cg.name group_name
             FROM sale_lines sl
             INNER JOIN cylinders c ON c.id=sl.cylinder_id
             INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             WHERE sl.sale_id=:sale ORDER BY sl.id',
            ['sale'=>$saleId]
        );
        $sale['receipts']=$this->db->fetchAll(
            'SELECT r.*,ch.cheque_no,ch.bank,ch.cheque_date,ch.status cheque_status
             FROM receipts r LEFT JOIN cheques ch ON ch.id=r.cheque_id
             WHERE r.sale_id=:sale ORDER BY r.id',
            ['sale'=>$saleId]
        );
        return $sale;
    }

    public function history(array $filters): array
    {
        $where=['1=1'];
        $params=['limit'=>min(100,(int)($filters['limit']??50)),'offset'=>max(0,(int)($filters['offset']??0))];

        if(($filters['from']??'')!==''){$where[]='s.txn_date>=:from';$params['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='s.txn_date<=:to';$params['to']=$filters['to'];}
        if((int)($filters['customer_id']??0)>0){$where[]='s.customer_id=:customer';$params['customer']=(int)$filters['customer_id'];}
        if(($filters['status']??'')!==''){$where[]='s.status=:status';$params['status']=$filters['status'];}
        if(($filters['search']??'')!==''){$where[]='(s.doc_no LIKE :search OR p.name LIKE :search OR EXISTS(SELECT 1 FROM sale_lines sx INNER JOIN cylinders cx ON cx.id=sx.cylinder_id WHERE sx.sale_id=s.id AND cx.code LIKE :search))';$params['search']='%'.$filters['search'].'%';}

        $base='FROM sales s INNER JOIN parties p ON p.id=s.customer_id LEFT JOIN users u ON u.id=s.created_by WHERE '.implode(' AND ',$where);
        $total=(int)($this->db->fetchOne('SELECT COUNT(*) c '.$base,$params)['c']??0);
        $rows=$this->db->fetchAll(
            'SELECT s.*,p.code customer_code,p.name customer_name,u.full_name user_name,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id=s.id) lines_count,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id=s.id AND sl.line_type IN (\'SELL_FILLED\',\'SELL_EMPTY\')) sold_count
             '.$base.' ORDER BY s.txn_date DESC,s.id DESC LIMIT :limit OFFSET :offset',
            $params
        );
        return ['rows'=>$rows,'total'=>$total];
    }

    public function void(int $saleId,int $userId,string $reason):void
    {
        $reason=trim($reason);
        if($reason==='') throw new \InvalidArgumentException('Void reason is required.');

        $this->db->transaction(function()use($saleId,$userId,$reason):void{
            $sale=$this->db->fetchOne('SELECT * FROM sales WHERE id=:id FOR UPDATE',['id'=>$saleId]);
            if(!$sale) throw new \InvalidArgumentException('Sale not found.');
            if($sale['status']!=='POSTED') throw new \InvalidArgumentException('Sale is already void.');

            $movements=$this->db->fetchAll(
                "SELECT m.*,c.code
                 FROM cylinder_movements m
                 INNER JOIN cylinders c ON c.id=m.cylinder_id
                 WHERE m.source_document_type='SALE' AND m.source_document_id=:sale
                 ORDER BY m.id",
                ['sale'=>$saleId]
            );

            foreach($movements as $movement){
                $later=$this->db->fetchOne(
                    "SELECT m.*,c.code
                     FROM cylinder_movements m
                     INNER JOIN cylinders c ON c.id=m.cylinder_id
                     WHERE m.cylinder_id=:cylinder
                       AND m.id>:movement
                       AND NOT (m.source_document_type='SALE' AND m.source_document_id=:sale)
                     ORDER BY m.id LIMIT 1",
                    ['cylinder'=>$movement['cylinder_id'],'movement'=>$movement['id'],'sale'=>$saleId]
                );
                if($later){
                    throw new \InvalidArgumentException(
                        'Cannot void sale: cylinder '.$movement['code'].' has later '.$later['movement_type'].
                        ' movement from '.$this->documentLabel($later).'. Void that document first.'
                    );
                }
            }

            foreach(array_reverse($movements) as $movement){
                $this->stock->reverseMovement($movement,$userId,$saleId,'SALE_VOID');
            }

            $this->ledger->reverseDocument('SALE',$saleId,$userId,(string)$sale['txn_date'],$reason);
            $this->ledger->reverseDocument('SALE_RETURN',$saleId,$userId,(string)$sale['txn_date'],$reason);

            $receipts=$this->db->fetchAll(
                'SELECT * FROM receipts WHERE sale_id=:sale AND status=\'POSTED\' FOR UPDATE',
                ['sale'=>$saleId]
            );
            foreach($receipts as $receipt){
                $this->ledger->reverseDocument('RECEIPT',(int)$receipt['id'],$userId,(string)$sale['txn_date'],$reason);
                if($receipt['method']==='CASH') $this->cash->reverseDocument('RECEIPT',(int)$receipt['id'],$userId);
                if($receipt['cheque_id']){
                    $this->db->execute("UPDATE cheques SET status='BOUNCED',bounce_reason=:reason WHERE id=:id AND status<>'BOUNCED'",['reason'=>$reason,'id'=>$receipt['cheque_id']]);
                }
                $this->db->execute("UPDATE receipts SET status='VOID' WHERE id=:id",['id'=>$receipt['id']]);
            }

            $this->db->execute(
                "UPDATE sales SET status='VOID',void_reason=:reason,voided_at=NOW(),voided_by=:user WHERE id=:id",
                ['reason'=>$reason,'user'=>$userId,'id'=>$saleId]
            );
        });
    }

    public function customerInfo(int $customerId): array
    {
        $party=$this->db->fetchOne('SELECT id,code,name,allow_credit,credit_limit,opening_balance FROM parties WHERE id=:id AND party_type=\'CUSTOMER\' AND active=1',['id'=>$customerId]);
        if(!$party) throw new \InvalidArgumentException('Customer not found.');

        $balance=$this->ledger->balance($customerId);
        $held=$this->db->fetchOne(
            "SELECT COUNT(*) cylinder_count,COALESCE(SUM(gas_kg),0) gas_kg
             FROM cylinders WHERE customer_id=:customer AND location='CUSTOMER' AND active=1",
            ['customer'=>$customerId]
        );
        $pending=$this->db->fetchOne(
            "SELECT COUNT(*) count,COALESCE(SUM(amount),0) amount
             FROM cheques WHERE direction='IN' AND party_id=:customer AND status='PENDING'",
            ['customer'=>$customerId]
        );

        return [
            'party'=>$party,
            'balance'=>$balance,
            'cylinder_count'=>(int)($held['cylinder_count']??0),
            'cylinder_gas'=>(string)($held['gas_kg']??'0.000'),
            'pending_cheque_count'=>(int)($pending['count']??0),
            'pending_cheque_amount'=>(string)($pending['amount']??'0.00')
        ];
    }

    private function creditEnforcement():string
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='sales_credit' AND setting_key='credit_limit_enforcement'");
        return strtoupper((string)($row['setting_value']??'BLOCK'));
    }

    private function allowAdvance():bool
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='sales_credit' AND setting_key='allow_advance'");
        return filter_var($row['setting_value']??'1',FILTER_VALIDATE_BOOL);
    }

    private function taxEnabled():bool
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='tax' AND setting_key='enabled'");
        return filter_var($row['setting_value']??'0',FILTER_VALIDATE_BOOL);
    }

    private function taxRate():string
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='tax' AND setting_key='rate_percent'");
        return (string)($row['setting_value']??'0.00');
    }

    private function chequePostingMode():string
    {
        $row=$this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key='cheque_ledger_posting'");
        return strtoupper((string)($row['setting_value']??'ON_CLEARANCE'));
    }

    private function documentLabel(array $movement):string
    {
        if($movement['source_document_type']==='SALE'){
            $row=$this->db->fetchOne('SELECT doc_no FROM sales WHERE id=:id',['id'=>$movement['source_document_id']]);
            return 'sale '.($row['doc_no']??'#'.$movement['source_document_id']);
        }
        if($movement['source_document_type']==='PURCHASE'){
            $row=$this->db->fetchOne('SELECT doc_no FROM purchases WHERE id=:id',['id'=>$movement['source_document_id']]);
            return 'purchase '.($row['doc_no']??'#'.$movement['source_document_id']);
        }
        return (string)$movement['source_document_type'];
    }
}
