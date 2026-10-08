<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ReceiptService
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
        $date = (string) ($input['receipt_date'] ?? '');
        $partyId = (int) ($input['party_id'] ?? 0);
        $amount = (string) ($input['amount'] ?? '0.00');
        $method = strtoupper((string) ($input['method'] ?? 'CASH'));
        $counterId = (int) ($input['counter_id'] ?? 0);
        $cheque = $input['cheque'] ?? null;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $partyId < 1) {
            throw new \InvalidArgumentException('Receipt date and customer are required.');
        }
        if (bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Receipt amount must be greater than zero.');
        }
        if (!in_array($method, ['CASH', 'ONLINE', 'CHEQUE'], true)) {
            throw new \InvalidArgumentException('Invalid receipt method.');
        }
        if ($method === 'CASH' && $counterId < 1) {
            throw new \InvalidArgumentException('Cash counter is required.');
        }

        return $this->db->transaction(function () use ($date, $partyId, $amount, $method, $counterId, $cheque, $userId): array {
            $party = $this->db->fetchOne(
                "SELECT id, party_type FROM parties WHERE id = :id AND active = 1 FOR UPDATE",
                ['id' => $partyId]
            );
            if (!$party || $party['party_type'] !== 'CUSTOMER') {
                throw new \InvalidArgumentException('Customer is invalid or inactive.');
            }

            $doc = $this->docs->next('RECEIPT');
            $this->db->execute(
                'INSERT INTO receipts
                 (doc_no, receipt_date, party_id, amount, method, counter_id, source, status, narration, created_by)
                 VALUES (:doc, :date, :party, :amount, :method, :counter, \'MANUAL\', \'POSTED\', :narration, :user)',
                [
                    'doc' => $doc,
                    'date' => $date,
                    'party' => $partyId,
                    'amount' => $amount,
                    'method' => $method,
                    'counter' => $counterId > 0 ? $counterId : null,
                    'narration' => (string) ($cheque['narration'] ?? ''),
                    'user' => $userId,
                ]
            );
            $receiptId = $this->db->lastInsertId();

            $postingMode = strtoupper((string) (($this->db->fetchOne(
                "SELECT setting_value FROM settings WHERE setting_group = 'cheques' AND setting_key = 'posting_mode'"
            )['setting_value'] ?? 'ON_CLEARANCE')));

            if ($method === 'CHEQUE' && $postingMode === 'ON_CLEARANCE') {
                $this->createCheque($partyId, $amount, $cheque, $receiptId, null, $userId);
            } else {
                $this->ledger->post($partyId, $date, 'RECEIPT', $receiptId, '0.00', $amount, 'Receipt ' . $doc, $userId);
                if ($method === 'CASH') {
                    $this->cash->post($counterId, $date, 'IN', $amount, 'RECEIPT', $receiptId, $userId);
                }
            }

            $this->audit->record($userId, 'CREATE', 'receipts', $receiptId, null, [
                'doc_no' => $doc, 'amount' => $amount, 'method' => $method,
            ], null);

            return ['id' => $receiptId, 'doc_no' => $doc];
        });
    }

    public function void(int $receiptId, int $userId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Void reason is required.');
        }

        $this->db->transaction(function () use ($receiptId, $userId, $reason): void {
            $receipt = $this->db->fetchOne('SELECT * FROM receipts WHERE id = :id FOR UPDATE', ['id' => $receiptId]);
            if (!$receipt || $receipt['status'] !== 'POSTED') {
                throw new \InvalidArgumentException('Receipt is not available for void.');
            }

            $cheque = $this->db->fetchOne(
                'SELECT * FROM cheques WHERE receipt_id = :receipt ORDER BY id DESC LIMIT 1 FOR UPDATE',
                ['receipt' => $receiptId]
            );
            if ($cheque && $cheque['status'] === 'PENDING') {
                $this->db->execute(
                    'UPDATE cheques SET status = \'BOUNCED\', bounced_reason = :reason WHERE id = :id',
                    ['reason' => 'Receipt void: ' . $reason, 'id' => $cheque['id']]
                );
            } else {
                foreach ($this->ledger->entriesForDocument('RECEIPT', $receiptId) as $entry) {
                    $this->ledger->reverseEntry(
                        $entry,
                        (string) $receipt['receipt_date'],
                        $userId,
                        'Void receipt ' . $receipt['doc_no']
                    );
                }
                if (!$cheque && $receipt['method'] === 'CASH') {
                    $this->cash->reverseDocument('RECEIPT', $receiptId, (string) $receipt['receipt_date'], $userId);
                }
            }

            $this->db->execute(
                'UPDATE receipts SET status = \'VOID\' WHERE id = :id',
                ['id' => $receiptId]
            );
            $this->audit->record($userId, 'VOID', 'receipts', $receiptId, ['status' => 'POSTED'], [
                'status' => 'VOID', 'reason' => $reason,
            ], null);
        });
    }

    private function createCheque(
        int $partyId,
        string $amount,
        ?array $data,
        int $receiptId,
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
             VALUES (\'IN\', :party, :no, :bank, :date, :amount, \'PENDING\', :receipt, :payment, :user)',
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
