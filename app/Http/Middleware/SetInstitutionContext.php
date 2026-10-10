<?php

namespace App\Http\Middleware;

use App\Models\Scopes\InstitutionScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's institution to the request, so the
 * institution scope can filter every query. It runs after authentication and
 * before route model binding (see the priority list in bootstrap/app.php).
 */
class SetInstitutionContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $institutionId = $request->user()?->institution_id;

        if ($institutionId !== null) {
            Context::add(InstitutionScope::CONTEXT_KEY, $institutionId);
        }

        return $next($request);
    }
}
