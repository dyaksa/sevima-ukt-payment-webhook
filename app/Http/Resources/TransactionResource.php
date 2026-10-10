<?php

namespace App\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->kind,
            'amount' => $this->amount,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'gateway_payment_id' => $this->gateway_payment_id,
            'source' => $this->source,
            'idempotency_key' => $this->idempotency_key,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
