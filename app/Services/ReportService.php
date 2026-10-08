<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ReportService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function run(string $type,array $filters):array
    {
        return match($type){
            'stock_summary'=>$this->stockSummary($filters),
            'cylinder_history'=>$this->cylinderHistory($filters),
            'cylinders_sold'=>$this->cylindersSold($filters),
            'customer_ledger'=>$this->partyLedger('CUSTOMER',$filters),
            'supplier_ledger'=>$this->partyLedger('SUPPLIER',$filters),
            'outstanding'=>$this->outstanding($filters),
            'customer_cylinders'=>$this->customerCylinders($filters),
            'cash'=>$this->cash($filters),
            'sales'=>$this->sales($filters),
            'receipts'=>$this->receipts($filters),
            'payments'=>$this->payments($filters),
            'expenses'=>$this->expenses($filters),
            default=>throw new \InvalidArgumentException('Unknown report type.')
        };
    }

    public function csv(string $type,array $filters):string
    {
        $result=$this->run($type,$filters);
        $handle=fopen('php://temp','r+');
        if($handle===false) throw new \RuntimeException('Unable to create export buffer.');
        $rows=$result['rows']??[];
        if($rows!==[]){
            fputcsv($handle,array_keys($rows[0]));
            foreach($rows as $row)fputcsv($handle,array_values($row));
        }else{
            fputcsv($handle,['message']);
            fputcsv($handle,['No data']);
        }
        rewind($handle);
        $csv=(string)stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    private function stockSummary(array $filters):array
    {
        $rows=$this->db->fetchAll(
            "SELECT cg.code group_code,cg.name group_name,cg.capacity_kg,
                    SUM(CASE WHEN c.location='SHOP' AND c.gas_kg=cg.capacity_kg THEN 1 ELSE 0 END) filled,
                    SUM(CASE WHEN c.location='SHOP' AND c.gas_kg>0 AND c.gas_kg<cg.capacity_kg THEN 1 ELSE 0 END) partial,
                    SUM(CASE WHEN c.location='SHOP' AND c.gas_kg=0 THEN 1 ELSE 0 END) empty,
                    SUM(CASE WHEN c.location='SHOP' THEN c.gas_kg ELSE 0 END) shop_gas_kg,
                    SUM(CASE WHEN c.location='CUSTOMER' THEN 1 ELSE 0 END) issued,
                    SUM(CASE WHEN c.location='CUSTOMER' THEN c.gas_kg ELSE 0 END) issued_gas_kg,
                    SUM(CASE WHEN c.condition_code='DAMAGED' AND c.active=1 THEN 1 ELSE 0 END) damaged,
                    SUM(CASE WHEN c.location='SOLD' THEN 1 ELSE 0 END) sold
             FROM cylinder_groups cg LEFT JOIN cylinders c ON c.group_id=cg.id
             GROUP BY cg.id,cg.code,cg.name,cg.capacity_kg
             ORDER BY cg.code"
        );
        return ['columns'=>array_keys($rows[0]??['group_code'=>'']),'rows'=>$rows,'title'=>'Stock Summary'];
    }

    private function cylinderHistory(array $filters):array
    {
        $code=trim((string)($filters['code']??''));
        if($code==='') throw new \InvalidArgumentException('Cylinder code is required.');
        $rows=$this->db->fetchAll(
            "SELECT c.code,cg.name group_name,m.movement_type,m.before_gas_kg,m.after_gas_kg,
                    m.from_location,m.to_location,m.customer_id,p.name customer_name,
                    m.rate,m.source_document_type,m.source_document_id,m.created_at,u.full_name user_name
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             INNER JOIN cylinder_movements m ON m.cylinder_id=c.id
             LEFT JOIN parties p ON p.id=m.customer_id
             LEFT JOIN users u ON u.id=m.created_by
             WHERE c.code=:code ORDER BY m.id",
            ['code'=>$code]
        );
        return ['columns'=>array_keys($rows[0]??['code'=>$code]),'rows'=>$rows,'title'=>'Cylinder History'];
    }

    private function cylindersSold(array $filters):array
    {
        $where=["m.movement_type='SALE_OUT'","m.to_location='SOLD'"];$p=[];
        if(($filters['from']??'')!==''){$where[]='DATE(m.created_at)>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='DATE(m.created_at)<=:to';$p['to']=$filters['to'];}
        if((int)($filters['customer_id']??0)>0){$where[]='sl.sale_id IN (SELECT id FROM sales WHERE customer_id=:customer)';$p['customer']=(int)$filters['customer_id'];}
        $rows=$this->db->fetchAll(
            "SELECT m.created_at,c.code,cg.code group_code,cg.name group_name,
                    s.doc_no,p.name customer_name,sl.line_type,sl.gas_kg,sl.rate,sl.cylinder_price,sl.amount
             FROM cylinder_movements m
             INNER JOIN cylinders c ON c.id=m.cylinder_id
             INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             INNER JOIN sales s ON s.id=m.source_document_id
             INNER JOIN sale_lines sl ON sl.sale_id=s.id AND sl.cylinder_id=c.id
             INNER JOIN parties p ON p.id=s.customer_id
             WHERE ".implode(' AND ',$where)." ORDER BY m.id DESC",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['doc_no'=>'']),'rows'=>$rows,'title'=>'Cylinders Sold'];
    }

    private function partyLedger(string $partyType,array $filters):array
    {
        $partyId=(int)($filters['party_id']??0);
        if($partyId<1) throw new \InvalidArgumentException('Party is required.');
        $party=$this->db->fetchOne('SELECT id,code,name,opening_balance FROM parties WHERE id=:id AND party_type=:type',['id'=>$partyId,'type'=>$partyType]);
        if(!$party) throw new \InvalidArgumentException('Party not found.');

        $where=['le.party_id=:party'];$p=['party'=>$partyId];
        if(($filters['from']??'')!==''){$where[]='le.entry_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='le.entry_date<=:to';$p['to']=$filters['to'];}

        $rows=$this->db->fetchAll(
            'SELECT le.entry_date,le.doc_type,le.doc_id,le.debit,le.credit,le.narration,
                    le.reversal_of
             FROM ledger_entries le WHERE '.implode(' AND ',$where).' ORDER BY le.entry_date,le.id',
            $p
        );
        $balance=(string)$party['opening_balance'];
        foreach($rows as &$row){
            $balance=bcadd($balance,bcsub((string)$row['debit'],(string)$row['credit'],2),2);
            $row['running_balance']=$balance;
        }
        unset($row);
        return ['columns'=>array_keys($rows[0]??['entry_date'=>'']),'rows'=>$rows,'title'=>$partyType==='CUSTOMER'?'Customer Ledger':'Supplier Ledger','meta'=>['party'=>$party,'opening_balance'=>$party['opening_balance'],'closing_balance'=>$balance]];
    }

    private function outstanding(array $filters):array
    {
        $type=strtoupper((string)($filters['party_type']??'CUSTOMER'));
        if(!in_array($type,['CUSTOMER','SUPPLIER'],true))$type='CUSTOMER';
        $rows=$this->db->fetchAll(
            'SELECT id,code,name,opening_balance,
                    opening_balance+COALESCE((SELECT SUM(debit-credit) FROM ledger_entries le WHERE le.party_id=p.id),0) balance
             FROM parties p WHERE party_type=:type AND active=1 ORDER BY name',
            ['type'=>$type]
        );
        foreach($rows as &$row){
            $balance=(string)$row['balance'];
            $row['display_balance']=$type==='SUPPLIER'?bcsub('0.00',$balance,2):$balance;
        }
        unset($row);
        return ['columns'=>array_keys($rows[0]??['code'=>'']),'rows'=>$rows,'title'=>$type==='SUPPLIER'?'Supplier Outstanding':'Customer Outstanding'];
    }

    private function customerCylinders(array $filters):array
    {
        $where=["c.location='CUSTOMER'","c.active=1"];$p=[];
        if((int)($filters['customer_id']??0)>0){$where[]='c.customer_id=:customer';$p['customer']=(int)$filters['customer_id'];}
        $rows=$this->db->fetchAll(
            "SELECT p.code customer_code,p.name customer_name,c.code cylinder_code,
                    cg.code group_code,cg.name group_name,c.gas_kg,
                    m.created_at issued_at,m.rate issue_rate
             FROM cylinders c
             INNER JOIN parties p ON p.id=c.customer_id
             INNER JOIN cylinder_groups cg ON cg.id=c.group_id
             LEFT JOIN (
                 SELECT m1.* FROM cylinder_movements m1
                 INNER JOIN (
                     SELECT cylinder_id,MAX(id) id FROM cylinder_movements
                     WHERE movement_type='ISSUE' GROUP BY cylinder_id
                 ) x ON x.id=m1.id
             ) m ON m.cylinder_id=c.id
             WHERE ".implode(' AND ',$where)." ORDER BY p.name,c.code",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['customer_code'=>'']),'rows'=>$rows,'title'=>'Cylinders Held by Customer'];
    }

    private function cash(array $filters):array
    {
        $counter=(int)($filters['counter_id']??0);
        if($counter<1) throw new \InvalidArgumentException('Counter is required.');
        $where=['ce.counter_id=:counter'];$p=['counter'=>$counter];
        if(($filters['from']??'')!==''){$where[]='ce.entry_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='ce.entry_date<=:to';$p['to']=$filters['to'];}
        $rows=$this->db->fetchAll(
            "SELECT ce.entry_date,ce.direction,ce.amount,ce.doc_type,ce.doc_id,ce.reason,
                    cs.opening_cash,cs.opened_at,cs.closed_at,cs.counted_cash,cs.expected_cash,cs.variance
             FROM cash_entries ce
             LEFT JOIN counter_sessions cs ON cs.id=ce.session_id
             WHERE ".implode(' AND ',$where)." ORDER BY ce.entry_date,ce.id",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['entry_date'=>'']),'rows'=>$rows,'title'=>'Daily Cash Register'];
    }

    private function sales(array $filters):array
    {
        $where=['1=1'];$p=[];
        if(($filters['from']??'')!==''){$where[]='s.txn_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='s.txn_date<=:to';$p['to']=$filters['to'];}
        if(($filters['status']??'')!==''){$where[]='s.status=:status';$p['status']=$filters['status'];}
        $rows=$this->db->fetchAll(
            "SELECT s.doc_no,s.txn_date,p.code customer_code,p.name customer_name,
                    s.issue_total,s.cylinder_sale_total,s.return_total,s.tax_amount,s.net_amount,
                    s.received_amount,s.balance_after,s.status,u.full_name user_name,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id=s.id) lines_count,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id=s.id AND sl.line_type IN ('SELL_EMPTY','SELL_FILLED')) sold_count
             FROM sales s INNER JOIN parties p ON p.id=s.customer_id
             LEFT JOIN users u ON u.id=s.created_by
             WHERE ".implode(' AND ',$where)." ORDER BY s.txn_date DESC,s.id DESC",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['doc_no'=>'']),'rows'=>$rows,'title'=>'Sales History'];
    }

    private function receipts(array $filters):array
    {
        $where=['1=1'];$p=[];
        if(($filters['from']??'')!==''){$where[]='r.receipt_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='r.receipt_date<=:to';$p['to']=$filters['to'];}
        $rows=$this->db->fetchAll(
            "SELECT r.doc_no,r.receipt_date,p.code customer_code,p.name customer_name,
                    r.amount,r.method,r.source,r.status,ch.cheque_no,ch.status cheque_status
             FROM receipts r INNER JOIN parties p ON p.id=r.party_id LEFT JOIN cheques ch ON ch.id=r.cheque_id
             WHERE ".implode(' AND ',$where)." ORDER BY r.receipt_date DESC,r.id DESC",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['doc_no'=>'']),'rows'=>$rows,'title'=>'Receipt History'];
    }

    private function payments(array $filters):array
    {
        $where=['1=1'];$p=[];
        if(($filters['from']??'')!==''){$where[]='pm.payment_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='pm.payment_date<=:to';$p['to']=$filters['to'];}
        $rows=$this->db->fetchAll(
            "SELECT pm.doc_no,pm.payment_date,p.code supplier_code,p.name supplier_name,
                    pm.amount,pm.method,pm.source,pm.status,ch.cheque_no,ch.status cheque_status
             FROM payments pm INNER JOIN parties p ON p.id=pm.party_id LEFT JOIN cheques ch ON ch.id=pm.cheque_id
             WHERE ".implode(' AND ',$where)." ORDER BY pm.payment_date DESC,pm.id DESC",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['doc_no'=>'']),'rows'=>$rows,'title'=>'Payment History'];
    }

    private function expenses(array $filters):array
    {
        $where=['1=1'];$p=[];
        if(($filters['from']??'')!==''){$where[]='e.expense_date>=:from';$p['from']=$filters['from'];}
        if(($filters['to']??'')!==''){$where[]='e.expense_date<=:to';$p['to']=$filters['to'];}
        $rows=$this->db->fetchAll(
            "SELECT e.expense_date,c.name category_name,e.amount,e.method,e.reference_no,e.payee,e.notes,e.status,u.full_name user_name
             FROM expenses e INNER JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.created_by
             WHERE ".implode(' AND ',$where)." ORDER BY e.expense_date DESC,e.id DESC",
            $p
        );
        return ['columns'=>array_keys($rows[0]??['expense_date'=>'']),'rows'=>$rows,'title'=>'Expense Report'];
    }
}
