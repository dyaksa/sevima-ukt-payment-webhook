<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when an institution-scoped model is queried before the institution
 * of the current request (or command) is known, so a query can never fall
 * back to returning every institution's rows.
 */
class MissingInstitutionContextException extends LogicException
{
    public function __construct()
    {
        parent::__construct('An institution-scoped model was queried without an institution context.');
    }
}
