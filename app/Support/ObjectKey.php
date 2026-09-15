<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Str;

/**
 * Builds MinIO object keys.
 *
 * Every version of a file lives under the file's creation period, so purging a
 * period is a single coherent prefix operation and can never leave one version
 * deleted while another survives in a different year. See spec §6.
 *
 * Pure by design -- no I/O, no container -- so archive and purge can reason
 * about prefixes without touching storage.
 */
final class ObjectKey
{
    public static function forVersion(File $file, int $versionNumber, string $filename): string
    {
        return sprintf(
            'files/%04d/%02d/%s/v%d/%s',
            $file->period_year,
            $file->period_month,
            $file->uuid,
            $versionNumber,
            self::sanitise($filename),
        );
    }

    /**
     * Staging area for presigned uploads that have not been committed yet.
     *
     * Deliberately outside the files/ prefix: an abandoned upload must never
     * land inside a period, or it would corrupt the counts recorded when that
     * period closes and confuse purge about what it is deleting.
     */
    public static function staging(string $uploadUuid, string $filename): string
    {
        return sprintf('uploads/%s/%s', $uploadUuid, self::sanitise($filename));
    }

    public static function periodPrefix(int $year, ?int $month = null): string
    {
        return $month === null
            ? sprintf('files/%04d/', $year)
            : sprintf('files/%04d/%02d/', $year, $month);
    }

    private static function sanitise(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? '';
        $name = trim($name, ' _');

        return $name === '' ? 'file' : Str::limit($name, 180, '');
    }
}
