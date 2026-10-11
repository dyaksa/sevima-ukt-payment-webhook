<?php

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;

it('supports Laravel resource collections without treating collection keys as annotations', function () {
    $transaction = Transaction::factory()->withoutParents()->make(['amount' => 4_500_000]);

    $response = TransactionResource::collection(collect([$transaction]))->response()->getData(true);

    expect($response['data'][0]['amount'])->toBe(4_500_000)
        ->and($response['data'][0]['annotation'])->toBeNull();
});
