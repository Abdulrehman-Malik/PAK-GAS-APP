<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

final class CylinderGroupRepository
{
    public function __construct(private readonly DB $db)
    {
    }

    public function paginate(string $search, int $limit, int $offset): array
    {
        $limit = min(100, max(10, $limit));
        $offset = max(0, $offset);
        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $where .= ' AND (code LIKE :search OR name LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $total = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) c FROM cylinder_groups WHERE {$where}",
            $params
        )['c'] ?? 0);

        $rows = $this->db->fetchAll(
            "SELECT * FROM cylinder_groups WHERE {$where}
             ORDER BY active DESC,name
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM cylinder_groups WHERE id=:id', ['id'=>$id]);
    }

    public function create(array $d): int
    {
        $this->db->execute(
            'INSERT INTO cylinder_groups(code,name,capacity_kg,cylinder_price,active,notes,created_by,updated_by)
             VALUES(:code,:name,:capacity_kg,:cylinder_price,1,:notes,:uid,:uid)',
            $d
        );
        return $this->db->lastInsertId();
    }

    public function update(int $id, array $d): void
    {
        $d['id']=$id;
        $this->db->execute(
            'UPDATE cylinder_groups SET name=:name,capacity_kg=:capacity_kg,cylinder_price=:cylinder_price,active=:active,notes=:notes,updated_by=:uid WHERE id=:id',
            $d
        );
    }

    public function childCount(int $id): int
    {
        $total=0;
        foreach ([['cylinders','group_id'],['rates','group_id'],['purchase_lines','group_id']] as [$table,$column]) {
            $row=$this->db->fetchOne("SELECT COUNT(*) c FROM {$table} WHERE {$column}=:id",['id'=>$id]);
            $total += (int)($row['c']??0);
        }
        return $total;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM cylinder_groups WHERE id=:id',['id'=>$id]);
    }
}
