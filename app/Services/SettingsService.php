<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

final class SettingsService
{
    public function __construct(private readonly SettingsRepository $repository)
    {
    }

    public function all(): array
    {
        return $this->repository->all();
    }

    public function updatePosSettings(array $transactionTypes, string $defaultType): void
    {
        $allowed = ['GAS_SALE', 'EMPTY_CYLINDER_SALE'];
        $types = array_values(array_unique(array_filter(
            array_map(static fn ($value): string => trim((string) $value), $transactionTypes),
            static fn (string $value): bool => $value !== ''
        )));

        if ($types === []) {
            throw new \InvalidArgumentException('Select at least one POS transaction type.');
        }

        foreach ($types as $type) {
            if (!in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('Unsupported POS transaction type.');
            }
        }

        if (!in_array($defaultType, $types, true)) {
            throw new \InvalidArgumentException('The default POS transaction type must be enabled.');
        }

        $this->repository->set('sales', 'pos_transaction_types', implode(',', $types));
        $this->repository->set('sales', 'pos_default_transaction_type', $defaultType);
    }
}
