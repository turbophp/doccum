<?php

declare(strict_types=1);

use App\Support\MailDeliverability;

/**
 * See App\Support\MailDeliverability's own docblock: the whole point of the
 * class is that it judges the resolved mailer's TRANSPORT, never the mailer
 * entry's NAME, so a stock "log" mailer and an operator's mailer that merely
 * happens to be named "log" but points at smtp must be told apart. Every
 * test here that renames a mailer relative to its transport exists to prove
 * that distinction, not the obvious in_array() cases alone.
 */
it('treats log and array transports as unable to deliver anywhere a human will see it', function (string $transport) {
    config()->set('mail.default', $transport);

    expect(MailDeliverability::unavailable())->toBeTrue();
})->with(['log', 'array']);

it('treats a real transport as able to deliver', function () {
    config()->set('mail.default', 'smtp');

    expect(MailDeliverability::unavailable())->toBeFalse();
});

it('judges a mailer by its resolved transport, not by what the mailer entry is called', function () {
    // A mailer literally named "log" repointed at a real transport must read
    // as available -- if this read the config KEY "log" instead of the
    // resolved transport, this would still say unavailable.
    config()->set('mail.mailers.log.transport', 'smtp');
    config()->set('mail.default', 'log');

    expect(MailDeliverability::unavailable())->toBeFalse();
});

it('judges a mailer by its resolved transport even when nothing calls it "log" or "array"', function () {
    // The inverse: a mailer named something else entirely, but whose
    // transport is "log", must still read as unavailable -- if this
    // branched on the mailer NAME, a rename would silently defeat it.
    config()->set('mail.mailers.primary.transport', 'log');
    config()->set('mail.default', 'primary');

    expect(MailDeliverability::unavailable())->toBeTrue();
});

it('does not error when the configured default mailer has no matching mailers entry at all', function () {
    config()->set('mail.default', 'this-mailer-does-not-exist');

    expect(MailDeliverability::unavailable())->toBeFalse();
});

it('does not error when the resolved mailer config is missing its transport key', function () {
    config()->set('mail.mailers.custom', ['url' => null]);
    config()->set('mail.default', 'custom');

    expect(MailDeliverability::unavailable())->toBeFalse();
});
