<?php

namespace App\Http\Middleware;

use App\Actions\RecordWebhookLog;
use App\Enums\WebhookOutcome;
use App\Exceptions\InvalidWebhookSignatureException;
use App\Models\Institution;
use App\Models\Scopes\InstitutionScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a gateway notification: the `X-Signature` header must be the
 * HMAC-SHA256 of the raw body under the secret of the institution named in
 * `X-Institution-Code`. Only then is the institution context set; the body is
 * not read as data before that.
 */
class VerifyWebhookSignature
{
    public function __construct(private RecordWebhookLog $recordWebhookLog) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws InvalidWebhookSignatureException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $code = (string) $request->header('X-Institution-Code');
        $institution = $code === '' ? null : Institution::firstWhere('code', $code);

        // The HMAC is computed even for an unknown code, so both rejections cost the same.
        $expectedSignature = hash_hmac('sha256', $request->getContent(), $institution->hmac_secret ?? '');

        if ($institution === null || ! hash_equals($expectedSignature, (string) $request->header('X-Signature'))) {
            $this->recordWebhookLog->handle(WebhookOutcome::Unauthorized, $institution?->id, $code === '' ? null : $code, $request->getContent());

            throw new InvalidWebhookSignatureException;
        }

        Context::add(InstitutionScope::CONTEXT_KEY, $institution->id);
        $request->attributes->set(Institution::class, $institution);

        return $next($request);
    }
}
