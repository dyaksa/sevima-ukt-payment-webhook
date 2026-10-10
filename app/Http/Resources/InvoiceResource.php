<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'invoice_number' => $this->invoice_number,
            'student_number' => $this->student_number,
            'semester' => $this->semester,
            'amount' => $this->amount,
            'status' => $this->status,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
        ];
    }
}
