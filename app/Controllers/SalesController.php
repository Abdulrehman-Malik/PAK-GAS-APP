<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\PosService;

final class SalesController
{
    public function __construct(
        private readonly DB $db,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly PosService $pos
    ) {
    }

    public function index(): Response
    {
        return View::render('sales/index', [
            'pageTitle' => 'Sales History',
            'user' => $this->auth->user(),
            '_base_path' => base_path(),
        ]);
    }

    public function data(): Response
    {
        $q = $this->request->query();
        $from = (string) ($q['from'] ?? '');
        $to = (string) ($q['to'] ?? '');
        $customer = (int) ($q['customer_id'] ?? 0);
        $status = strtoupper((string) ($q['status'] ?? ''));
        $search = trim((string) ($q['search'] ?? ''));
        $limit = min(100, max(10, (int) ($q['limit'] ?? 25)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];
        if ($from !== '') {
            $where[] = 's.txn_date >= :from';
            $params['from'] = $from;
        }
        if ($to !== '') {
            $where[] = 's.txn_date <= :to';
            $params['to'] = $to;
        }
        if ($customer > 0) {
            $where[] = 's.customer_id = :customer';
            $params['customer'] = $customer;
        }
        if (in_array($status, ['POSTED', 'VOID'], true)) {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($search !== '') {
            $where[] = '(s.doc_no LIKE :search OR p.code LIKE :search OR p.name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $condition = implode(' AND ', $where);
        $total = (int) (($this->db->fetchOne(
            "SELECT COUNT(*) AS total
             FROM sales s
             INNER JOIN parties p ON p.id = s.customer_id
             WHERE {$condition}",
            $params
        )['total'] ?? 0));

        $rows = $this->db->fetchAll(
            "SELECT s.id, s.doc_no, s.txn_date, p.code AS customer_code, p.name AS customer_name,
                    s.issue_total, s.cylinder_sale_total, s.return_total, s.net_amount,
                    s.received_amount, s.balance_after, s.status, u.full_name AS user_name,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id = s.id) AS line_count,
                    (SELECT COUNT(*) FROM sale_lines sl WHERE sl.sale_id = s.id
                        AND sl.line_type IN ('SELL_EMPTY','SELL_FILLED')) AS cylinders_sold
             FROM sales s
             INNER JOIN parties p ON p.id = s.customer_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE {$condition}
             ORDER BY s.id DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return Response::json(['ok' => true, 'data' => ['rows' => $rows, 'total' => $total]]);
    }

    public function detail(int $id): Response
    {
        $sale = $this->db->fetchOne(
            'SELECT s.*, p.code AS customer_code, p.name AS customer_name, u.full_name AS user_name
             FROM sales s
             INNER JOIN parties p ON p.id = s.customer_id
             LEFT JOIN users u ON u.id = s.created_by
             WHERE s.id = :id',
            ['id' => $id]
        );
        if (!$sale) {
            return Response::json(['ok' => false, 'message' => 'Sale not found.'], 404);
        }

        $lines = $this->db->fetchAll(
            "SELECT sl.*, c.code, cg.name AS group_name
             FROM sale_lines sl
             INNER JOIN cylinders c ON c.id = sl.cylinder_id
             INNER JOIN cylinder_groups cg ON cg.id = c.group_id
             WHERE sl.sale_id = :sale
             ORDER BY sl.id",
            ['sale' => $id]
        );

        $receipt = $this->db->fetchOne(
            'SELECT id, doc_no, amount, method, status, source FROM receipts WHERE sale_id = :sale ORDER BY id DESC LIMIT 1',
            ['sale' => $id]
        );

        return Response::json(['ok' => true, 'data' => [
            'sale' => $sale,
            'lines' => $lines,
            'receipt' => $receipt,
        ]]);
    }

    public function void(int $id): Response
    {
        try {
            $input = $this->request->input();
            $this->pos->void($id, (int) $this->auth->user()['id'], (string) ($input['reason'] ?? ''));
            return Response::json(['ok' => true, 'message' => 'Sale voided.']);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
