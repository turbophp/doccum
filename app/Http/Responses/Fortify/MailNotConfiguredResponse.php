<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one response both password-reset-link wrapper classes in this
 * namespace fall back to when App\Support\MailDeliverability::unavailable()
 * is true.
 *
 * Both wrappers reach this from a different branch of
 * Illuminate\Auth\Passwords\PasswordBroker::sendResetLink() -- one because
 * a user matched, one because none did -- and a single shared
 * implementation is the only way to guarantee they produce byte-identical
 * output. Two independently written "same message" strings drift; a
 * shared call site cannot. The message states a fact about this instance
 * (its mailer cannot deliver), never about the submitted address, which is
 * what keeps this from becoming a second way to learn whether an account
 * exists on top of whatever Laravel's own default behaviour already does
 * or does not reveal.
 */
final class MailNotConfiguredResponse implements Responsable
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $message = __('Mail is not configured on this server, so nothing was actually sent. Ask an administrator to run the doccum:user:reset-password command for a one-time reset link instead.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
