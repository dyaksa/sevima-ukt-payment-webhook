<?php

namespace App\Models;

use App\Enums\WebhookOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit row for one received notification.
 *
 * Unlike the domain models it is not institution-scoped: rows are written
 * before the institution is known (unknown code, bad signature), so the
 * institution is set explicitly and may be empty. The application only
 * writes these rows; it never reads them.
 */
#[Fillable(['institution_id', 'institution_code', 'outcome', 'event', 'idempotency_key', 'invoice_number', 'raw_body'])]
class WebhookLog extends Model
{
    use HasUuids;

    public const null UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
        ];
    }
}
