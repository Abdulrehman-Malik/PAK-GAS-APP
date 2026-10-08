<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const SESSION_USER = 'auth_user_id';

    public function __construct(
        private readonly DB $db,
        private readonly Session $session
    ) {
    }

    public function check(): bool
    {
        return is_int($this->session->get(self::SESSION_USER)) && $this->user() !== null;
    }

    public function user(): ?array
    {
        $userId = $this->session->get(self::SESSION_USER);
        if (!is_int($userId)) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT u.id, u.username, u.full_name, u.role_id, u.default_counter_id, u.active,
                    r.code AS role_code, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id AND u.active = 1',
            ['id' => $userId]
        );
    }

    public function attempt(string $username, string $password, string $ip): bool
    {
        $username = trim($username);

        $attempts = $this->db->fetchOne(
            'SELECT COUNT(*) AS total
             FROM login_attempts
             WHERE username = :username
               AND ip = :ip
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            ['username' => $username, 'ip' => $ip]
        );

        if ((int) ($attempts['total'] ?? 0) >= 5) {
            return false;
        }

        $user = $this->db->fetchOne(
            'SELECT id, password_hash, active
             FROM users
             WHERE username = :username
             LIMIT 1',
            ['username' => $username]
        );

        if ($user === null || !(bool) $user['active'] || !password_verify($password, (string) $user['password_hash'])) {
            $this->recordFailedAttempt($username, $ip, $user['id'] ?? null);
            return false;
        }

        $this->db->execute(
            'DELETE FROM login_attempts WHERE username = :username AND ip = :ip',
            ['username' => $username, 'ip' => $ip]
        );

        $this->session->regenerate();
        $this->session->set(self::SESSION_USER, (int) $user['id']);

        $this->db->execute(
            'UPDATE users
             SET last_login_at = NOW(), last_login_ip = :ip, updated_at = NOW()
             WHERE id = :id',
            ['ip' => $ip, 'id' => $user['id']]
        );

        return true;
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_USER);
        $this->session->regenerate();
    }

    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $this->db->fetchOne(
            'SELECT 1
             FROM role_permissions rp
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = :role_id
               AND (p.code = :permission OR p.code = :wildcard)
             LIMIT 1',
            [
                'role_id' => $user['role_id'],
                'permission' => $permission,
                'wildcard' => '*',
            ]
        ) !== null;
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new \RuntimeException('Access denied.', 403);
        }
    }

    private function recordFailedAttempt(string $username, string $ip, mixed $userId): void
    {
        $this->db->execute(
            'INSERT INTO login_attempts (username, ip, user_id, attempted_at)
             VALUES (:username, :ip, :user_id, NOW())',
            [
                'username' => $username,
                'ip' => $ip,
                'user_id' => is_numeric($userId) ? (int) $userId : null,
            ]
        );
    }
}
