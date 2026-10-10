<?php

namespace App\Models\Scopes;

use App\Exceptions\MissingInstitutionContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Context;

class InstitutionScope implements Scope
{
    /**
     * The Context key that carries the current institution id. It is a visible
     * key, so every log line written during the request includes it.
     */
    public const string CONTEXT_KEY = 'institution_id';

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('institution_id'), self::currentInstitutionId());
    }

    /**
     * The id of the institution the current request acts for.
     *
     * @throws MissingInstitutionContextException
     */
    public static function currentInstitutionId(): string
    {
        $institutionId = Context::get(self::CONTEXT_KEY);

        if ($institutionId === null) {
            throw new MissingInstitutionContextException;
        }

        return $institutionId;
    }
}
