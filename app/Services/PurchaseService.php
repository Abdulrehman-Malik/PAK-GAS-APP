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
        private readonly CodeGenerator $codes,
        private readonly AuditService $audit
    ) {
    }

    public function post(array $input, int $userId): array
    {
        $date = (string) ($input['purchase_date'] ?? '');
        $supplierId = (int) ($input['supplier_id'] ?? 0);
        $lines = $input['lines'] ?? [];
        $paid = (string) ($input['paid_amount'] ?? '0.00');
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $counterId = (int) ($input['counter_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $supplierId < 1) {
            throw new \InvalidArgumentException('Purchase date and supplier are required.');
        }
        if (!is_array($lines) || $lines === []) {
            throw new \InvalidArgumentException('At least one purchase line is required.');
        }
        if (bccomp($paid, '0.00', 2) < 0) {
            throw new \InvalidArgumentException('Paid amount cannot be negative.');
        }
        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Invalid payment method.');
        }

        return $this->db->transaction(function () use ($date, $supplierId, $lines, $paid, $method, $counterId, $userId, $input): array {
            $supplier = $this->db->fetchOne(
                "SELECT id, party_type FROM parties WHERE id = :id AND party_type = 'SUPPLIER' AND active = 1 FOR UPDATE",
                ['id' => $supplierId]
            );
            if (!$supplier) {
                throw new \InvalidArgumentException('Supplier is invalid or inactive.');
            }

            $prepared = [];
            $total = '0.00';

            foreach ($lines as $line) {
                $type = strtoupper((string) ($line['line_type'] ?? ''));
                $groupId = (int) ($line['group_id'] ?? 0);
                $gas = (string) ($line['gas_kg'] ?? '0.000');
                $gasRate = (string) ($line['gas_rate'] ?? '0.00');
                $cylinderCost = (string) ($line['cylinder_cost'] ?? '0.00');

                if ($type === 'GAS_FILL') {
                    $cylinderId = (int) ($line['cylinder_id'] ?? 0);
                    $c = $this->db->fetchOne(
                        'SELECT c.*, cg.capacity_kg, cg.id AS resolved_group_id
                         FROM cylinders c INNER JOIN cylinder_groups cg ON cg.id = c.group_id
                         WHERE c.id = :id AND c.active = 1 FOR UPDATE',
                        ['id' => $cylinderId]
                    );
                    if (!$c || $c['location'] !== 'SHOP' || $c['condition_code'] !== 'GOOD') {
                        throw new \InvalidArgumentException('Purchase refill cylinder is unavailable.');
                    }
                    $groupId = (int) $c['resolved_group_id'];
                    if (bccomp($gas, '0.000', 3) <= 0 || bccomp($gas, bcsub((string) $c['capacity_kg'], (string) $c['gas_kg'], 3), 3) > 0) {
                        throw new \InvalidArgumentException('Gas added must be greater than zero and fit the cylinder.');
                    }
                    if (bccomp($gasRate, '0.00', 2) <= 0) {
                        throw new \InvalidArgumentException('Gas purchase rate must be greater than zero.');
                    }
                    $amount = bcmul($gas, $gasRate, 2);
                    $prepared[] = [
                        'line_type' => 'GAS_FILL',
                        'cylinder_id' => $cylinderId,
                        'group_id' => $groupId,
                        'quantity' => 1,
                        'gas' => $gas,
                        'gas_rate' => $gasRate,
                        'cylinder_cost' => '0.00',
                        'amount' => $amount,
                        'before' => (string) $c['gas_kg'],
                        'after' => bcadd((string) $c['gas_kg'], $gas, 3),
                    ];
                    $total = bcadd($total, $amount, 2);
                } elseif ($type === 'NEW_CYLINDERS') {
                    $group = $this->db->fetchOne(
                        'SELECT id, code, capacity_kg FROM cylinder_groups WHERE id = :id AND active = 1 FOR UPDATE',
                        ['id' => $groupId]
                    );
                    if (!$group) {
                        throw new \InvalidArgumentException('Cylinder group is invalid or inactive.');
                    }
                    $quantity = (int) ($line['quantity'] ?? 0);
                    if ($quantity < 1 || $quantity > 1000) {
                        throw new \InvalidArgumentException('New-cylinder quantity must be between 1 and 1000.');
                    }
                    $initialGas = $gas;
                    if (bccomp($initialGas, '0.000', 3) < 0 || bccomp($initialGas, (string) $group['capacity_kg'], 3) > 0) {
                        throw new \InvalidArgumentException('Initial gas is outside cylinder capacity.');
                    }
                    if (bccomp($cylinderCost, '0.00', 2) < 0 || bccomp($gasRate, '0.00', 2) < 0) {
                        throw new \InvalidArgumentException('Cylinder cost and gas rate cannot be negative.');
                    }
                    $codes = $line['codes'] ?? [];
                    $mode = strtoupper((string) ($line['code_mode'] ?? 'AUTO'));
                    if ($mode === 'MANUAL' && (!is_array($codes) || count($codes) !== $quantity)) {
                        throw new \InvalidArgumentException('Manual purchase mode requires one code per new cylinder.');
                    }
                    if ($mode === 'MANUAL' && count(array_unique(array_map('strtoupper', $codes))) !== $quantity) {
                        throw new \InvalidArgumentException('Manual purchase cylinder codes must be unique.');
                    }

                    for ($i = 0; $i < $quantity; $i++) {
                        $code = $this->codes->nextCylinderCode(
                            $groupId,
                            (string) $group['code'],
                            $mode,
                            (string) ($codes[$i] ?? '')
                        );
                        $this->db->execute(
                            "INSERT INTO cylinders
                             (code, group_id, gas_kg, location, customer_id, condition_code, source, active, created_by, updated_by)
                             VALUES (:code, :group, 0.000, 'SHOP', NULL, :condition, 'PURCHASE', 1, :user, :user)",
                            [
                                'code' => $code,
                                'group' => $groupId,
                                'condition' => strtoupper((string) ($line['condition_code'] ?? 'GOOD')),
                                'user' => $userId,
                            ]
                        );
                        $cylinderId = $this->db->lastInsertId();
                        $prepared[] = [
                            'line_type' => 'NEW_CYLINDERS',
                            'cylinder_id' => $cylinderId,
                            'group_id' => $groupId,
                            'quantity' => 1,
                            'gas' => $initialGas,
                            'gas_rate' => $gasRate,
                            'cylinder_cost' => $cylinderCost,
                            'amount' => bcadd($cylinderCost, bcmul($initialGas, $gasRate, 2), 2),
                            'code' => $code,
                        ];
                        $total = bcadd($total, end($prepared)['amount'], 2);
                    }
                } else {
                    throw new \InvalidArgumentException('Unsupported purchase line type.');
                }
            }

            if (bccomp($paid, $total, 2) > 0) {
                throw new \InvalidArgumentException('Paid amount cannot exceed purchase total.');
            }
            if ($method === 'CASH' && bccomp($paid, '0.00', 2) > 0 && $counterId < 1) {
                throw new \InvalidArgumentException('Cash counter is required.');
            }

            $postingMode = strtoupper((string) (($this->db->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key IN ('cheque_ledger_posting','posting_mode') ORDER BY setting_key='cheque_ledger_posting' DESC LIMIT 1"
            )['setting_value'] ?? 'ON_CLEARANCE')));
            $postedPaid = ($method === 'CHEQUE' && $postingMode === 'ON_CLEARANCE') ? '0.00' : $paid;

            $doc = $this->docs->next('PURCHASE');
            $oldBalance = $this->ledger->balance($supplierId);
            $newBalance = bcadd($oldBalance, bcsub($total, $postedPaid, 2), 2);

            $this->db->execute(
                'INSERT INTO purchases
                 (doc_no, supplier_id, supplier_invoice_no, purchase_date, total, paid_amount, balance_after, status, notes, created_by)
                 VALUES (:doc, :supplier, :invoice, :date, :total, :paid, :balance, \'POSTED\', :notes, :user)',
                [
                    'doc' => $doc,
                    'supplier' => $supplierId,
                    'invoice' => (string) ($input['supplier_invoice_no'] ?? ''),
                    'date' => $date,
                    'total' => $total,
                    'paid' => $postedPaid,
                    'balance' => $newBalance,
                    'notes' => (string) ($input['notes'] ?? ''),
                    'user' => $userId,
                ]
            );
            $purchaseId = $this->db->lastInsertId();

            foreach ($prepared as $line) {
                $this->db->execute(
                    'INSERT INTO purchase_lines
                     (purchase_id, line_type, cylinder_id, group_id, quantity, gas_kg, cylinder_cost, gas_rate, amount)
                     VALUES (:purchase, :type, :cylinder, :group, 1, :gas, :cost, :rate, :amount)',
                    [
                        'purchase' => $purchaseId,
                        'type' => $line['line_type'],
                        'cylinder' => $line['cylinder_id'],
                        'group' => $line['group_id'],
                        'gas' => $line['gas'],
                        'cost' => $line['cylinder_cost'],
                        'rate' => $line['gas_rate'],
                        'amount' => $line['amount'],
                    ]
                );

                if ($line['line_type'] === 'GAS_FILL') {
                    $this->stock->moveCylinder(
                        (int) $line['cylinder_id'],
                        'SHOP',
                        null,
                        (string) $line['after'],
                        'PURCHASE_FILL',
                        (string) $line['gas_rate'],
                        'PURCHASE',
                        $purchaseId,
                        $userId
                    );
                } else {
                    $this->stock->moveCylinder(
                        (int) $line['cylinder_id'],
                        'SHOP',
                        null,
                        (string) $line['gas'],
                        'PURCHASE_NEW',
                        (string) $line['gas_rate'],
                        'PURCHASE',
                        $purchaseId,
                        $userId
                    );
                }
            }

            $this->ledger->post($supplierId, $date, 'PURCHASE', $purchaseId, '0.00', $total, 'Purchase ' . $doc, $userId);

            if (bccomp($paid, '0.00', 2) > 0) {
                $paymentDoc = $this->docs->next('PAYMENT');
                $this->db->execute(
                    'INSERT INTO payments
                     (doc_no, payment_date, party_id, amount, method, counter_id, source, purchase_id, status, narration, created_by)
                     VALUES (:doc, :date, :party, :amount, :method, :counter, \'PURCHASE\', :purchase, \'POSTED\', :narration, :user)',
                    [
                        'doc' => $paymentDoc,
                        'date' => $date,
                        'party' => $supplierId,
                        'amount' => $paid,
                        'method' => $method,
                        'counter' => $counterId > 0 ? $counterId : null,
                        'purchase' => $purchaseId,
                        'narration' => 'Paid with purchase ' . $doc,
                        'user' => $userId,
                    ]
                );
                $paymentId = $this->db->lastInsertId();

                if ($method === 'CHEQUE') {
                    $cheque = $input['cheque'] ?? [];
                    $chequeNo = trim((string) ($cheque['cheque_no'] ?? ''));
                    $chequeDate = (string) ($cheque['cheque_date'] ?? '');
                    if ($chequeNo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $chequeDate)) {
                        throw new \InvalidArgumentException('Cheque number and date are required.');
                    }
                    $postingMode = strtoupper((string) (($this->db->fetchOne(
                        "SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key IN ('cheque_ledger_posting','posting_mode') ORDER BY setting_key='cheque_ledger_posting' DESC LIMIT 1"
                    )['setting_value'] ?? 'ON_CLEARANCE')));
                    $this->db->execute(
                        'INSERT INTO cheques
                         (direction, party_id, cheque_no, bank, cheque_date, amount, status, payment_id, created_by)
                         VALUES (\'OUT\', :party, :no, :bank, :date, :amount, :status, :payment, :user)',
                        [
                            'party' => $supplierId,
                            'no' => strtoupper($chequeNo),
                            'bank' => trim((string) ($cheque['bank'] ?? '')),
                            'date' => $chequeDate,
                            'amount' => $paid,
                            'status' => $postingMode === 'ON_CLEARANCE' ? 'PENDING' : 'CLEARED',
                            'payment' => $paymentId,
                            'user' => $userId,
                        ]
                    );
                    if ($postingMode !== 'ON_CLEARANCE') {
                        $this->ledger->post($supplierId, $date, 'PAYMENT', $paymentId, $paid, '0.00', 'Payment ' . $paymentDoc, $userId);
                    }
                } else {
                    $this->ledger->post($supplierId, $date, 'PAYMENT', $paymentId, $paid, '0.00', 'Payment ' . $paymentDoc, $userId);
                    if ($method === 'CASH') {
                        $this->cash->post($counterId, $date, 'OUT', $paid, 'PAYMENT', $paymentId, $userId);
                    }
                }
            }

            $this->audit->record($userId, 'CREATE', 'purchases', $purchaseId, null, [
                'doc_no' => $doc, 'total' => $total, 'paid_amount' => $postedPaid,
            ], null);

            return ['id' => $purchaseId, 'doc_no' => $doc, 'total' => $total, 'balance_after' => $newBalance];
        });
    }

    public function void(int $purchaseId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Void reason is required.');
        }

        $this->db->transaction(function () use ($purchaseId, $userId, $reason): void {
            $purchase = $this->db->fetchOne('SELECT * FROM purchases WHERE id=:id FOR UPDATE', ['id'=>$purchaseId]);
            if (!$purchase || $purchase['status'] !== 'POSTED') {
                throw new \InvalidArgumentException('Purchase is not available for void.');
            }

            $movements = $this->db->fetchAll(
                'SELECT m.*, c.code FROM cylinder_movements m
                 INNER JOIN cylinders c ON c.id=m.cylinder_id
                 WHERE m.source_document_type=\'PURCHASE\' AND m.source_document_id=:purchase
                 ORDER BY m.id FOR UPDATE',
                ['purchase'=>$purchaseId]
            );

            foreach ($movements as $m) {
                $later = $this->db->fetchOne(
                    'SELECT id,movement_type FROM cylinder_movements WHERE cylinder_id=:c AND id>:movement ORDER BY id LIMIT 1',
                    ['c'=>$m['cylinder_id'],'movement'=>$m['id']]
                );
                if ($later) {
                    throw new \InvalidArgumentException(
                        'Cannot void purchase: cylinder ' . $m['code'] . ' has a later ' . $later['movement_type'] . ' movement.'
                    );
                }
            }

            foreach ($movements as $m) {
                $this->stock->moveCylinder(
                    (int) $m['cylinder_id'],
                    'SHOP',
                    null,
                    (string) $m['before_gas_kg'],
                    'PURCHASE_VOID',
                    (string) ($m['rate'] ?? '0.00'),
                    'PURCHASE_VOID',
                    $purchaseId,
                    $userId
                );
                if ($m['movement_type'] === 'PURCHASE_NEW') {
                    $this->db->execute('UPDATE cylinders SET active=0, updated_by=:user WHERE id=:id', [
                        'user'=>$userId,'id'=>$m['cylinder_id']
                    ]);
                }
            }

            foreach ($this->ledger->entriesForDocument('PURCHASE', $purchaseId) as $entry) {
                $this->ledger->reverseEntry($entry, (string)$purchase['purchase_date'], $userId, 'Void purchase '.$purchase['doc_no']);
            }

            $payments = $this->db->fetchAll(
                'SELECT * FROM payments WHERE purchase_id=:purchase AND status=\'POSTED\' FOR UPDATE',
                ['purchase'=>$purchaseId]
            );
            foreach ($payments as $payment) {
                foreach ($this->ledger->entriesForDocument('PAYMENT',(int)$payment['id']) as $entry) {
                    $this->ledger->reverseEntry($entry,(string)$payment['payment_date'],$userId,'Void purchase payment '.$payment['doc_no']);
                }
                $cheque=$this->db->fetchOne('SELECT * FROM cheques WHERE payment_id=:payment ORDER BY id DESC LIMIT 1 FOR UPDATE',['payment'=>$payment['id']]);
                if (!$cheque && $payment['method']==='CASH') {
                    $this->cash->reverseDocument('PAYMENT',(int)$payment['id'],(string)$payment['payment_date'],$userId);
                }
                if ($cheque && $cheque['status']==='PENDING') {
                    $this->db->execute('UPDATE cheques SET status=\'BOUNCED\',bounced_reason=:reason WHERE id=:id',['reason'=>'Purchase void: '.$reason,'id'=>$cheque['id']]);
                }
                $this->db->execute('UPDATE payments SET status=\'VOID\' WHERE id=:id',['id'=>$payment['id']]);
            }

            $this->db->execute(
                'UPDATE purchases SET status=\'VOID\',void_reason=:reason,voided_at=NOW(),voided_by=:user WHERE id=:id',
                ['reason'=>$reason,'user'=>$userId,'id'=>$purchaseId]
            );
            $this->audit->record($userId,'VOID','purchases',$purchaseId,['status'=>'POSTED'],['status'=>'VOID','reason'=>$reason],null);
        });
    }
}
