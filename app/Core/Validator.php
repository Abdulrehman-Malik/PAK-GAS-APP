<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                if ($rule === 'required' && ($value === null || trim((string) $value) === '')) {
                    $errors[$field] = 'This field is required.';
                    break;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (mb_strlen((string) $value) < $min) {
                        $errors[$field] = sprintf('Must be at least %d characters.', $min);
                        break;
                    }
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (mb_strlen((string) $value) > $max) {
                        $errors[$field] = sprintf('Must not exceed %d characters.', $max);
                        break;
                    }
                }
            }
        }

        return $errors;
    }
}
