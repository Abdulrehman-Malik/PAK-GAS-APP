<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class ImportService
{
    public function __construct(
        private readonly DB $db,
        private readonly XlsxService $xlsx,
        private readonly StockService $stock,
        private readonly AuditService $audit
    ) {}

    public function openingPreview(string $path): array
    {
        $rows = $this->xlsx->read($path);
        $errors = [];
        $valid = [];

        foreach ($rows as $n => $row) {
            $line = $n + 2;
            $groupCode = strtoupper(trim((string) ($row['group_code'] ?? '')));
            $groupName = trim((string) ($row['group_name'] ?? ''));
            $capacity = (string) ($row['capacity'] ?? '');
            $gas = (string) ($row['actual_gas'] ?? '');
            $location = strtoupper(trim((string) ($row['location'] ?? 'SHOP')));
            $customerCode = strtoupper(trim((string) ($row['customer_code'] ?? '')));
            $condition = strtoupper(trim((string) ($row['condition'] ?? 'GOOD')));
            $date = (string) ($row['date'] ?? '');
            $code = strtoupper(trim((string) ($row['cylinder_code'] ?? '')));
            $mode = strtoupper(trim((string) ($row['code_mode'] ?? 'AUTO')));

            $err = [];

            if ($groupCode === '') {
                $err[] = 'group_code is required';
            }
            if (bccomp($capacity, '0.000', 3) <= 0) {
                $err[] = 'capacity must be greater than zero';
            }
            if (bccomp($gas, '0.000', 3) < 0 || bccomp($gas, $capacity, 3) > 0) {
                $err[] = 'actual_gas is outside capacity';
            }
            if (!in_array($location, ['SHOP', 'ISSUED'], true)) {
                $err[] = 'location must be SHOP or ISSUED';
            }
            if ($location === 'ISSUED' && $customerCode === '') {
                $err[] = 'customer_code is required for ISSUED';
            }
            if (!in_array($condition, ['GOOD', 'DAMAGED'], true)) {
                $err[] = 'condition must be GOOD or DAMAGED';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $err[] = 'date is invalid';
            }
            if (!in_array($mode, ['AUTO', 'MANUAL'], true)) {
                $err[] = 'code_mode must be AUTO or MANUAL';
            }

            $codes = array_values(array_filter(
                array_map('trim', preg_split('/[\s,]+/', $code) ?: []),
                static fn (string $v): bool => $v !== ''
            ));

            if ($mode === 'MANUAL') {
                if ($codes === []) {
                    $err[] = 'cylinder_code is required in MANUAL mode';
                }
                if (count($codes) !== count(array_unique(array_map('strtoupper', $codes)))) {
                    $err[] = 'manual cylinder codes must be unique within the file row';
                }
                foreach ($codes as $manualCode) {
                    $existing = $this->db->fetchOne(
                        'SELECT id FROM cylinders WHERE code=:code LIMIT 1',
                        ['code' => strtoupper($manualCode)]
                    );
                    if ($existing) {
                        $err[] = 'cylinder code already exists: ' . strtoupper($manualCode);
                    }
                }
                $quantity = count($codes);
            } else {
                $quantity = (int) ($row['quantity'] ?? 0);
                if ($quantity < 1 || $quantity > 1000) {
                    $err[] = 'quantity must be between 1 and 1000 in AUTO mode';
                }
                $codes = [];
            }

            $group = $groupCode === '' ? null : $this->db->fetchOne(
                'SELECT id,capacity_kg FROM cylinder_groups WHERE code=:code',
                ['code' => $groupCode]
            );
            if ($group && bccomp((string) $group['capacity_kg'], $capacity, 3) !== 0) {
                $err[] = 'capacity conflicts with existing group';
            }

            $customerId = null;
            if ($location === 'ISSUED') {
                $customer = $this->db->fetchOne(
                    "SELECT id FROM parties WHERE code=:code AND party_type='CUSTOMER' AND active=1",
                    ['code' => $customerCode]
                );
                if (!$customer) {
                    $err[] = 'customer_code does not match an active customer';
                } else {
                    $customerId = (int) $customer['id'];
                }
            }

            if ($err) {
                $errors[] = ['row' => $line, 'errors' => $err, 'data' => $row];
            } else {
                $valid[] = [
                    'row' => $line,
                    'data' => $row,
                    'mode' => $mode,
                    'quantity' => $quantity,
                    'codes' => $codes,
                    'customer_id' => $customerId,
                ];
            }
        }

        return ['rows_total' => count($rows), 'valid' => $valid, 'errors' => $errors];
    }

    public function createPreview(int $userId, string $filename, array $preview): string
    {
        $token = bin2hex(random_bytes(16));
        $dir = base_path('storage/imports');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create import storage directory.');
        }

        $payload = [
            'user_id' => $userId,
            'expires_at' => time() + 1800,
            'preview' => $preview,
        ];
        file_put_contents($dir . '/preview_' . $token . '.json', json_encode($payload, JSON_THROW_ON_ERROR));

        $this->db->execute(
            'INSERT INTO import_batches
             (import_type, filename, rows_total, rows_ok, rows_error, status, created_by)
             VALUES (\'OPENING_STOCK\', :filename, :total, :ok, :errors, \'PREVIEW\', :user)',
            [
                'filename' => $filename,
                'total' => $preview['rows_total'],
                'ok' => count($preview['valid']),
                'errors' => count($preview['errors']),
                'user' => $userId,
            ]
        );

        return $token;
    }

    public function openingCommitPreview(string $token, int $userId): array
    {
        $file = base_path('storage/imports/preview_' . basename($token) . '.json');
        if (!is_file($file)) {
            throw new \InvalidArgumentException('Import preview has expired or does not exist.');
        }

        $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if ((int) ($payload['user_id'] ?? 0) !== $userId || (int) ($payload['expires_at'] ?? 0) < time()) {
            @unlink($file);
            throw new \InvalidArgumentException('Import preview has expired or belongs to another user.');
        }

        $preview = $payload['preview'] ?? [];
        if (($preview['errors'] ?? []) !== []) {
            throw new \InvalidArgumentException('Import contains validation errors. Nothing was committed.');
        }

        $result = $this->openingCommit((array) ($preview['valid'] ?? []), $userId);
        @unlink($file);

        $this->db->execute(
            "UPDATE import_batches
             SET status='COMMITTED'
             WHERE import_type='OPENING_STOCK'
               AND created_by=:user
               AND status='PREVIEW'
             ORDER BY id DESC LIMIT 1",
            ['user' => $userId]
        );

        return $result;
    }

    public function openingCommit(array $validRows, int $userId): array
    {
        if ($validRows === []) {
            throw new \InvalidArgumentException('No valid rows to commit.');
        }

        return $this->db->transaction(function () use ($validRows, $userId): array {
            $stockRows = [];

            foreach ($validRows as $item) {
                $row = $item['data'];
                $groupCode = strtoupper(trim((string) $row['group_code']));
                $group = $this->db->fetchOne(
                    'SELECT id,code,capacity_kg FROM cylinder_groups WHERE code=:code FOR UPDATE',
                    ['code' => $groupCode]
                );

                if (!$group) {
                    $this->db->execute(
                        'INSERT INTO cylinder_groups
                         (code,name,capacity_kg,cylinder_price,active,created_by,updated_by)
                         VALUES (:code,:name,:capacity,0,1,:user,:user)',
                        [
                            'code' => $groupCode,
                            'name' => trim((string) ($row['group_name'] ?? $groupCode)) ?: $groupCode,
                            'capacity' => (string) $row['capacity'],
                            'user' => $userId,
                        ]
                    );
                    $group = $this->db->fetchOne(
                        'SELECT id,code,capacity_kg FROM cylinder_groups WHERE code=:code FOR UPDATE',
                        ['code' => $groupCode]
                    );
                }

                $stockRows[] = [
                    'batch_date' => (string) $row['date'],
                    'group_id' => (int) $group['id'],
                    'actual_gas' => (string) $row['actual_gas'],
                    'location' => strtoupper((string) $row['location']),
                    'customer_id' => $item['customer_id'] ?? null,
                    'condition_code' => strtoupper((string) ($row['condition'] ?? 'GOOD')),
                    'quantity' => (int) $item['quantity'],
                    'code_mode' => (string) $item['mode'],
                    'codes' => $item['codes'] ?? [],
                ];
            }

            $batchId = $this->stock->createOpeningBatch($stockRows, $userId, 'IMPORT', 'Excel import');

            $this->audit->record(
                $userId,
                'IMPORT',
                'opening_stock',
                $batchId,
                null,
                ['rows' => count($validRows), 'batch_id' => $batchId],
                null
            );

            return ['created' => array_sum(array_map(static fn (array $r): int => (int) $r['quantity'], $validRows)), 'batch_id' => $batchId];
        });
    }
}
