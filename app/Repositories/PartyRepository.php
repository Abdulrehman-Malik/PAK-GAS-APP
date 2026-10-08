<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

final class PartyRepository
{
    public function __construct(private readonly DB $db) {}

    public function paginate(string $type, string $search, int $limit, int $offset): array
    {
        $limit = min(100, max(10, $limit));
        $offset = max(0, $offset);

        $where = 'p.party_type = :type';
        $params = ['type' => $type];

        if ($search !== '') {
            $where .= ' AND (p.code LIKE :search OR p.name LIKE :search OR p.phone LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $totalParams = $params;
        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM parties p WHERE {$where}",
            $totalParams
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT p.*, COALESCE(v.balance, p.opening_balance) AS balance
             FROM parties p
             LEFT JOIN v_party_balance v ON v.id = p.id
             WHERE {$where}
             ORDER BY p.active DESC, p.name
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM parties WHERE id=:id', ['id' => $id]);
    }

    public function create(array $d): int
    {
        $this->db->execute(
            'INSERT INTO parties(code,party_type,name,phone,address,allow_credit,credit_limit,opening_balance,active,notes,created_by,updated_by)
             VALUES(:code,:party_type,:name,:phone,:address,:allow_credit,:credit_limit,:opening_balance,1,:notes,:uid,:uid)',
            $d
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $d['id'] = $id;
        $this->db->execute(
            'UPDATE parties SET name=:name,phone=:phone,address=:address,allow_credit=:allow_credit,credit_limit=:credit_limit,notes=:notes,active=:active,updated_by=:uid WHERE id=:id',
            $d
        );
    }

    public function childCount(int $id): int
    {
        $total = 0;
        foreach ([
            ['cylinders', 'customer_id'],
            ['sales', 'customer_id'],
            ['receipts', 'party_id'],
            ['purchases', 'supplier_id'],
            ['payments', 'party_id'],
            ['ledger_entries', 'party_id'],
            ['cheques', 'party_id'],
        ] as [$table, $column]) {
            $total += (int) ($this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM {$table} WHERE {$column}=:id",
                ['id' => $id]
            )['c'] ?? 0);
        }
        return $total;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM parties WHERE id=:id', ['id' => $id]);
    }
}
