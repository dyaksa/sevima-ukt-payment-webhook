<?php

namespace App\Enums;

enum TokenAbility: string
{
    case InvoicesRead = 'invoices:read';
    case InvoicesWrite = 'invoices:write';

    /**
     * The route middleware that requires this ability on the current token.
     */
    public function middleware(): string
    {
        return 'abilities:'.$this->value;
    }
}
