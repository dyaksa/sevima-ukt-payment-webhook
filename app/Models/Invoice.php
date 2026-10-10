<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToInstitution;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `institution_id` is deliberately not fillable: it always comes from the
 * institution context, never from request data.
 */
#[Fillable(['invoice_number', 'student_number', 'semester', 'amount', 'status', 'expires_at'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToInstitution, HasFactory, HasUuids;

    /**
     * The ledger in event order; the time-ordered id breaks ties.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('occurred_at')->orderBy('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => InvoiceStatus::class,
            'expires_at' => 'datetime',
        ];
    }
}
