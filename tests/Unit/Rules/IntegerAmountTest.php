<?php

use App\Rules\IntegerAmount;

function failsIntegerAmount(mixed $value): bool
{
    $failed = false;

    (new IntegerAmount)->validate('amount', $value, function () use (&$failed): void {
        $failed = true;
    });

    return $failed;
}

it('accepts whole rupiah amounts', function (mixed $value) {
    expect(failsIntegerAmount($value))->toBeFalse();
})->with([
    'smallest integer' => 1,
    'typical integer' => 4_500_000,
    'largest integer' => IntegerAmount::MAXIMUM,
    'digit string' => '4500000',
]);

it('rejects values that are not whole rupiah amounts', function (mixed $value) {
    expect(failsIntegerAmount($value))->toBeTrue();
})->with([
    'float holding a whole value' => 4_500_000.0,
    'fraction' => 4_500_000.5,
    'scientific notation string' => '4.5e6',
    'decimal string' => '4500000.00',
    'zero' => 0,
    'negative' => -1,
    'leading zero' => '04500000',
    'empty string' => '',
    'word' => 'abc',
    'above the maximum' => IntegerAmount::MAXIMUM + 1,
    'too many digits' => '10000000000000',
    'null' => null,
    'boolean' => true,
    'array' => [[4_500_000]],
]);
