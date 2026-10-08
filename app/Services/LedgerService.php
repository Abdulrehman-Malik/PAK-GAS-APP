<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class LedgerService
{
    public function __construct(private readonly DB $db)
    {
    }

    public function post(
        int $partyId,
        string $date,
        string $docType,
        int $docId,
        string $debit,
        string $credit,
        string $narration,
        int $userId,
        ?int $reversalOf = null
    ): int {
        if (bccomp($debit, '0.00', 2) < 0 || bccomp($credit, '0.00', 2) < 0) {
            throw new \InvalidArgumentException('Ledger amounts cannot be negative.');
        }

        $this->db->execute(
            'INSERT INTO ledger_entries
             (party_id, entry_date, doc_type, doc_id, debit, credit, narration, reversal_of, created_by)
             VALUES (:party, :date, :type, :doc, :debit, :credit, :narration, :reversal, :user)',
            [
                'party' => $partyId,
                'date' => $date,
                'type' => $docType,
                'doc' => $docId,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $narration,
                'reversal' => $reversalOf,
                'user' => $userId,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function balance(int $partyId): string
    {
        $r = $this->db->fetchOne(
            'SELECT opening_balance
                    + COALESCE((SELECT SUM(debit - credit) FROM ledger_entries WHERE party_id = :party), 0) AS balance
             FROM parties
             WHERE id = :party',
            ['party' => $partyId]
        );

        return (string) ($r['balance'] ?? '0.00');
    }

    public function entriesForDocument(string $docType, int $docId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ledger_entries
             WHERE doc_type = :doc_type AND doc_id = :doc_id
             ORDER BY id',
            ['doc_type' => $docType, 'doc_id' => $docId]
        );
    }

    public function reverseEntry(array $entry, string $date, int $userId, string $narration): int
    {
        $entryId = (int) $entry['id'];

        $existing = $this->db->fetchOne(
            'SELECT id FROM ledger_entries WHERE reversal_of = :id LIMIT 1',
            ['id' => $entryId]
        );

        if ($existing) {
            throw new \InvalidArgumentException('Ledger entry has already been reversed.');
        }

        return $this->post(
            (int) $entry['party_id'],
            $date,
            'REVERSAL',
            (int) $entry['doc_id'],
            (string) $entry['credit'],
            (string) $entry['debit'],
            $narration,
            $userId,
            $entryId
        );
    }
}
