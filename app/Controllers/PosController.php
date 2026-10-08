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
        $config = \App\Core\Config::load(base_path());
        $today = (new DateTimeImmutable('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');

        return View::render('pos/index', [
            'pageTitle' => 'Point of Sale',
            'user' => $this->auth->user(),
            'today' => $today,
            '_base_path' => base_path(),
        ]);
    }

    public function config(): Response
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value
             FROM settings
             WHERE setting_group = 'sales'
               AND setting_key IN ('pos_transaction_types', 'pos_default_transaction_type')"
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        $types = array_values(array_filter(
            array_map('trim', explode(',', $values['pos_transaction_types'] ?? 'GAS_SALE,EMPTY_CYLINDER_SALE'))
        ));
        $defaultType = $values['pos_default_transaction_type'] ?? ($types[0] ?? 'GAS_SALE');

        if (!in_array($defaultType, $types, true)) {
            $defaultType = $types[0] ?? 'GAS_SALE';
        }

        return Response::json([
            'ok' => true,
            'data' => [
                'transaction_types' => $types,
                'default_transaction_type' => $defaultType,
            ],
        ]);
    }

    public function cylinders(): Response
    {
        $query = $this->request->query();
        $groupId = (int) ($query['group_id'] ?? 0);
        $transactionType = (string) ($query['transaction_type'] ?? 'GAS_SALE');
        $txnDate = (string) ($query['txn_date'] ?? date('Y-m-d'));

        $where = [
            "c.active = 1",
            "c.condition_code = 'GOOD'",
            "c.location = 'SHOP'",
        ];
        $params = [];

        if ($groupId > 0) {
            $where[] = 'c.group_id = :group_id';
            $params['group_id'] = $groupId;
        }

        if ($transactionType === 'EMPTY_CYLINDER_SALE') {
            $where[] = 'c.gas_kg = 0';
        }

        $rows = $this->db->fetchAll(
            'SELECT c.id, c.code, c.group_id, c.gas_kg, c.location, c.customer_id,
                    c.condition_code, cg.code AS group_code, cg.name AS group_name, cg.capacity_kg
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id = c.group_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.code',
            $params
        );

        foreach ($rows as &$row) {
            $rate = null;
            try {
                $rate = $this->rates->resolve((int) $row['group_id'], $txnDate);
            } catch (\Throwable) {
                // Missing rate is reported to the POS row so the user can correct configuration
                // before posting; the server still validates the rate during posting.
            }

            $row['gas_rate'] = $rate['gas_rate'] ?? '';
            $row['cylinder_price'] = $rate['cylinder_price'] ?? '';
        }
        unset($row);

        return Response::json(['ok' => true, 'data' => $rows]);
    }

    public function issued(int $customerId): Response
    {
        if ($customerId < 1) {
            return Response::json(['ok' => true, 'data' => []]);
        }

        try {
            $this->auth->require('sales.create');
            return Response::json(['ok' => true, 'data' => $this->pos->issuedToCustomer($customerId)]);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function customers(): Response
    {
        $q = trim((string) ($this->request->query()['q'] ?? ''));
        $rows = $this->db->fetchAll(
            "SELECT id, code, name, allow_credit, credit_limit, opening_balance
             FROM parties
             WHERE party_type = 'CUSTOMER'
               AND active = 1
               AND (name LIKE :q OR code LIKE :q OR phone LIKE :q)
             ORDER BY name
             LIMIT 30",
            ['q' => '%' . $q . '%']
        );

        return Response::json(['ok' => true, 'data' => $rows]);
    }

    public function store(): Response
    {
        $data = $this->request->input();
        $user = $this->auth->user();

        try {
            $lines = json_decode(
                (string) ($data['lines_json'] ?? '[]'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $result = $this->pos->post(
                [
                    'txn_date' => (string) ($data['txn_date'] ?? date('Y-m-d')),
                    'transaction_type' => (string) ($data['transaction_type'] ?? 'GAS_SALE'),
                    'customer_id' => (int) ($data['customer_id'] ?? 0),
                    'counter_id' => (int) ($data['counter_id'] ?? ($user['default_counter_id'] ?? 0)),
                    'method' => (string) ($data['method'] ?? 'CASH'),
                    'received_amount' => (string) ($data['received_amount'] ?? '0.00'),
                    'lines' => $lines,
                ],
                (int) $user['id']
            );

            return Response::json(['ok' => true, 'data' => $result, 'message' => 'Sale posted']);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
