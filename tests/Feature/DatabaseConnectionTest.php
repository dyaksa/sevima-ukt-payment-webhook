<?php

use Illuminate\Support\Facades\DB;

test('the suite runs on the PostgreSQL testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');
});
