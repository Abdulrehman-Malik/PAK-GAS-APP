<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class ReportController
{
    public function __construct(private readonly DB $db,private readonly Auth $auth,private readonly Request $request){}
    public function index():Response{return View::render('reports/index',['pageTitle'=>'Reports','user'=>$this->auth->user()]);}
    private function rows(string $type,string $from,string $to):array{
      return match($type){
        'stock'=>$this->db->fetchAll("SELECT group_code,group_name,capacity_kg,
          SUM(CASE WHEN location='SHOP' AND gas_kg=capacity_kg AND condition_code='GOOD' AND active=1 THEN 1 ELSE 0 END) filled,
          SUM(CASE WHEN location='SHOP' AND gas_kg>0 AND gas_kg<capacity_kg AND condition_code='GOOD' AND active=1 THEN 1 ELSE 0 END) partial,
          SUM(CASE WHEN location='SHOP' AND gas_kg=0 AND condition_code='GOOD' AND active=1 THEN 1 ELSE 0 END) empty,
          SUM(CASE WHEN location='CUSTOMER' AND active=1 THEN 1 ELSE 0 END) issued,
          SUM(CASE WHEN location='SOLD' THEN 1 ELSE 0 END) sold,
          COALESCE(SUM(CASE WHEN location='SHOP' AND condition_code='GOOD' AND active=1 THEN gas_kg ELSE 0 END),0) shop_gas
          FROM v_cylinder_status GROUP BY group_id,group_code,group_name,capacity_kg ORDER BY group_code"),
        'sold'=>$this->db->fetchAll("SELECT s.txn_date,s.doc_no,p.code customer_code,p.name customer_name,c.code cylinder_code,cg.code group_code,sl.line_type,sl.gas_kg,sl.amount,s.status FROM sale_lines sl JOIN sales s ON s.id=sl.sale_id JOIN parties p ON p.id=s.customer_id JOIN cylinders c ON c.id=sl.cylinder_id JOIN cylinder_groups cg ON cg.id=c.group_id WHERE sl.line_type IN ('SELL_EMPTY','SELL_FILLED') AND s.txn_date BETWEEN :f AND :t ORDER BY s.txn_date DESC,s.id DESC",['f'=>$from,'t'=>$to]),
        'balances'=>$this->db->fetchAll("SELECT * FROM v_party_balance WHERE party_type='CUSTOMER' AND balance>0 ORDER BY balance DESC"),
        'supplier_balances'=>$this->db->fetchAll("SELECT * FROM v_party_balance WHERE party_type='SUPPLIER' AND balance>0 ORDER BY balance DESC"),
        'held'=>$this->db->fetchAll("SELECT code,group_code,group_name,capacity_kg,gas_kg,customer_id FROM v_cylinder_status WHERE location='CUSTOMER' AND active=1 ORDER BY customer_id,code"),
        'sales'=>$this->db->fetchAll("SELECT s.doc_no,s.txn_date,p.code customer_code,p.name customer_name,s.issue_total,s.cylinder_sale_total,s.return_total,s.net_amount,s.received_amount,s.balance_after,s.status FROM sales s JOIN parties p ON p.id=s.customer_id WHERE s.txn_date BETWEEN :f AND :t ORDER BY s.txn_date DESC,s.id DESC",['f'=>$from,'t'=>$to]),
        'receipts'=>$this->db->fetchAll("SELECT r.doc_no,r.receipt_date,p.code party_code,p.name party_name,r.amount,r.method,r.source,r.status FROM receipts r JOIN parties p ON p.id=r.party_id WHERE r.receipt_date BETWEEN :f AND :t ORDER BY r.receipt_date DESC,r.id DESC",['f'=>$from,'t'=>$to]),
        'payments'=>$this->db->fetchAll("SELECT r.doc_no,r.payment_date,p.code party_code,p.name party_name,r.amount,r.method,r.source,r.status FROM payments r JOIN parties p ON p.id=r.party_id WHERE r.payment_date BETWEEN :f AND :t ORDER BY r.payment_date DESC,r.id DESC",['f'=>$from,'t'=>$to]),
        'expenses'=>$this->db->fetchAll("SELECT e.expense_date,c.name category_name,e.amount,e.method,e.reference_no,e.payee,e.status FROM expenses e JOIN expense_categories c ON c.id=e.category_id WHERE e.expense_date BETWEEN :f AND :t ORDER BY e.expense_date DESC,e.id DESC",['f'=>$from,'t'=>$to]),
        'cash'=>$this->db->fetchAll("SELECT cs.id,cs.counter_id,c.name counter_name,cs.opened_at,cs.closed_at,cs.opening_cash,cs.counted_cash,cs.expected_cash,cs.variance,COALESCE(SUM(CASE WHEN ce.direction='IN' THEN ce.amount ELSE 0 END),0) cash_in,COALESCE(SUM(CASE WHEN ce.direction='OUT' THEN ce.amount ELSE 0 END),0) cash_out FROM counter_sessions cs JOIN counters c ON c.id=cs.counter_id LEFT JOIN cash_entries ce ON ce.session_id=cs.id WHERE DATE(cs.opened_at) BETWEEN :f AND :t GROUP BY cs.id,c.name ORDER BY cs.id DESC",['f'=>$from,'t'=>$to]),
        default=>throw new \InvalidArgumentException('Unknown report.')
      };
    }
    public function data():Response{try{$q=$this->request->query();$type=(string)($q['type']??'stock');$from=(string)($q['from']??date('Y-m-d'));$to=(string)($q['to']??date('Y-m-d'));return Response::json(['ok'=>true,'data'=>['rows'=>$this->rows($type,$from,$to)]]);}catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}}
    public function csv():Response{
      $q=$this->request->query();$type=(string)($q['type']??'stock');$from=(string)($q['from']??date('Y-m-d'));$to=(string)($q['to']??date('Y-m-d'));
      $rows=$this->rows($type,$from,$to);if($rows===[])return Response::binary("No data\n",'text/csv; charset=UTF-8');
      $fh=fopen('php://temp','r+');fputcsv($fh,array_keys($rows[0]));foreach($rows as $row)fputcsv($fh,$row);rewind($fh);$body=stream_get_contents($fh);fclose($fh);return Response::binary((string)$body,'text/csv; charset=UTF-8');
    }
}
