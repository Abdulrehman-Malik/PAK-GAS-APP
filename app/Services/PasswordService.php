<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class PasswordService
{
    public function __construct(
        private readonly DB $db,
        private readonly AuditService $audit
    ) {
    }

    public function change(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $confirmation,
        string $ip
    ): void {
        if ($newPassword !== $confirmation) {
            throw new \InvalidArgumentException('New password confirmation does not match.');
        }

        if (strlen($newPassword) < 8) {
            throw new \InvalidArgumentException('New password must be at least 8 characters.');
        }

        $user = $this->db->fetchOne(
            'SELECT password_hash, force_password_change
             FROM users
             WHERE id = :id AND active = 1
             LIMIT 1',
            ['id' => $userId]
        );

        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new \InvalidArgumentException('Current password is incorrect.');
        }

        $this->db->transaction(function () use ($userId, $user, $newPassword, $ip): void {
            $this->db->execute(
                'UPDATE users
                 SET password_hash = :password_hash, force_password_change = 0, updated_at = NOW()
                 WHERE id = :id',
                [
                    'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                    'id' => $userId,
                ]
            );

            $this->audit->record(
                $userId,
                'PASSWORD_CHANGE',
                'users',
                $userId,
                ['force_password_change' => (bool) $user['force_password_change']],
                ['force_password_change' => false],
                $ip
            );
        });
    }
}
