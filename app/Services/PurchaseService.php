<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class PurchaseService
{
    public function __construct(
        private readonly DB $db,
        private readonly DocNumberService $docs,
        private readonly LedgerService $ledger,
        private readonly CashService $cash,
        private readonly StockService $stock,
        private readonly CodeGenerator $codes
    ) {
    }

    public function post(array $input, int $userId): array
    {
        $date = (string) ($input['purchase_date'] ?? date('Y-m-d'));
        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $invoice = trim((string) ($input['supplier_invoice_no'] ?? ''));
        $paid = (string) ($input['paid_amount'] ?? '0.00');
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $counterId = (int) ($input['counter_id'] ?? 0);
        $lines = $input['lines'] ?? [];

        if ($supplierId < 1 || !is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('Supplier and at least one purchase line are required.');
        }
        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Payment method is invalid.');
        }
        if (bccomp($paid, '0.00', 2) < 0) {
            throw new \InvalidArgumentException('Paid amount cannot be negative.');
        }

        return $this->db->transaction(function () use (
            $date, $supplierId, $invoice, $paid, $method, $counterId, $lines, $userId, $input
        ): array {
            $supplier = $this->db->fetchOne(
                "SELECT * FROM parties
                 WHERE id = :id AND party_type = 'SUPPLIER' AND active = 1
                 FOR UPDATE",
                ['id' => $supplierId]
            );
            if (!$supplier) {
                throw new \InvalidArgumentException('Supplier is invalid or inactive.');
            }

            $lineRows = [];
            $total = '0.00';

            foreach ($lines as $line) {
                $type = strtoupper((string) ($line['line_type'] ?? ''));
                if ($type === 'GAS_FILL') {
                    $cylinderId = (int) ($line['cylinder_id'] ?? 0);
                    $gas = (string) ($line['gas_kg'] ?? '0');
                    $rate = (string) ($line['gas_rate'] ?? '0');

                    $cylinder = $this->db->fetchOne(
                        'SELECT c.*, cg.capacity_kg
                         FROM cylinders c
                         INNER JOIN cylinder_groups cg ON cg.id = c.group_id
                         WHERE c.id = :id
                         FOR UPDATE',
                        ['id' => $cylinderId]
                    );
                    if (!$cylinder || !(int) $cylinder['active']) {
                        throw new \InvalidArgumentException('Selected cylinder is unavailable.');
                    }
                    if ($cylinder['location'] !== 'SHOP' || $cylinder['condition_code'] !== 'GOOD') {
                        throw new \InvalidArgumentException('Only good shop cylinders can be refilled.');
                    }

                    $available = bcsub((string) $cylinder['capacity_kg'], (string) $cylinder['gas_kg'], 3);
                    if (bccomp($gas, '0.001', 3) < 0 || bccomp($gas, $available, 3) > 0) {
                        throw new \InvalidArgumentException(
                            'Gas added to ' . $cylinder['code'] . ' must be between 0.001 and ' . $available . ' kg.'
                        );
                    }
                    if (bccomp($rate, '0.00', 2) <= 0) {
                        throw new \InvalidArgumentException('Gas purchase rate must be greater than zero.');
                    }

                    $amount = bcmul($gas, $rate, 2);
                    $total = bcadd($total, $amount, 2);
                    $after = bcadd((string) $cylinder['gas_kg'], $gas, 3);

                    $lineRows[] = [
                        'line_type' => 'GAS_FILL',
                        'cylinder_id' => $cylinderId,
                        'group_id' => (int) $cylinder['group_id'],
                        'quantity' => 1,
                        'gas' => $gas,
                        'cylinder_cost' => '0.00',
                        'gas_rate' => $rate,
                        'amount' => $amount,
                        'before' => (string) $cylinder['gas_kg'],
                        'after' => $after,
                    ];
                    continue;
                }

                if ($type !== 'NEW_CYLINDERS') {
                    throw new \InvalidArgumentException('Unsupported purchase line type.');
                }

                $groupId = (int) ($line['group_id'] ?? 0);
                $quantity = (int) ($line['quantity'] ?? 0);
                $initialGas = (string) ($line['initial_gas_kg'] ?? '0');
                $cylinderCost = (string) ($line['cylinder_cost'] ?? '0');
                $gasRate = (string) ($line['gas_rate'] ?? '0');
                $condition = strtoupper((string) ($line['condition_code'] ?? 'GOOD'));
                $codeMode = strtoupper((string) ($line['code_mode'] ?? 'AUTO'));
                $manualCodes = $line['codes'] ?? [];

                if ($quantity < 1 || $quantity > 1000) {
                    throw new \InvalidArgumentException('New-cylinder quantity must be between 1 and 1000.');
                }

                $group = $this->db->fetchOne(
                    'SELECT * FROM cylinder_groups WHERE id = :id AND active = 1 FOR UPDATE',
                    ['id' => $groupId]
                );
                if (!$group) {
                    throw new \InvalidArgumentException('Cylinder group is invalid or inactive.');
                }
                if (bccomp($initialGas, '0.000', 3) < 0 || bccomp($initialGas, (string) $group['capacity_kg'], 3) > 0) {
                    throw new \InvalidArgumentException('Initial gas is outside cylinder capacity.');
                }
                if (bccomp($cylinderCost, '0.00', 2) < 0 || bccomp($gasRate, '0.00', 2) < 0) {
                    throw new \InvalidArgumentException('Purchase prices cannot be negative.');
                }
                if (bccomp($initialGas, '0.000', 3) > 0 && bccomp($gasRate, '0.00', 2) <= 0) {
                    throw new \InvalidArgumentException('Gas rate is required when initial gas is greater than zero.');
                }

                if (!in_array($codeMode, ['AUTO', 'MANUAL'], true)) {
                    throw new \InvalidArgumentException('Invalid cylinder code mode.');
                }

                if (!is_array($manualCodes)) {
                    $manualCodes = preg_split('/[\s,]+/', trim((string) $manualCodes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }

                if ($codeMode === 'MANUAL') {
                    if (count($manualCodes) !== $quantity) {
                        throw new \InvalidArgumentException('Manual mode requires exactly one code per new cylinder.');
                    }
                    $normalized = array_map(static fn ($code): string => strtoupper(trim((string) $code)), $manualCodes);
                    if (count(array_unique($normalized)) !== $quantity) {
                        throw new \InvalidArgumentException('Manual cylinder codes must be unique.');
                    }
                    $manualCodes = $normalized;
                }

                $amount = bcadd(
                    bcmul((string) $quantity, $cylinderCost, 2),
                    bcmul(bcmul((string) $quantity, $initialGas, 3), $gasRate, 2),
                    2
                );
                $total = bcadd($total, $amount, 2);

                $lineRows[] = [
                    'line_type' => 'NEW_CYLINDERS',
                    'cylinder_id' => null,
                    'group_id' => $groupId,
                    'quantity' => $quantity,
                    'gas' => $initialGas,
                    'cylinder_cost' => $cylinderCost,
                    'gas_rate' => $gasRate,
                    'amount' => $amount,
                    'before' => '0.000',
                    'after' => $initialGas,
                    'condition' => $condition,
                    'code_mode' => $codeMode,
                    'codes' => $manualCodes,
                    'group_code' => (string) $group['code'],
                ];
            }

            $doc = $this->docs->next('PURCHASE');
            $oldBalance = $this->ledger->balance($supplierId);
            $newBalance = bcadd($oldBalance, bcsub($total, $paid, 2), 2);

            $this->db->execute(
                'INSERT INTO purchases
                 (doc_no, supplier_id, supplier_invoice_no, purchase_date, total, paid_amount, balance_after, created_by)
                 VALUES (:doc, :supplier, :invoice, :date, :total, :paid, :balance, :user)',
                [
                    'doc' => $doc,
                    'supplier' => $supplierId,
                    'invoice' => $invoice !== '' ? $invoice : null,
                    'date' => $date,
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => $newBalance,
                    'user' => $userId,
                ]
            );
            $purchaseId = (int) $this->db->pdo()->lastInsertId();

            foreach ($lineRows as $line) {
                $this->db->execute(
                    'INSERT INTO purchase_lines
                     (purchase_id, line_type, cylinder_id, group_id, quantity, gas_kg, cylinder_cost, gas_rate, amount)
                     VALUES (:purchase, :type, :cylinder, :group_id, :quantity, :gas, :cost, :rate, :amount)',
                    [
                        'purchase' => $purchaseId,
                        'type' => $line['line_type'],
                        'cylinder' => $line['cylinder_id'],
                        'group_id' => $line['group_id'],
                        'quantity' => $line['quantity'],
                        'gas' => $line['gas'],
                        'cost' => $line['cylinder_cost'],
                        'rate' => $line['gas_rate'],
                        'amount' => $line['amount'],
                    ]
                );

                if ($line['line_type'] === 'GAS_FILL') {
                    $this->stock->move(
                        $line['cylinder_id'],
                        'PURCHASE_FILL',
                        'SHOP',
                        $line['after'],
                        null,
                        $line['gas_rate'],
                        'PURCHASE',
                        $purchaseId,
                        $userId,
                        'Gas refill'
                    );
                    continue;
                }

                for ($i = 0; $i < $line['quantity']; $i++) {
                    $code = $this->codes->nextCylinderCode(
                        $line['group_id'],
                        $line['group_code'],
                        $line['code_mode'],
                        (string) ($line['codes'][$i] ?? '')
                    );
                    $cylinderId = $this->stock->createPurchasedCylinder(
                        $line['group_id'],
                        $code,
                        $line['gas'],
                        $line['condition'],
                        $userId,
                        $purchaseId
                    );
                    $this->db->execute(
                        'UPDATE cylinder_movements
                         SET rate = :rate
                         WHERE source_document_type = \'PURCHASE\'
                           AND source_document_id = :purchase
                           AND cylinder_id = :cylinder
                           AND movement_type = \'PURCHASE_NEW\'
                         ORDER BY id DESC LIMIT 1',
                        [
                            'rate' => $line['gas_rate'],
                            'purchase' => $purchaseId,
                            'cylinder' => $cylinderId,
                        ]
                    );
                }
            }

            $this->ledger->post(
                $supplierId,
                $date,
                'PURCHASE',
                $purchaseId,
                '0.00',
                $total,
                'Purchase ' . $doc,
                $userId
            );

            if (bccomp($paid, '0.00', 2) > 0) {
                $paymentDoc = $this->docs->next('PAYMENT');
                $chequeId = null;

                if ($method === 'CHEQUE') {
                    $chequeNo = trim((string) ($input['cheque_no'] ?? ''));
                    if ($chequeNo === '') {
                        throw new \\InvalidArgumentException('Cheque number is required.');
                    }
                    $chequeDate = (string) ($input['cheque_date'] ?? $date);
                    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $chequeDate)) {
                        throw new \\InvalidArgumentException('Cheque date is invalid.');
                    }
                    $cheque = $this->db->execute(
                        'INSERT INTO cheques
                         (direction, party_id, cheque_no, bank, cheque_date, amount, status, payment_id, created_by)
                         VALUES (\'OUT\', :party, :no, :bank, :cheque_date, :amount, :status, NULL, :user)',
                        [
                            'party' => $supplierId,
                            'no' => $chequeNo,
                            'bank' => trim((string) ($input['cheque_bank'] ?? '')) ?: null,
                            'cheque_date' => $chequeDate,
                            'amount' => $paid,
                            'status' => $this->chequePostingMode() === 'ON_RECEIPT' ? 'CLEARED' : 'PENDING',
                            'user' => $userId,
                        ]
                    );
                    $chequeId = (int) $this->db->pdo()->lastInsertId();
                }

                $this->db->execute(
                    'INSERT INTO payments
                     (doc_no, payment_date, party_id, amount, method, counter_id, cheque_id, source, purchase_id, created_by)
                     VALUES (:doc, :date, :party, :amount, :method, :counter, :cheque, \'PURCHASE\', :purchase, :user)',
                    [
                        'doc' => $paymentDoc,
                        'date' => $date,
                        'party' => $supplierId,
                        'amount' => $paid,
                        'method' => $method,
                        'counter' => $counterId > 0 ? $counterId : null,
                        'cheque' => $chequeId,
                        'purchase' => $purchaseId,
                        'user' => $userId,
                    ]
                );
                $paymentId = (int) $this->db->pdo()->lastInsertId();

                if ($method === 'CHEQUE' && $chequeId) {
                    $this->db->execute('UPDATE cheques SET payment_id=:payment WHERE id=:id', [
                        'payment' => $paymentId, 'id' => $chequeId
                    ]);
                }

                if ($method !== 'CHEQUE' || $this->chequePostingMode() === 'ON_RECEIPT') {
                    $this->ledger->post(
                        $supplierId,
                        $date,
                        'PAYMENT',
                        $paymentId,
                        $paid,
                        '0.00',
                        'Payment ' . $paymentDoc,
                        $userId
                    );
                }

                if ($method === 'CASH') {
                    $this->cash->post($counterId, $date, 'OUT', $paid, 'PAYMENT', $paymentId, $userId);
                }
            }

            return [
                'id' => $purchaseId,
                'doc_no' => $doc,
                'total' => $total,
                'paid_amount' => $paid,
                'balance_after' => $newBalance,
            ];
        });
    }

    public function void(int $purchaseId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Void reason is required.');
        }

        $this->db->transaction(function () use ($purchaseId, $userId, $reason): void {
            $purchase = $this->db->fetchOne(
                'SELECT * FROM purchases WHERE id = :id FOR UPDATE',
                ['id' => $purchaseId]
            );
            if (!$purchase) {
                throw new \InvalidArgumentException('Purchase not found.');
            }
            if ($purchase['status'] !== 'POSTED') {
                throw new \InvalidArgumentException('Purchase is already void.');
            }

            $movements = $this->db->fetchAll(
                "SELECT m.*, c.code
                 FROM cylinder_movements m
                 INNER JOIN cylinders c ON c.id = m.cylinder_id
                 WHERE m.source_document_type = 'PURCHASE'
                   AND m.source_document_id = :purchase
                 ORDER BY m.id
                 FOR UPDATE",
                ['purchase' => $purchaseId]
            );

            foreach ($movements as $movement) {
                $later = $this->db->fetchOne(
                    'SELECT m.*, c.code
                     FROM cylinder_movements m
                     INNER JOIN cylinders c ON c.id = m.cylinder_id
                     WHERE m.cylinder_id = :cylinder AND m.id > :movement
                     ORDER BY m.id
                     LIMIT 1',
                    [
                        'cylinder' => $movement['cylinder_id'],
                        'movement' => $movement['id'],
                    ]
                );
                if ($later) {
                    throw new \InvalidArgumentException(
                        'Cannot void purchase: cylinder ' . $movement['code'] . ' has later ' .
                        $later['movement_type'] . ' movement. Void that document first.'
                    );
                }
            }

            foreach ($movements as $movement) {
                $this->db->execute(
                    'UPDATE cylinders
                     SET gas_kg = 0.000, location = \'SHOP\', customer_id = NULL,
                         active = 0, updated_by = :user
                     WHERE id = :id',
                    ['user' => $userId, 'id' => $movement['cylinder_id']]
                );
                $this->db->execute(
                    'INSERT INTO cylinder_movements
                     (cylinder_id, movement_type, before_gas_kg, after_gas_kg, from_location, to_location,
                      customer_id, rate, source_document_type, source_document_id, created_by)
                     VALUES (:cylinder, \'VOID_REVERSAL\', :before, 0.000, :from_location, \'SHOP\',
                             NULL, :rate, \'PURCHASE_VOID\', :purchase, :user)',
                    [
                        'cylinder' => $movement['cylinder_id'],
                        'before' => $movement['after_gas_kg'],
                        'from_location' => $movement['to_location'],
                        'rate' => $movement['rate'],
                        'purchase' => $purchaseId,
                        'user' => $userId,
                    ]
                );
            }

            $this->ledger->reverseDocument('PURCHASE', $purchaseId, $userId, (string) $purchase['purchase_date'], $reason);

            $payments = $this->db->fetchAll(
                'SELECT * FROM payments WHERE purchase_id = :purchase AND status = \'POSTED\' FOR UPDATE',
                ['purchase' => $purchaseId]
            );
            foreach ($payments as $payment) {
                $this->ledger->reverseDocument('PAYMENT', (int) $payment['id'], $userId, (string) $purchase['purchase_date'], $reason);
                if ($payment['method'] === 'CASH') {
                    $this->cash->reverseDocument('PAYMENT', (int) $payment['id'], $userId);
                }
                $this->db->execute(
                    'UPDATE payments SET status=\'VOID\' WHERE id=:id',
                    ['id' => $payment['id']]
                );
            }

            $this->db->execute(
                'UPDATE purchases
                 SET status = \'VOID\', void_reason = :reason, voided_at = NOW(), voided_by = :user
                 WHERE id = :id',
                ['reason' => $reason, 'user' => $userId, 'id' => $purchaseId]
            );
        });
    }

    private function chequePostingMode(): string
    {
        $row = $this->db->fetchOne(
            "SELECT setting_value FROM settings
             WHERE setting_group='cheques' AND setting_key='cheque_ledger_posting'"
        );
        return strtoupper((string) ($row['setting_value'] ?? 'ON_CLEARANCE'));
    }
}
