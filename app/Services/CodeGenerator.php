<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class CodeGenerator
{
    public function __construct(private readonly DB $db)
    {
    }

    public function nextGroupCode(): string
    {
        $prefix = (string) (($this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='code_generation' AND setting_key='group_code_prefix'"
        )['setting_value'] ?? 'G') ?: 'G');
        $width = max(1, min(12, (int) (($this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='code_generation' AND setting_key='group_seq_width'"
        )['setting_value'] ?? '3'))));

        $this->db->execute(
            'INSERT INTO code_sequences(prefix,last_value) VALUES(:prefix,0)
             ON DUPLICATE KEY UPDATE last_value=last_value',
            ['prefix' => $prefix]
        );
        $row = $this->db->fetchOne(
            'SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',
            ['prefix' => $prefix]
        );
        $next = ((int) $row['last_value']) + 1;
        $this->db->execute(
            'UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',
            ['value' => $next, 'prefix' => $prefix]
        );

        return strtoupper($prefix) . str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    public function nextCylinderCode(int $groupId, string $groupCode, string $mode, string $manualCode = ''): string
    {
        if (strtoupper($mode) === 'MANUAL') {
            $code = strtoupper(trim($manualCode));
            if ($code === '') {
                throw new \InvalidArgumentException('Cylinder code is required.');
            }
            if ($this->db->fetchOne('SELECT id FROM cylinders WHERE code=:code LIMIT 1', ['code'=>$code])) {
                throw new \InvalidArgumentException('Cylinder code already exists.');
            }
            return $code;
        }

        $group = $this->db->fetchOne(
            'SELECT code, capacity_kg FROM cylinder_groups WHERE id=:id FOR UPDATE',
            ['id'=>$groupId]
        );
        if (!$group) {
            throw new \InvalidArgumentException('Cylinder group not found.');
        }

        $pattern = (string) (($this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='code_generation' AND setting_key='cylinder_code_pattern'"
        )['setting_value'] ?? '{GROUP}{CAP}-{SEQ}'));

        $seqWidth = max(1, min(12, (int) (($this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_group='code_generation' AND setting_key='cylinder_seq_width'"
        )['setting_value'] ?? '6'))));

        $capacity = rtrim(rtrim(number_format((float) $group['capacity_kg'], 3, '.', ''), '0'), '.');
        $capacity = str_replace('.', '_', $capacity);

        if (preg_match('/^(.*)\{SEQ(?::(\d+))?\}(.*)$/', $pattern, $m) !== 1) {
            throw new \RuntimeException('Invalid cylinder code pattern setting. Use {SEQ}.');
        }

        $width = isset($m[2]) && $m[2] !== '' ? max(1, min(12, (int) $m[2])) : $seqWidth;
        $before = str_replace(
            ['{GROUP}', '{CAP}'],
            [strtoupper((string) $group['code']), $capacity],
            $m[1]
        );
        $after = str_replace(
            ['{GROUP}', '{CAP}'],
            [strtoupper((string) $group['code']), $capacity],
            $m[3]
        );
        $sequencePrefix = $before . $after;

        $this->db->execute(
            'INSERT INTO code_sequences(prefix,last_value) VALUES(:prefix,0)
             ON DUPLICATE KEY UPDATE last_value=last_value',
            ['prefix'=>$sequencePrefix]
        );
        $row = $this->db->fetchOne(
            'SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',
            ['prefix'=>$sequencePrefix]
        );
        $next = ((int) $row['last_value']) + 1;
        $this->db->execute(
            'UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',
            ['value'=>$next,'prefix'=>$sequencePrefix]
        );

        return $before . str_pad((string)$next,$width,'0',STR_PAD_LEFT) . $after;
    }
}
