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
        if (!in_array($txnType, ['GAS_SALE', 'EMPTY_CYLINDER_SALE', 'CYLINDER_RETURN'], true)) {
            throw new \InvalidArgumentException('Transaction type is not supported.');
        }
        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Payment method is invalid.');
        }
        if (bccomp($received, '0.00', 2) < 0) {
            throw new \InvalidArgumentException('Received amount cannot be negative.');
        }
        if ($customerId < 1 || !is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('Customer and at least one line are required.');
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
            $soldGas = '0.00';
            $returned = '0.00';
            $saleLines = [];
            $rateEditSetting = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='sales_credit' AND setting_key='rate_edit'");
            $rateEditAllowed = filter_var((string)($rateEditSetting['setting_value'] ?? '1'), FILTER_VALIDATE_BOOL);


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
                } elseif ($txnType === 'CYLINDER_RETURN') {
                    $type = 'RETURN';
                } elseif (!in_array($type, ['ISSUE', 'SELL_FILLED', 'RETURN'], true)) {
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

                if ($type === 'RETURN') {
                    if ($cylinder['location'] !== 'CUSTOMER' || (int) $cylinder['customer_id'] !== $customerId) {
                        throw new \InvalidArgumentException('Cylinder ' . $cylinder['code'] . ' is not held by this customer.');
                    }
                    if (bccomp($gas, '0.000', 3) < 0 || bccomp($gas, $capacity, 3) > 0) {
                        throw new \InvalidArgumentException('Returned gas is outside the cylinder capacity.');
                    }
                    if (bccomp($rate, '0.00', 2) <= 0) {
                        $rate = $this->issueRateForCylinder($cid, $date);
                    }
                    if (bccomp($rate, '0.00', 2) <= 0) {
                        throw new \InvalidArgumentException('Return rate must be greater than zero.');
                    }

                    $credit = bcmul($gas, $rate, 2);
                    $amount = bcsub('0.00', $credit, 2);
                    $returned = bcadd($returned, $credit, 2);
                    $to = 'SHOP';
                    $after = $gas;
                    $cprice = '0.00';
                } else {
                    $resolved = $this->rates->resolve((int) $cylinder['group_id'], $date);
                    $resolvedRate = (string) $resolved['gas_rate'];
                    $resolvedCylinderPrice = (string) $resolved['cylinder_price'];
                    if (bccomp($rate, '0.00', 2) <= 0) {
                        $rate = $resolvedRate;
                    } elseif (!$rateEditAllowed && bccomp($rate, $resolvedRate, 2) !== 0) {
                        throw new \\InvalidArgumentException('Rate editing is disabled for POS.');
                    }
                    if (bccomp($cprice, '0.00', 2) <= 0) {
                        $cprice = $resolvedCylinderPrice;
                    } elseif (!$rateEditAllowed && bccomp($cprice, $resolvedCylinderPrice, 2) !== 0) {
                        throw new \\InvalidArgumentException('Cylinder price editing is disabled for POS.');
                    }

                    if (bccomp($gas, '0.000', 3) < 0 || bccomp($gas, $capacity, 3) > 0) {
                        throw new \InvalidArgumentException('Gas quantity exceeds cylinder capacity.');
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
                        if (bccomp($gas, '0.000', 3) <= 0 || bccomp($rate, '0.00', 2) <= 0) {
                            throw new \InvalidArgumentException('Gas quantity and rate must be greater than zero.');
                        }
                        $amount = bcmul($gas, $rate, 2);
                        $issue = bcadd($issue, $amount, 2);
                        $to = 'CUSTOMER';
                        $after = bcsub($before, $gas, 3);
                    } elseif ($type === 'SELL_FILLED') {
                        if ($cylinder['location'] !== 'SHOP') {
                            throw new \InvalidArgumentException('Cylinder ' . $cylinder['code'] . ' is no longer available.');
                        }
                        if (bccomp($before, '0.000', 3) <= 0 || bccomp($gas, $before, 3) !== 0) {
                            throw new \InvalidArgumentException(
                                'Filled-cylinder sale must sell the full available gas of ' . $before . ' kg.'
                            );
                        }
                        if (bccomp($rate, '0.00', 2) <= 0 || bccomp($cprice, '0.00', 2) <= 0) {
                            throw new \InvalidArgumentException('Gas rate and cylinder price must be greater than zero.');
                        }
                        $gasAmount = bcmul($gas, $rate, 2);
                        $amount = bcadd($gasAmount, $cprice, 2);
                        $sold = bcadd($sold, $amount, 2);
                        $soldGas = bcadd($soldGas, $gasAmount, 2);
                        $to = 'SOLD';
                        $after = '0.000';
                    } elseif ($type === 'SELL_EMPTY') {
                        if ($cylinder['location'] !== 'SHOP' || bccomp($before, '0.000', 3) !== 0) {
                            throw new \InvalidArgumentException('Only an empty shop cylinder can be sold as Empty.');
                        }
                        if (bccomp($cprice, '0.00', 2) <= 0) {
                            throw new \InvalidArgumentException('Cylinder price must be greater than zero.');
                        }
                        $amount = $cprice;
                        $sold = bcadd($sold, $amount, 2);
                        $to = 'SOLD';
                        $after = '0.000';
                        $gas = '0.000';
                    } else {
                        throw new \InvalidArgumentException('Unsupported sale line.');
                    }
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

            $taxSetting = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='tax' AND setting_key='enabled'");
            $taxEnabled = filter_var((string)($taxSetting['setting_value'] ?? '0'), FILTER_VALIDATE_BOOL);
            $taxRateRow = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='tax' AND setting_key='rate_percent'");
            $taxRate = (string)($taxRateRow['setting_value'] ?? '0.00');
            $taxBase = bcadd($issue, $soldGas, 2);
            $tax = $taxEnabled ? bcdiv(bcmul($taxBase, $taxRate, 4), '100.00', 2) : '0.00';
            $net = bcadd(bcsub(bcadd($issue, $sold, 2), $returned, 2), $tax, 2);
            $oldBalance = $this->ledger->balance($customerId);
            $newBalance = bcadd($oldBalance, bcsub($net, $received, 2), 2);

            $creditSetting = $this->db->fetchOne(
                "SELECT setting_value FROM settings
                 WHERE setting_group = 'sales_credit' AND setting_key IN ('credit_limit_enforcement','credit_enforcement')
                 ORDER BY setting_key = 'credit_limit_enforcement' DESC LIMIT 1"
            );
            $enforcement = strtoupper((string) ($creditSetting['setting_value'] ?? 'BLOCK'));

            $advanceSetting = $this->db->fetchOne("SELECT setting_value FROM settings WHERE setting_group='sales_credit' AND setting_key='allow_advance'");
            $allowAdvance = filter_var((string)($advanceSetting['setting_value'] ?? '1'), FILTER_VALIDATE_BOOL);
            if (!$allowAdvance && bccomp($newBalance, '0.00', 2) < 0) {
                throw new \\InvalidArgumentException('Advance balance is disabled for this shop.');
            }

            if ($enforcement === 'BLOCK' && bccomp($newBalance, '0.00', 2) > 0) {
                $allowCredit = (int) $party['allow_credit'];
                $creditLimit = (string) $party['credit_limit'];
                if (!$allowCredit || bccomp($newBalance, $creditLimit, 2) > 0) {
                    throw new \InvalidArgumentException(
                        'Credit limit exceeded. Current balance would be ' . $newBalance . '.'
                    );
                }
            }

            if (bccomp($received, '0.00', 2) > 0 && $method === 'CASH' && $counterId < 1) {
                throw new \InvalidArgumentException('A cash counter is required for cash payments.');
            }

            $doc = $this->docs->next('SALE');
            $this->db->execute(
                'INSERT INTO sales
                 (doc_no, txn_date, customer_id, counter_id, issue_total, cylinder_sale_total, return_total,
                  tax_amount, net_amount, received_amount, balance_after, created_by)
                 VALUES (:doc, :date, :customer, :counter, :issue, :sold, :returned, :tax, :net, :received, :balance, :user)',
                [
                    'doc' => $doc,
                    'date' => $date,
                    'customer' => $customerId,
                    'counter' => $counterId > 0 ? $counterId : null,
                    'issue' => $issue,
                    'sold' => $sold,
                    'returned' => $returned,
                    'tax' => $tax,
                    'net' => $net,
                    'received' => $received,
                    'balance' => $newBalance,
                    'user' => $userId,
                ]
            );
            $saleId = $this->db->lastInsertId();

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

            if (bccomp($issue, '0.00', 2) > 0) {
                $this->ledger->post($customerId, $date, 'SALE', $saleId, $issue, '0.00', 'Gas sale ' . $doc, $userId);
            }
            if (bccomp($sold, '0.00', 2) > 0) {
                $this->ledger->post($customerId, $date, 'SALE', $saleId, $sold, '0.00', 'Cylinder sale ' . $doc, $userId);
            }
            if (bccomp($returned, '0.00', 2) > 0) {
                $this->ledger->post($customerId, $date, 'SALE_RETURN', $saleId, '0.00', $returned, 'Return credit ' . $doc, $userId);
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
                $receiptId = $this->db->lastInsertId();

                $postingModeRow = $this->db->fetchOne(
                    "SELECT setting_key, setting_value FROM settings
                     WHERE setting_group='cheques' AND setting_key IN ('cheque_ledger_posting','posting_mode')
                     ORDER BY setting_key='cheque_ledger_posting' DESC LIMIT 1"
                );
                $postingMode = strtoupper((string)($postingModeRow['setting_value'] ?? 'ON_CLEARANCE'));

                if ($method === 'CHEQUE') {
                    $cheque = $input['cheque'] ?? [];
                    $chequeNo = trim((string)($cheque['cheque_no'] ?? ''));
                    $chequeDate = (string)($cheque['cheque_date'] ?? '');
                    if ($chequeNo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chequeDate)) {
                        throw new \\InvalidArgumentException('Cheque number and cheque date are required.');
                    }
                    $this->db->execute(
                        'INSERT INTO cheques
                         (direction, party_id, cheque_no, bank, cheque_date, amount, status, receipt_id, created_by)
                         VALUES (\'IN\', :party, :no, :bank, :date, :amount, :status, :receipt, :user)',
                        [
                            'party' => $customerId,
                            'no' => strtoupper($chequeNo),
                            'bank' => trim((string)($cheque['bank'] ?? '')),
                            'date' => $chequeDate,
                            'amount' => $received,
                            'status' => $postingMode === 'ON_CLEARANCE' ? 'PENDING' : 'CLEARED',
                            'receipt' => $receiptId,
                            'user' => $userId,
                        ]
                    );
                }

                if ($method !== 'CHEQUE' || $postingMode !== 'ON_CLEARANCE') {
                    $this->ledger->post($customerId, $date, 'RECEIPT', $receiptId, '0.00', $received, 'Receipt ' . $receipt, $userId);
                }
                if ($method === 'CASH') {
                    $this->cash->post($counterId, $date, 'IN', $received, 'RECEIPT', $receiptId, $userId);
                }
            }

            $this->audit->record(
                $userId,
                'CREATE',
                'sales',
                $saleId,
                null,
                ['doc_no' => $doc, 'net_amount' => $net,
                'tax_amount' => $tax, 'received_amount' => $received],
                null
            );

            return [
                'id' => $saleId,
                'doc_no' => $doc,
                'net_amount' => $net,
                'received_amount' => $received,
                'balance_after' => $newBalance,
            ];
        });
    }

    public function void(int $saleId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Void reason is required.');
        }

        $this->db->transaction(function () use ($saleId, $userId, $reason): void {
            $sale = $this->db->fetchOne('SELECT * FROM sales WHERE id = :id FOR UPDATE', ['id' => $saleId]);
            if (!$sale) {
                throw new \InvalidArgumentException('Sale not found.');
            }
            if ($sale['status'] !== 'POSTED') {
                throw new \InvalidArgumentException('Sale is already void.');
            }

            $lines = $this->db->fetchAll(
                'SELECT sl.*, c.code
                 FROM sale_lines sl
                 INNER JOIN cylinders c ON c.id = sl.cylinder_id
                 WHERE sl.sale_id = :sale
                 ORDER BY sl.id
                 FOR UPDATE',
                ['sale' => $saleId]
            );

            foreach ($lines as $line) {
                $later = $this->db->fetchOne(
                    'SELECT m.id, m.movement_type
                     FROM cylinder_movements m
                     WHERE m.cylinder_id = :cylinder
                       AND m.id > (
                           SELECT MAX(m2.id)
                           FROM cylinder_movements m2
                           WHERE m2.source_document_type = \'SALE\'
                             AND m2.source_document_id = :sale
                             AND m2.cylinder_id = :cylinder2
                       )
                     ORDER BY m.id
                     LIMIT 1',
                    ['cylinder' => $line['cylinder_id'], 'sale' => $saleId, 'cylinder2' => $line['cylinder_id']]
                );
                if ($later) {
                    throw new \InvalidArgumentException(
                        'Cannot void sale: cylinder ' . $line['code'] .
                        ' has a later ' . $later['movement_type'] . ' movement.'
                    );
                }
            }

            foreach ($lines as $line) {
                $movement = $this->db->fetchOne(
                    'SELECT * FROM cylinder_movements
                     WHERE source_document_type = \'SALE\'
                       AND source_document_id = :sale
                       AND cylinder_id = :cylinder
                     ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    ['sale' => $saleId, 'cylinder' => $line['cylinder_id']]
                );
                if (!$movement) {
                    throw new \InvalidArgumentException('Sale movement is missing for cylinder ' . $line['code'] . '.');
                }

                if ($line['line_type'] === 'RETURN') {
                    $to = 'CUSTOMER';
                    $customerId = (int) $sale['customer_id'];
                    $after = (string) $movement['before_gas_kg'];
                } else {
                    $to = 'SHOP';
                    $customerId = null;
                    $after = (string) $movement['before_gas_kg'];
                }

                $this->stock->moveCylinder(
                    (int) $line['cylinder_id'],
                    $to,
                    $customerId,
                    $after,
                    'SALE_VOID',
                    (string) $line['rate'],
                    'SALE_VOID',
                    $saleId,
                    $userId
                );
            }

            foreach ($this->ledger->entriesForDocument('SALE', $saleId) as $entry) {
                $this->ledger->reverseEntry($entry, (string) $sale['txn_date'], $userId, 'Void sale ' . $sale['doc_no']);
            }
            foreach ($this->ledger->entriesForDocument('SALE_RETURN', $saleId) as $entry) {
                $this->ledger->reverseEntry($entry, (string) $sale['txn_date'], $userId, 'Void sale return ' . $sale['doc_no']);
            }

            $receipts = $this->db->fetchAll(
                'SELECT * FROM receipts WHERE sale_id = :sale AND status = \'POSTED\' FOR UPDATE',
                ['sale' => $saleId]
            );
            foreach ($receipts as $receipt) {
                foreach ($this->ledger->entriesForDocument('RECEIPT', (int) $receipt['id']) as $entry) {
                    $this->ledger->reverseEntry(
                        $entry,
                        (string) $receipt['receipt_date'],
                        $userId,
                        'Void POS receipt ' . $receipt['doc_no']
                    );
                }
                if ($receipt['method'] === 'CASH') {
                    $this->cash->reverseDocument('RECEIPT', (int) $receipt['id'], (string) $receipt['receipt_date'], $userId);
                }
                $this->db->execute(
                    'UPDATE receipts SET status = \'VOID\' WHERE id = :id',
                    ['id' => $receipt['id']]
                );
            }

            $this->db->execute(
                'UPDATE sales
                 SET status = \'VOID\', void_reason = :reason, voided_at = NOW(), voided_by = :user
                 WHERE id = :id',
                ['reason' => $reason, 'user' => $userId, 'id' => $saleId]
            );

            $this->audit->record(
                $userId,
                'VOID',
                'sales',
                $saleId,
                ['status' => 'POSTED'],
                ['status' => 'VOID', 'reason' => $reason],
                null
            );
        });
    }

    public function issuedToCustomer(int $customerId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.code, c.group_id, cg.code AS group_code, cg.name AS group_name,
                    cg.capacity_kg, c.gas_kg,
                    COALESCE((
                        SELECT m.rate
                        FROM cylinder_movements m
                        WHERE m.cylinder_id = c.id
                          AND m.movement_type = 'ISSUE'
                        ORDER BY m.id DESC LIMIT 1
                    ), 0) AS issue_rate,
                    COALESCE((
                        SELECT DATE(m.created_at)
                        FROM cylinder_movements m
                        WHERE m.cylinder_id = c.id
                          AND m.movement_type = 'ISSUE'
                        ORDER BY m.id DESC LIMIT 1
                    ), DATE(c.created_at)) AS issue_date
             FROM cylinders c
             INNER JOIN cylinder_groups cg ON cg.id = c.group_id
             WHERE c.active = 1 AND c.condition_code = 'GOOD'
               AND c.location = 'CUSTOMER' AND c.customer_id = :customer
             ORDER BY c.code",
            ['customer' => $customerId]
        );
    }

    private function issueRateForCylinder(int $cylinderId, string $date): string
    {
        $row = $this->db->fetchOne(
            "SELECT rate FROM cylinder_movements
             WHERE cylinder_id = :cylinder AND movement_type = 'ISSUE'
             ORDER BY id DESC LIMIT 1",
            ['cylinder' => $cylinderId]
        );

        if ($row && bccomp((string) $row['rate'], '0.00', 2) > 0) {
            return (string) $row['rate'];
        }

        return '0.00';
    }
}
