<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use App\Services\CylinderStatus;

$failures = 0;

function checkSame(string $expected, string $actual, string $label): void
{
    global $failures;

    if ($expected === $actual) {
        echo "PASS  {$label}" . PHP_EOL;
        return;
    }

    $failures++;
    echo "FAIL  {$label}: expected {$expected}, got {$actual}" . PHP_EOL;
}

$status = new CylinderStatus();

checkSame('FILLED', $status->resolve('15.000', '15.000', 'SHOP'), 'CylinderStatus filled');
checkSame('PARTIAL', $status->resolve('7.500', '15.000', 'SHOP'), 'CylinderStatus partial');
checkSame('EMPTY', $status->resolve('0.000', '15.000', 'SHOP'), 'CylinderStatus empty');
checkSame('ISSUED', $status->resolve('15.000', '15.000', 'CUSTOMER'), 'CylinderStatus issued');
checkSame('SOLD', $status->resolve('15.000', '15.000', 'SOLD'), 'CylinderStatus sold');

if ($failures > 0) {
    fwrite(STDERR, $failures . " runtime check(s) failed." . PHP_EOL);
    exit(1);
}

echo "All runtime checks passed." . PHP_EOL;
