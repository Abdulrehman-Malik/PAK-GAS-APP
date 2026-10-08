<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class AuditController
{
    public function __construct(private readonly DB $db, private readonly Auth $auth, private readonly Request $request) {}
    public function index(): Response
    {
        return View::render('audit/index',['pageTitle'=>'Audit Log','user'=>$this->auth->user()]);
    }
    public function data(): Response
    {
        $q=$this->request->query();
        $from=(string)($q['from']??date('Y-m-d'));
        $to=(string)($q['to']??date('Y-m-d'));
        $rows=$this->db->fetchAll(
            "SELECT a.id,a.created_at,a.action,a.entity,a.entity_id,a.ip,u.username,u.full_name,a.old_json,a.new_json
             FROM audit_log a LEFT JOIN users u ON u.id=a.user_id
             WHERE DATE(a.created_at) BETWEEN :from AND :to
             ORDER BY a.id DESC LIMIT 500",
            ['from'=>$from,'to'=>$to]
        );
        return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);
    }
}
