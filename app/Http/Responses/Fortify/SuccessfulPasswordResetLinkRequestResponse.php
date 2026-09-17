<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Support\MailDeliverability;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse as StockSuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps Fortify's stock "reset link sent" response so it never claims to
 * have emailed anything a "log" or "array" mailer only wrote to a file or
 * held in memory. See App\Support\MailDeliverability.
 *
 * This class runs only on the branch where
 * Illuminate\Auth\Passwords\PasswordBroker::sendResetLink() found a
 * matching user and "sent" the link -- but the check that decides what to
 * print reads only the instance's mail configuration, never that fact, so
 * the sentence shown here carries no signal beyond what
 * App\Http\Responses\Fortify\FailedPasswordResetLinkRequestResponse also
 * shows for the branch where no user matched at all. See
 * App\Http\Responses\Fortify\MailNotConfiguredResponse for why one shared
 * class, not two similar strings, produces that.
 *
 * Registered in App\Providers\DoccumServiceProvider in place of Fortify's
 * own binding for Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse.
 */
class SuccessfulPasswordResetLinkRequestResponse implements SuccessfulPasswordResetLinkRequestResponseContract
{
    public function __construct(private readonly string $status) {}

    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if (MailDeliverability::unavailable()) {
            return (new MailNotConfiguredResponse)->toResponse($request);
        }

        return (new StockSuccessfulPasswordResetLinkRequestResponse($this->status))->toResponse($request);
    }
}
