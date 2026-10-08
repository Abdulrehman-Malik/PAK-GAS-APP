<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class PaymentService
{
    public function __construct(
        private readonly DB $db,
        private readonly DocNumberService $docs,
        private readonly LedgerService $ledger,
        private readonly CashService $cash,
        private readonly AuditService $audit
    ) {
    }

    public function post(array $input, int $userId): array
    {
        $date = (string) ($input['payment_date'] ?? '');
        $partyId = (int) ($input['party_id'] ?? 0);
        $amount = (string) ($input['amount'] ?? '0.00');
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $counterId = (int) ($input['counter_id'] ?? 0);
        $cheque = $input['cheque'] ?? null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $partyId < 1) {
            throw new \InvalidArgumentException('Payment date and supplier are required.');
        }
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }
        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Invalid payment method.');
        }
        if ($method === 'CASH' && $counterId < 1) {
            throw new \InvalidArgumentException('Cash counter is required.');
        }

        return $this->db->transaction(function () use ($date, $partyId, $amount, $method, $counterId, $cheque, $userId): array {
            $party = $this->db->fetchOne(
                "SELECT id, party_type FROM parties WHERE id = :id AND active = 1 FOR UPDATE",
                ['id' => $partyId]
            );
            if (!$party || $party['party_type'] !== 'SUPPLIER') {
                throw new \InvalidArgumentException('Supplier is invalid or inactive.');
            }

            $doc = $this->docs->next('PAYMENT');
            $this->db->execute(
                'INSERT INTO payments
                 (doc_no, payment_date, party_id, amount, method, counter_id, source, status, narration, created_by)
                 VALUES (:doc, :date, :party, :amount, :method, :counter, \'MANUAL\', \'POSTED\', :narration, :user)',
                [
                    'doc' => $doc,
                    'date' => $date,
                    'party' => $partyId,
                    'amount' => $amount,
                    'method' => $method,
                    'counter' => $counterId > 0 ? $counterId : null,
                    'narration' => (string) ($input['narration'] ?? ''),
                    'user' => $userId,
                ]
            );
            $paymentId = $this->db->lastInsertId();

            $postingMode = strtoupper((string) (($this->db->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_group='cheques' AND setting_key IN ('cheque_ledger_posting','posting_mode') ORDER BY setting_key='cheque_ledger_posting' DESC LIMIT 1"
            )['setting_value'] ?? 'ON_CLEARANCE')));

            if ($method === 'CHEQUE' && $postingMode === 'ON_CLEARANCE') {
                $this->createCheque($partyId, $amount, $cheque, null, $paymentId, $userId);
            } else {
                $this->ledger->post($partyId, $date, 'PAYMENT', $paymentId, $amount, '0.00', 'Payment ' . $doc, $userId);
                if ($method === 'CASH') {
                    $this->cash->post($counterId, $date, 'OUT', $amount, 'PAYMENT', $paymentId, $userId);
                }
            }

            $this->audit->record($userId, 'CREATE', 'payments', $paymentId, null, [
                'doc_no' => $doc, 'amount' => $amount, 'method' => $method,
            ], null);

            return ['id' => $paymentId, 'doc_no' => $doc];
        });
    }

    public function void(int $paymentId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Void reason is required.');
        }

        $this->db->transaction(function () use ($paymentId, $userId, $reason): void {
            $payment = $this->db->fetchOne('SELECT * FROM payments WHERE id = :id FOR UPDATE', ['id' => $paymentId]);
            if (!$payment || $payment['status'] !== 'POSTED') {
                throw new \InvalidArgumentException('Payment is not available for void.');
            }

            $cheque = $this->db->fetchOne(
                'SELECT * FROM cheques WHERE payment_id = :payment ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['payment' => $paymentId]
            );

            foreach ($this->ledger->entriesForDocument('PAYMENT', $paymentId) as $entry) {
                $this->ledger->reverseEntry($entry, (string) $payment['payment_date'], $userId, 'Void payment ' . $payment['doc_no']);
            }

            if ($cheque && $cheque['status'] === 'PENDING') {
                $this->db->execute(
                    'UPDATE cheques SET status = \'BOUNCED\', bounced_reason = :reason WHERE id = :id',
                    ['reason' => 'Payment void: ' . $reason, 'id' => $cheque['id']]
                );
            }
            if (!$cheque && $payment['method'] === 'CASH') {
                $this->cash->reverseDocument('PAYMENT', $paymentId, (string) $payment['payment_date'], $userId);
            }

            $this->db->execute(
                'UPDATE payments SET status = \'VOID\' WHERE id = :id',
                ['id' => $paymentId]
            );

            $this->audit->record($userId, 'VOID', 'payments', $paymentId, ['status' => 'POSTED'], [
                'status' => 'VOID', 'reason' => $reason,
            ], null);
        });
    }

    private function createCheque(
        int $partyId,
        string $amount,
        ?array $data,
        ?int $receiptId,
        ?int $paymentId,
        int $userId
    ): int {
        if (!is_array($data) || trim((string) ($data['cheque_no'] ?? '')) === '') {
            throw new \InvalidArgumentException('Cheque number is required.');
        }
        $date = (string) ($data['cheque_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('Cheque date is required.');
        }

        $this->db->execute(
            'INSERT INTO cheques
             (direction, party_id, cheque_no, bank, cheque_date, amount, status, receipt_id, payment_id, created_by)
             VALUES (\'OUT\', :party, :no, :bank, :date, :amount, \'PENDING\', :receipt, :payment, :user)',
            [
                'party' => $partyId,
                'no' => strtoupper(trim((string) $data['cheque_no'])),
                'bank' => trim((string) ($data['bank'] ?? '')),
                'date' => $date,
                'amount' => $amount,
                'receipt' => $receiptId,
                'payment' => $paymentId,
                'user' => $userId,
            ]
        );

        return $this->db->lastInsertId();
    }
}
