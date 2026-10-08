<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndLengthRules(): void
    {
        $validator = new Validator();

        $errors = $validator->validate(
            ['username' => '', 'password' => 'abc'],
            ['username' => ['required'], 'password' => ['required', 'min:6']]
        );

        self::assertSame('This field is required.', $errors['username']);
        self::assertSame('Must be at least 6 characters.', $errors['password']);
    }

    public function testValidValuesProduceNoErrors(): void
    {
        $validator = new Validator();

        self::assertSame(
            [],
            $validator->validate(
                ['username' => 'admin', 'password' => 'secret123'],
                ['username' => ['required', 'max:100'], 'password' => ['required', 'min:6', 'max:255']]
            )
        );
    }
}
