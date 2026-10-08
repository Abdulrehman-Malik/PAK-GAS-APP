<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\ChequeService;

final class ChequeController
{
    public function __construct(private readonly DB $db, private readonly Auth $auth, private readonly Request $request, private readonly ChequeService $service) {}
    public function index(): Response
    {
        return View::render('cheques/index',['pageTitle'=>'Cheque Register','user'=>$this->auth->user()]);
    }
    public function data(): Response
    {
        $rows=$this->db->fetchAll("SELECT c.*,p.code party_code,p.name party_name FROM cheques c JOIN parties p ON p.id=c.party_id ORDER BY c.cheque_date,c.id DESC LIMIT 500");
        return Response::json(['ok'=>true,'data'=>['rows'=>$rows]]);
    }
    public function clear(int $id): Response
    {
        try{$this->service->clear($id,(string)($this->request->input()['cleared_date']??date('Y-m-d')),(int)$this->auth->user()['id']);return Response::json(['ok'=>true,'message'=>'Cheque cleared.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
    public function bounce(int $id): Response
    {
        try{$this->service->bounce($id,(int)$this->auth->user()['id'],(string)($this->request->input()['reason']??''));return Response::json(['ok'=>true,'message'=>'Cheque marked bounced.']);}
        catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }
}
