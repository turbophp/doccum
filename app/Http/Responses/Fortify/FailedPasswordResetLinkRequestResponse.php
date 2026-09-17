<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Support\MailDeliverability;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse as StockFailedPasswordResetLinkRequestResponse;

/**
 * Wraps Fortify's stock "no reset link sent" response so that, when mail
 * cannot deliver anyway (App\Support\MailDeliverability::unavailable()),
 * it says exactly that instead of Laravel's normal "we can't find a user
 * with that email address" -- which would otherwise keep working as an
 * account-existence oracle even after
 * App\Http\Responses\Fortify\SuccessfulPasswordResetLinkRequestResponse
 * stops claiming success on the other branch.
 *
 * Falls back to the stock response, unchanged, once mail is actually
 * configured: this wrapper only ever narrows what gets shown, it never
 * changes throttling or validation-error behaviour that has nothing to do
 * with mail deliverability.
 *
 * Registered in App\Providers\DoccumServiceProvider in place of Fortify's
 * own binding for Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse.
 */
class FailedPasswordResetLinkRequestResponse implements FailedPasswordResetLinkRequestResponseContract
{
    public function __construct(private readonly string $status)
    {
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        if (MailDeliverability::unavailable()) {
            return (new MailNotConfiguredResponse())->toResponse($request);
        }

        return (new StockFailedPasswordResetLinkRequestResponse($this->status))->toResponse($request);
    }
}
