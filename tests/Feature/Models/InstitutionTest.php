<?php

use App\Models\Institution;
use Illuminate\Support\Facades\DB;

it('rejects a change to the institution code', function () {
    $institution = Institution::factory()->create(['code' => 'UNIV-A']);

    expect(fn () => $institution->update(['code' => 'UNIV-Z']))
        ->toThrow(LogicException::class, 'An institution code cannot be changed.');

    expect($institution->fresh()->code)->toBe('UNIV-A');
});

it('stores the hmac secret encrypted and keeps it out of serialization', function () {
    $secret = bin2hex(random_bytes(32));
    $institution = Institution::factory()->create(['hmac_secret' => $secret]);

    expect(DB::table('institutions')->where('id', $institution->id)->value('hmac_secret'))->not->toBe($secret)
        ->and($institution->fresh()->hmac_secret)->toBe($secret)
        ->and($institution->toArray())->not->toHaveKey('hmac_secret');
});
