<?php

namespace App\Models;

use App\Enums\TransactionKind;
use App\Enums\TransactionSource;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One money event of an invoice. The ledger is append-only: rows are never
 * updated or deleted, so it has no `updated_at`.
 */
#[Fillable(['invoice_id', 'idempotency_key', 'kind', 'amount', 'occurred_at', 'gateway_payment_id', 'source'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToInstitution, HasFactory, HasUuids;

    public const null UPDATED_AT = null;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'source' => TransactionSource::class,
        ];
    }
}
