<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\DB;

final class CodeGenerator
{
    public function __construct(private readonly DB $db) {}

    public function nextCylinderCode(int $groupId, string $groupCode, string $mode, string $manualCode = ''): string
    {
        if ($mode === 'MANUAL') {
            $code = strtoupper(trim($manualCode));
            if ($code === '') throw new \InvalidArgumentException('Cylinder code is required.');
            if ($this->db->fetchOne('SELECT id FROM cylinders WHERE code = :code LIMIT 1', ['code'=>$code])) {
                throw new \InvalidArgumentException('Cylinder code already exists.');
            }
            return $code;
        }

        $prefix = 'CYL-' . strtoupper($groupCode) . '-';
        $this->db->execute(
            'INSERT INTO code_sequences (prefix,last_value) VALUES (:prefix,0)
             ON DUPLICATE KEY UPDATE last_value = last_value',
            ['prefix'=>$prefix]
        );
        $row = $this->db->fetchOne('SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE', ['prefix'=>$prefix]);
        $next = ((int)$row['last_value']) + 1;
        $this->db->execute('UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix', ['value'=>$next,'prefix'=>$prefix]);
        return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
    }
}
