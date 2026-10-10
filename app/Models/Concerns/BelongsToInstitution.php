<?php

namespace App\Models\Concerns;

use App\Models\Institution;
use App\Models\Scopes\InstitutionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes every query of the model to the current institution and stamps new
 * rows with it, so application code never chooses the institution itself.
 */
trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope);

        static::creating(function (Model $model): void {
            $model->institution_id ??= InstitutionScope::currentInstitutionId();
        });
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
