<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class CashService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function post(
        int $counterId,
        string $date,
        string $direction,
        string $amount,
        string $docType,
        int $docId,
        int $userId
    ): int {
        if (!in_array($direction, ['IN', 'OUT'], true) || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Invalid cash entry.');
        }

        $session = $this->db->fetchOne(
            'SELECT id FROM counter_sessions
             WHERE counter_id = :counter AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1',
            ['counter' => $counterId]
        );
        if (!$session) {
            throw new \InvalidArgumentException('No open cash session for the selected counter.');
        }

        return $this->insert(
            $counterId,
            (int) $session['id'],
            $date,
            $direction,
            $amount,
            $docType,
            $docId,
            $userId,
            null
        );
    }

    public function reverseDocument(string $docType, int $docId, int $userId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM cash_entries
             WHERE doc_type = :doc_type AND doc_id = :doc_id
             ORDER BY id
             FOR UPDATE',
            ['doc_type' => $docType, 'doc_id' => $docId]
        );

        foreach ($rows as $row) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM cash_entries
                 WHERE doc_type = :doc_type AND doc_id = :doc_id
                   AND reason = :reason
                 LIMIT 1',
                [
                    'doc_type' => 'REVERSAL_' . $docType,
                    'doc_id' => $docId,
                    'reason' => 'Reversal of cash entry ' . $row['id'],
                ]
            );
            if ($exists) {
                continue;
            }

            $this->insert(
                (int) $row['counter_id'],
                (int) $row['session_id'],
                (string) $row['entry_date'],
                $row['direction'] === 'IN' ? 'OUT' : 'IN',
                (string) $row['amount'],
                'REVERSAL_' . $docType,
                $docId,
                $userId,
                'Reversal of cash entry ' . $row['id']
            );
        }
    }

    public function summary(int $counterId, ?int $sessionId = null): array
    {
        $params = ['counter' => $counterId];
        $where = 'counter_id = :counter';

        if ($sessionId !== null) {
            $where .= ' AND session_id = :session';
            $params['session'] = $sessionId;
        }

        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE 0 END), 0) AS cash_in,
                COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount ELSE 0 END), 0) AS cash_out
             FROM cash_entries
             WHERE {$where}",
            $params
        );

        return [
            'cash_in' => (string) ($row['cash_in'] ?? '0.00'),
            'cash_out' => (string) ($row['cash_out'] ?? '0.00'),
        ];
    }

    private function insert(
        int $counterId,
        int $sessionId,
        string $date,
        string $direction,
        string $amount,
        string $docType,
        int $docId,
        int $userId,
        ?string $reason
    ): int {
        $this->db->execute(
            'INSERT INTO cash_entries
             (counter_id, session_id, entry_date, direction, amount, doc_type, doc_id, reason, created_by)
             VALUES (:counter, :session, :date, :direction, :amount, :doc_type, :doc_id, :reason, :user)',
            [
                'counter' => $counterId,
                'session' => $sessionId,
                'date' => $date,
                'direction' => $direction,
                'amount' => $amount,
                'doc_type' => $docType,
                'doc_id' => $docId,
                'reason' => $reason,
                'user' => $userId,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }
}
