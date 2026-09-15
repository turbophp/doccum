<?php

declare(strict_types=1);

use App\Models\File;
use App\Support\ObjectKey;

/**
 * Built directly rather than through the factory: ObjectKey is pure, and this
 * test proves it needs no container, no database, and no booted application.
 */
function makeFile(string $uuid, int $year, int $month): File
{
    return new File([
        'uuid' => $uuid,
        'period_year' => $year,
        'period_month' => $month,
    ]);
}

it('builds a version key from period, uuid, and version', function () {
    $file = makeFile('0195f0c2-8b3a-7000-9000-000000000001', 2024, 3);

    expect(ObjectKey::forVersion($file, 2, 'Report.pdf'))
        ->toBe('files/2024/03/0195f0c2-8b3a-7000-9000-000000000001/v2/Report.pdf');
});

it('zero pads a single digit month', function () {
    $file = makeFile('0195f0c2-8b3a-7000-9000-000000000002', 2026, 9);

    expect(ObjectKey::forVersion($file, 1, 'a.txt'))->toStartWith('files/2026/09/');
});

it('strips directory traversal from the filename', function () {
    $file = makeFile('0195f0c2-8b3a-7000-9000-000000000003', 2026, 1);

    expect(ObjectKey::forVersion($file, 1, '../../etc/passwd'))->toEndWith('/v1/passwd')
        ->and(ObjectKey::forVersion($file, 1, 'a/b/c.txt'))->toEndWith('/v1/c.txt');
});

it('replaces characters that are awkward in object keys', function () {
    $file = makeFile('0195f0c2-8b3a-7000-9000-000000000004', 2026, 1);

    expect(ObjectKey::forVersion($file, 1, 'in?voice#1.pdf'))->toEndWith('/v1/in_voice_1.pdf');
});

it('falls back to a placeholder for an empty filename', function () {
    $file = makeFile('0195f0c2-8b3a-7000-9000-000000000005', 2026, 1);

    expect(ObjectKey::forVersion($file, 1, '???'))->toEndWith('/v1/file');
});

it('builds a staging key outside the files prefix', function () {
    $key = ObjectKey::staging('0195f0c2-8b3a-7000-9000-000000000006', 'Report.pdf');

    expect($key)->toBe('uploads/0195f0c2-8b3a-7000-9000-000000000006/Report.pdf')
        ->and($key)->not->toStartWith('files/');
});

it('builds period prefixes for a month and a whole year', function () {
    expect(ObjectKey::periodPrefix(2024, 3))->toBe('files/2024/03/')
        ->and(ObjectKey::periodPrefix(2024))->toBe('files/2024/');
});
