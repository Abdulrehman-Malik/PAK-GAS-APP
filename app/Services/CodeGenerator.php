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
            if ($code === '') {
                throw new \InvalidArgumentException('Cylinder code is required.');
            }
            if ($this->db->fetchOne('SELECT id FROM cylinders WHERE code = :code LIMIT 1', ['code' => $code])) {
                throw new \InvalidArgumentException('Cylinder code already exists.');
            }
            return $code;
        }

        $pattern = (string)($this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='code_generation' AND setting_key='cylinder_code_pattern'"
        )['setting_value'] ?? 'CYL-{GROUP}-{SEQ:6}');

        $prefix = 'CYL-' . strtoupper($groupCode) . '-';
        if (preg_match('/^(.+)\{SEQ:(\d+)\}(.*)$/', $pattern, $m) !== 1) {
            throw new \RuntimeException('Invalid cylinder code pattern setting. Use {SEQ:n}.');
        }

        $width = max(1, min(12, (int)$m[2]));
        $before = str_replace('{GROUP}', strtoupper($groupCode), $m[1]);
        $after = str_replace('{GROUP}', strtoupper($groupCode), $m[3]);
        $sequencePrefix = $before . $after;
        $this->db->execute(
            'INSERT INTO code_sequences (prefix,last_value) VALUES (:prefix,0)
             ON DUPLICATE KEY UPDATE last_value=last_value',
            ['prefix' => $sequencePrefix]
        );
        $row = $this->db->fetchOne(
            'SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',
            ['prefix' => $sequencePrefix]
        );
        $next = ((int)$row['last_value']) + 1;
        $this->db->execute(
            'UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',
            ['value' => $next, 'prefix' => $sequencePrefix]
        );

        return $before . str_pad((string)$next, $width, '0', STR_PAD_LEFT) . $after;
    }
}
