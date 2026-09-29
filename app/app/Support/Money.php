<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function parse($value): int
    {
        if (! preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', (string) $value)) {
            throw ValidationException::withMessages(['amount' => 'Use a valid BDT amount with at most two decimal places.']);
        }

return (int) bcmul((string) $value, '100', 0);
    }

    public static function format($value): string
    {
        return number_format($value / 100, 2);
    }

    public static function split(int $amount, array $weights): array
    {
        if (! $weights || array_sum($weights) <= 0) {
            throw ValidationException::withMessages(['shares' => 'Select at least one member.']);
        }$sum = array_sum($weights);
        $result = [];
        $used = 0;
        foreach ($weights as $id => $weight) {
            $result[$id] = (int) floor($amount * $weight / $sum);
            $used += $result[$id];
        }foreach (array_keys($result) as $id) {
            if ($used >= $amount) {
                break;
            }$result[$id]++;
            $used++;
        }

return $result;
    }
}
