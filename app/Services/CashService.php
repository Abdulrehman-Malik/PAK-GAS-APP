<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class CashService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function post(int $counterId, string $date, string $direction, string $amount, string $docType, int $docId, int $userId): int
    {
        if (!in_array($direction, ['IN', 'OUT'], true) || bccomp($amount, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('Invalid cash entry.');
        }

        $s = $this->db->fetchOne(
            'SELECT id FROM counter_sessions
             WHERE counter_id = :counter AND closed_at IS NULL
             ORDER BY id DESC LIMIT 1',
            ['counter' => $counterId]
        );

        if (!$s) {
            throw new \InvalidArgumentException('No open cash session for the selected counter.');
        }

        $this->db->execute(
            'INSERT INTO cash_entries
             (counter_id, session_id, entry_date, direction, amount, doc_type, doc_id, created_by)
             VALUES (:counter, :session, :date, :direction, :amount, :type, :doc, :user)',
            [
                'counter' => $counterId,
                'session' => $s['id'],
                'date' => $date,
                'direction' => $direction,
                'amount' => $amount,
                'type' => $docType,
                'doc' => $docId,
                'user' => $userId,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function reverseDocument(string $docType, int $docId, string $date, int $userId): int
    {
        $entry = $this->db->fetchOne(
            'SELECT * FROM cash_entries
             WHERE doc_type = :type AND doc_id = :doc
             ORDER BY id DESC LIMIT 1',
            ['type' => $docType, 'doc' => $docId]
        );

        if (!$entry || bccomp((string) $entry['amount'], '0.00', 2) <= 0) {
            return 0;
        }

        $existing = $this->db->fetchOne(
            "SELECT id FROM cash_entries
             WHERE doc_type='REVERSAL' AND doc_id=:doc
             ORDER BY id DESC LIMIT 1",
            ['doc' => $docId]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $this->db->execute(
            'INSERT INTO cash_entries
             (counter_id, session_id, entry_date, direction, amount, doc_type, doc_id, reason, created_by)
             VALUES (:counter, :session, :date, :direction, :amount, \'REVERSAL\', :doc, :reason, :user)',
            [
                'counter' => $entry['counter_id'],
                'session' => $entry['session_id'],
                'date' => $date,
                'direction' => $entry['direction'] === 'IN' ? 'OUT' : 'IN',
                'amount' => $entry['amount'],
                'doc' => $docId,
                'reason' => 'Reversal of ' . $docType . ' #' . $docId,
                'user' => $userId,
            ]
        );

        return $this->db->lastInsertId();
    }
}
