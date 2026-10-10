<?php

use App\Models\Concerns\BelongsToInstitution;

arch('domain models are scoped to an institution')
    ->expect('App\Models')
    ->classes()
    ->toUseTrait(BelongsToInstitution::class)
    ->ignoring(['App\Models\Institution', 'App\Models\User', 'App\Models\Scopes']);
