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
}
