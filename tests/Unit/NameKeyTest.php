<?php

declare(strict_types=1);

use App\Support\NameKey;

it('is pure and lowercases plain ascii', function () {
    expect(NameKey::of('Report.pdf'))->toBe('report.pdf');
});

it('treats names differing only by case as the same key', function () {
    expect(NameKey::of('Report.pdf'))->toBe(NameKey::of('report.pdf'));
});

it('treats names differing by an accent as different keys', function () {
    // "\u{00E9}" is U+00E9 LATIN SMALL LETTER E WITH ACUTE, the single
    // precomposed codepoint -- the NFC form of "e" + combining acute.
    $accented = "r\u{00E9}sum\u{00E9}.pdf";

    expect(NameKey::of($accented))->not->toBe(NameKey::of('resume.pdf'));
});

it('normalises NFD input to the same key as its NFC equivalent', function () {
    // NFC: "e" with the precomposed acute-accent codepoint U+00E9.
    $nfc = "r\u{00E9}sum\u{00E9}.pdf";

    // NFD: the same abstract characters, but each accented "e" written as the
    // bare letter U+0065 followed by the standalone combining acute accent
    // U+0301 -- the form macOS clients have historically submitted.
    $nfd = "re\u{0301}sume\u{0301}.pdf";

    expect($nfc)->not->toBe($nfd) // different bytes going in...
        ->and(NameKey::of($nfc))->toBe(NameKey::of($nfd)); // ...same key coming out.
});

it('lowercases after normalising, not before', function () {
    $nfdUppercase = "R\u{0045}\u{0301}SUM\u{0045}\u{0301}.PDF"; // "RÉSUMÉ.PDF" in NFD

    expect(NameKey::of($nfdUppercase))->toBe(NameKey::of("r\u{00E9}sum\u{00E9}.pdf"));
});

it('falls back to the raw string when normalisation reports malformed input', function () {
    // An invalid UTF-8 byte sequence: a continuation byte with no leader.
    // Normalizer::normalize() returns false for this, so NameKey::of() must
    // fall back to the original string rather than throwing -- calling it
    // directly here (not through a closure passed to toThrow(), which
    // branches on class_exists() and silently no-ops for an interface like
    // Throwable) is what actually proves it completes normally.
    $malformed = "broken\x80name.pdf";

    $result = NameKey::of($malformed);

    expect($result)->toBeString();
});
