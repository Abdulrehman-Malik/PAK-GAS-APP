<?php
declare(strict_types=1);
namespace App\Services;

final class CylinderStatus
{
    public function resolve(string $gasKg, string $capacity, string $location): string
    {
        if ($location === 'CUSTOMER') return 'ISSUED';
        if ($location === 'SOLD') return 'SOLD';
        if (bccomp($gasKg, $capacity, 3) === 0) return 'FILLED';
        if (bccomp($gasKg, '0.000', 3) > 0) return 'PARTIAL';
        return 'EMPTY';
    }
}
