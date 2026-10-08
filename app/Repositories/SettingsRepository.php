<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\DB;

final class SettingsRepository
{
    public function __construct(private readonly DB $db)
    {
    }

    public function set(string $group, string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings(setting_group, setting_key, setting_value)
             VALUES(:group_name, :key_name, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['group_name' => $group, 'key_name' => $key, 'value' => $value]
        );
    }

    public function all(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT setting_group, setting_key, setting_value
             FROM settings
             ORDER BY setting_group, setting_key'
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['setting_group']][(string) $row['setting_key']] = (string) $row['setting_value'];
        }

        return $result;
    }
}
