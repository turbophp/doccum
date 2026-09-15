<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Removes known secret values from text that is about to be shown or logged.
 *
 * Driver and SDK exceptions sometimes quote the credentials they were given.
 * PDO marks passwords with #[SensitiveParameter] and so keeps them out of its
 * own messages, but that is a guarantee of one driver, not of every client this
 * application will ever talk to -- S3, search engines and OCR services all
 * raise their own errors. Redaction is the belt to that braces.
 */
final class Redact
{
    /**
     * Values shorter than this are ignored: redacting a two-character secret
     * would blank out unrelated fragments of the message and make the error
     * useless without protecting anything meaningful.
     */
    private const MIN_LENGTH = 4;

    /** @param  array<int, mixed>  $secrets */
    public static function secrets(string $text, array $secrets, string $replacement = '[redacted]'): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= self::MIN_LENGTH) {
                $text = str_replace($secret, $replacement, $text);
            }
        }

        return $text;
    }
}
