<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class CodeGenerator
{
    public function __construct(private readonly DB $db) {}

    public function nextCylinderCode(
        int $groupId,
        string $groupCode,
        string $mode,
        string $manualCode = ''
    ): string {
        if (strtoupper($mode) === 'MANUAL') {
            return $this->validateManualCode($manualCode);
        }

        $group = $this->db->fetchOne(
            'SELECT capacity_kg FROM cylinder_groups WHERE id=:id',
            ['id'=>$groupId]
        );
        if (!$group) {
            throw new \InvalidArgumentException('Cylinder group not found.');
        }

        $pattern=(string)($this->db->fetchOne(
            "SELECT setting_value FROM settings
             WHERE setting_group='code_generation' AND setting_key='cylinder_code_pattern'"
        )['setting_value']??'{GROUP}{CAP}-{SEQ:6}');

        if (!preg_match('/\{SEQ(?::(\d+))?\}/',$pattern,$seqMatch)) {
            throw new \RuntimeException('Invalid cylinder code pattern. Include {SEQ} or {SEQ:n}.');
        }

        $width=max(
            1,
            min(
                12,
                (int)($seqMatch[1]??$this->settingInt('cylinder_seq_width',6))
            )
        );

        $capacity=trim((string)$group['capacity_kg']);
        $capacity=rtrim(rtrim($capacity,'0'),'.');
        $capacity=str_replace('.','_',$capacity);

        $render=$pattern;
        $render=str_replace('{GROUP}',strtoupper($groupCode),$render);
        $render=str_replace('{CAP}',$capacity,$render);
        $render=preg_replace('/\{SEQ(?::\d+)?\}/','%SEQ%',$render) ?? $render;

        [$prefix,$suffix]=array_pad(explode('%SEQ%',$render,2),2,'');
        $sequencePrefix=$prefix.$suffix;

        $this->db->execute(
            'INSERT INTO code_sequences(prefix,last_value) VALUES(:prefix,0)
             ON DUPLICATE KEY UPDATE last_value=last_value',
            ['prefix'=>$sequencePrefix]
        );
        $row=$this->db->fetchOne(
            'SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',
            ['prefix'=>$sequencePrefix]
        );
        $next=((int)$row['last_value'])+1;

        $this->db->execute(
            'UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',
            ['value'=>$next,'prefix'=>$sequencePrefix]
        );

        return $prefix.str_pad((string)$next,$width,'0',STR_PAD_LEFT).$suffix;
    }

    public function nextGroupCode(string $mode, string $manualCode=''): string
    {
        if (strtoupper($mode)==='MANUAL') {
            $code=strtoupper(trim($manualCode));
            if($code==='')throw new \InvalidArgumentException('Cylinder group code is required.');
            if($this->db->fetchOne('SELECT id FROM cylinder_groups WHERE code=:code LIMIT 1',['code'=>$code])){
                throw new \InvalidArgumentException('Cylinder group code already exists.');
            }
            return $code;
        }

        $prefix=(string)($this->db->fetchOne(
            "SELECT setting_value FROM settings
             WHERE setting_group='code_generation' AND setting_key='group_code_prefix'"
        )['setting_value']??'G');
        $width=max(1,min(12,$this->settingInt('group_code_width',3)));

        $this->db->execute(
            'INSERT INTO code_sequences(prefix,last_value) VALUES(:prefix,0)
             ON DUPLICATE KEY UPDATE last_value=last_value',
            ['prefix'=>$prefix]
        );
        $row=$this->db->fetchOne(
            'SELECT last_value FROM code_sequences WHERE prefix=:prefix FOR UPDATE',
            ['prefix'=>$prefix]
        );
        $next=((int)$row['last_value'])+1;
        $this->db->execute(
            'UPDATE code_sequences SET last_value=:value WHERE prefix=:prefix',
            ['value'=>$next,'prefix'=>$prefix]
        );

        return $prefix.str_pad((string)$next,$width,'0',STR_PAD_LEFT);
    }

    private function validateManualCode(string $value): string
    {
        $code=strtoupper(trim($value));
        if($code==='')throw new \InvalidArgumentException('Cylinder code is required.');
        if($this->db->fetchOne('SELECT id FROM cylinders WHERE code=:code LIMIT 1',['code'=>$code])){
            throw new \InvalidArgumentException('Cylinder code already exists.');
        }
        return $code;
    }

    private function settingInt(string $key,int $default):int
    {
        $row=$this->db->fetchOne(
            'SELECT setting_value FROM settings WHERE setting_group=\'code_generation\' AND setting_key=:key',
            ['key'=>$key]
        );
        return (int)($row['setting_value']??$default);
    }
}
