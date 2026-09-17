<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Whether the instance's configured mailer can actually deliver anywhere a
 * human will see it.
 *
 * "log" writes the message to storage/logs and "array" only holds it in
 * memory for the life of the request -- both accept every message without
 * error, so nothing downstream (a queued job, a controller) can tell
 * "delivered" apart from "wrote it nowhere anyone will read." A stock
 * container's fresh default is MAIL_MAILER=log (see config/mail.php), so
 * an unconfigured install has no real mailer until an operator points one
 * at it.
 *
 * Reads the resolved mailer's own transport rather than its config key, so
 * an install that renames the "log" entry, or points a mailer literally
 * named "log" at a real transport, is judged by what it does rather than
 * by what it is called.
 */
final class MailDeliverability
{
    /**
     * @var list<string>
     */
    private const NON_DELIVERING_TRANSPORTS = ['log', 'array'];

    public static function unavailable(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport");

        return in_array($transport, self::NON_DELIVERING_TRANSPORTS, true);
    }
}
