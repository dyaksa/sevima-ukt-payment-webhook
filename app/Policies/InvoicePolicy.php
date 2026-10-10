<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The second isolation layer behind the institution scope. A denial looks
 * exactly like a missing record (404), so it never confirms that another
 * institution's invoice exists.
 */
class InvoicePolicy
{
    /**
     * Determine whether the user can view the invoice.
     */
    public function view(User $user, Invoice $invoice): Response
    {
        return $user->institution_id === $invoice->institution_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
