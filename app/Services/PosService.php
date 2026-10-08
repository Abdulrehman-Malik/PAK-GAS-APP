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
        private readonly StockService $stock,
        private readonly AuditService $audit
    ) {
    }

    public function post(array $input, int $userId): array
    {
        $date = (string) ($input['txn_date'] ?? '');
        $customerId = (int) ($input['customer_id'] ?? 0);
        $counterId = (int) ($input['counter_id'] ?? 0);
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $received = (string) ($input['received_amount'] ?? '0.00');
        $lines = $input['lines'] ?? [];
        $txnType = (string) ($input['transaction_type'] ?? 'GAS_SALE');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Transaction date is invalid.');
        }

        if (!in_array($txnType, ['GAS_SALE', 'EMPTY_CYLINDER_SALE'], true)) {
            throw new \InvalidArgumentException('Transaction type is not supported.');
        }

        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Payment method is invalid.');
        }

        if (bccomp($received, '0.00', 2) < 0) {
            throw new \InvalidArgumentException('Received amount cannot be negative.');
        }

        if ($customerId < 1 || !is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('Customer and at least one cylinder are required.');
        }

        return $this->db->transaction(function () use (
            $date,
            $customerId,
            $counterId,
            $method,
            $received,
            $lines,
            $userId,
            $txnType
        ): array {
            $party = $this->db->fetchOne(
                "SELECT *
                 FROM parties
                 WHERE id = :id AND party_type = 'CUSTOMER' AND active = 1
                 FOR UPDATE",
                ['id' => $customerId]
            );

            if (!$party) {
                throw new \InvalidArgumentException('Customer is invalid or inactive.');
            }

            $issue = '0.00';
            $sold = '0.00';
            $returned = '0.00';
            $saleLines = [];

            foreach ($lines as $line) {
                if (!is_array($line)) {
                    throw new \InvalidArgumentException('Invalid sale line.');
                }

                $cid = (int) ($line['cylinder_id'] ?? 0);
                $type = (string) ($line['type'] ?? 'ISSUE');
                $rate = (string) ($line['rate'] ?? '0');
                $gas = (string) ($line['gas_kg'] ?? '0');
                $cprice = (string) ($line['cylinder_price'] ?? '0');

                if ($txnType === 'EMPTY_CYLINDER_SALE') {
                    $type = 'SELL_EMPTY';
                    $gas = '0.000';
                } elseif (!in_array($type, ['ISSUE', 'SELL_FILLED'], true)) {
                    throw new \InvalidArgumentException('Invalid line type for Gas Sale.');
                }

                $cylinder = $this->db->fetchOne(
                    'SELECT c.*, cg.capacity_kg
                     FROM cylinders c
                     INNER JOIN cylinder_groups cg ON cg.id = c.group_id
                     WHERE c.id = :id AND c.active = 1
                     FOR UPDATE',
                    ['id' => $cid]
                );

                if (!$cylinder || $cylinder['condition_code'] !== 'GOOD') {
                    throw new \InvalidArgumentException('Cylinder is unavailable.');
                }

                $before = (string) $cylinder['gas_kg'];
                $capacity = (string) $cylinder['capacity_kg'];

                $resolved = $this->rates->resolve((int) $cylinder['group_id'], $date);

                if (bccomp($rate, '0.00', 2) <= 0) {
                    $rate = (string) $resolved['gas_rate'];
                }

                if (bccomp($cprice, '0.00', 2) <= 0) {
                    $cprice = (string) $resolved['cylinder_price'];
                }

                if (bccomp($gas, '0.000', 3) < 0 || bccomp($gas, $capacity, 3) > 0) {
                    throw new \InvalidArgumentException('Gas quantity exceeds cylinder capacity.');
                }

                if (in_array($type, ['ISSUE', 'SELL_FILLED'], true) && bccomp($rate, '0.00', 2) <= 0) {
                    throw new \InvalidArgumentException('Gas rate must be greater than zero.');
                }

                if (in_array($type, ['SELL_FILLED', 'SELL_EMPTY'], true) && bccomp($cprice, '0.00', 2) <= 0) {
                    throw new \InvalidArgumentException('Cylinder price must be greater than zero.');
                }

                if ($type === 'ISSUE') {
                    if ($cylinder['location'] !== 'SHOP') {
                        throw new \InvalidArgumentException('Cylinder ' . $cylinder['code'] . ' is no longer available.');
                    }

                    if (bccomp($gas, $before, 3) > 0) {
                        throw new \InvalidArgumentException(
                            'Gas quantity for ' . $cylinder['code'] . ' cannot exceed available gas of ' . $before . ' kg.'
                        );
                    }

                    if (bccomp($gas, '0.000', 3) <= 0) {
                        throw new \InvalidArgumentException('Gas quantity must be greater than zero.');
                    }

                    $amount = bcmul($gas, $rate, 2);
                    $issue = bcadd($issue, $amount, 2);
                    $to = 'CUSTOMER';
                    $after = bcsub($before, $gas, 3);
                } elseif ($type === 'SELL_FILLED') {
                    if ($cylinder['location'] !== 'SHOP') {
                        throw new \InvalidArgumentException('Cylinder ' . $cylinder['code'] . ' is no longer available.');
                    }

                    if (bccomp($before, '0.000', 3) <= 0) {
                        throw new \InvalidArgumentException('Only a cylinder containing gas can be sold as Filled.');
                    }

                    // A filled-cylinder sale transfers the physical cylinder and all gas in it.
                    // Allowing a smaller gas quantity would silently destroy the remaining gas.
                    if (bccomp($gas, $before, 3) !== 0) {
                        throw new \InvalidArgumentException(
                            'Filled-cylinder sale must sell the full available gas of ' . $before . ' kg.'
                        );
                    }

                    $amount = bcadd(bcmul($gas, $rate, 2), $cprice, 2);
                    $sold = bcadd($sold, $amount, 2);
                    $to = 'SOLD';
                    $after = '0.000';
                } elseif ($type === 'SELL_EMPTY') {
                    if ($cylinder['location'] !== 'SHOP' || bccomp($before, '0.000', 3) !== 0) {
                        throw new \InvalidArgumentException('Only an empty shop cylinder can be sold as Empty.');
                    }

                    $amount = $cprice;
                    $sold = bcadd($sold, $amount, 2);
                    $to = 'SOLD';
                    $after = '0.000';
                    $gas = '0.000';
                } elseif ($type === 'RETURN') {
                    if (
                        $cylinder['location'] !== 'CUSTOMER'
                        || (int) $cylinder['customer_id'] !== $customerId
                    ) {
                        throw new \InvalidArgumentException('Cylinder is not held by this customer.');
                    }

                    if (bccomp($gas, $capacity, 3) > 0 || bccomp($gas, '0.000', 3) < 0) {
                        throw new \InvalidArgumentException('Returned gas is outside the cylinder capacity.');
                    }

                    $credit = bcmul($gas, $rate, 2);
                    $amount = bcsub('0.00', $credit, 2);
                    $returned = bcadd($returned, $credit, 2);
                    $to = 'SHOP';
                    $after = $gas;
                } else {
                    throw new \InvalidArgumentException('Unsupported sale line.');
                }

                $saleLines[] = [
                    'type' => $type,
                    'cid' => $cid,
                    'gas' => $gas,
                    'rate' => $rate,
                    'cp' => $cprice,
                    'amount' => $amount,
                    'before' => $before,
                    'after' => $after,
                    'from' => $cylinder['location'],
                    'to' => $to,
                    'customer' => $to === 'CUSTOMER' ? $customerId : null,
                ];
            }

            $net = bcsub(bcadd($issue, $sold, 2), $returned, 2);
            $oldBalance = $this->ledger->balance($customerId);
            $newBalance = bcadd($oldBalance, bcsub($net, $received, 2), 2);

            $creditSetting = $this->db->fetchOne(
                "SELECT setting_value
                 FROM settings
                 WHERE setting_group = 'sales_credit' AND setting_key = 'credit_enforcement'"
            );
            $enforcement = strtoupper((string) ($creditSetting['setting_value'] ?? 'BLOCK'));

            if ($enforcement === 'BLOCK' && bccomp($newBalance, '0.00', 2) > 0) {
                $allowCredit = (int) $party['allow_credit'];
                $creditLimit = (string) $party['credit_limit'];

                if (!$allowCredit || bccomp($newBalance, $creditLimit, 2) > 0) {
                    throw new \InvalidArgumentException(
                        'Credit limit exceeded. Current balance would be ' . $newBalance . '.'
                    );
                }
            }

            if ($received !== '0.00' && $method === 'CASH' && $counterId < 1) {
                throw new \InvalidArgumentException('A cash counter is required for cash payments.');
            }

            $doc = $this->docs->next('SALE');

            $this->db->execute(
                'INSERT INTO sales
                 (doc_no, txn_date, customer_id, counter_id, issue_total, cylinder_sale_total, return_total,
                  net_amount, received_amount, balance_after, created_by)
                 VALUES (:doc, :date, :customer, :counter, :issue, :sold, :returned, :net, :received, :balance, :user)',
                [
                    'doc' => $doc,
                    'date' => $date,
                    'customer' => $customerId,
                    'counter' => $counterId > 0 ? $counterId : null,
                    'issue' => $issue,
                    'sold' => $sold,
                    'returned' => $returned,
                    'net' => $net,
                    'received' => $received,
                    'balance' => $newBalance,
                    'user' => $userId,
                ]
            );

            $saleId = (int) $this->db->pdo()->lastInsertId();

            foreach ($saleLines as $line) {
                $this->db->execute(
                    'INSERT INTO sale_lines
                     (sale_id, line_type, cylinder_id, gas_kg, rate, cylinder_price, amount)
                     VALUES (:sale, :type, :cylinder, :gas, :rate, :price, :amount)',
                    [
                        'sale' => $saleId,
                        'type' => $line['type'],
                        'cylinder' => $line['cid'],
                        'gas' => $line['gas'],
                        'rate' => $line['rate'],
                        'price' => $line['cp'],
                        'amount' => $line['amount'],
                    ]
                );

                $this->stock->moveCylinder(
                    $line['cid'],
                    $line['to'],
                    $line['customer'],
                    $line['after'],
                    $line['type'] === 'RETURN'
                        ? 'RETURN'
                        : ($line['type'] === 'ISSUE' ? 'ISSUE' : 'SALE_OUT'),
                    $line['rate'],
                    'SALE',
                    $saleId,
                    $userId
                );
            }

            if (bccomp($net, '0.00', 2) > 0) {
                $this->ledger->post(
                    $customerId,
                    $date,
                    'SALE',
                    $saleId,
                    $net,
                    '0.00',
                    'Sale ' . $doc,
                    $userId
                );
            }

            if (bccomp($returned, '0.00', 2) > 0) {
                $this->ledger->post(
                    $customerId,
                    $date,
                    'SALE_RETURN',
                    $saleId,
                    '0.00',
                    $returned,
                    'Return credit ' . $doc,
                    $userId
                );
            }

            if (bccomp($received, '0.00', 2) > 0) {
                $receipt = $this->docs->next('RECEIPT');

                $this->db->execute(
                    'INSERT INTO receipts
                     (doc_no, receipt_date, party_id, amount, method, counter_id, source, sale_id, created_by)
                     VALUES (:doc, :date, :party, :amount, :method, :counter, \'POS\', :sale, :user)',
                    [
                        'doc' => $receipt,
                        'date' => $date,
                        'party' => $customerId,
                        'amount' => $received,
                        'method' => $method,
                        'counter' => $counterId > 0 ? $counterId : null,
                        'sale' => $saleId,
                        'user' => $userId,
                    ]
                );

                $receiptId = (int) $this->db->pdo()->lastInsertId();

                $this->ledger->post(
                    $customerId,
                    $date,
                    'RECEIPT',
                    $receiptId,
                    '0.00',
                    $received,
                    'Receipt ' . $receipt,
                    $userId
                );

                if ($method === 'CASH') {
                    $this->cash->post(
                        $counterId,
                        $date,
                        'IN',
                        $received,
                        'RECEIPT',
                        $receiptId,
                        $userId
                    );
                }
            }

            $this->audit->record($userId, 'CREATE', 'sales', $saleId, null, [
                'doc_no' => $doc,
                'transaction_type' => $txnType,
                'net_amount' => $net,
                'received_amount' => $received,
            ], null);

            return [
                'id' => $saleId,
                'doc_no' => $doc,
                'net_amount' => $net,
                'received_amount' => $received,
                'balance_after' => $newBalance,
            ];
        });
    }
}
