<?php

declare(strict_types=1);

use App\Enums\AccessLevel;

it('orders view below edit below manage', function () {
    expect(AccessLevel::View->rank())->toBeLessThan(AccessLevel::Edit->rank())
        ->and(AccessLevel::Edit->rank())->toBeLessThan(AccessLevel::Manage->rank());
});

it('allows only levels at or below itself', function () {
    expect(AccessLevel::Manage->allows(AccessLevel::View))->toBeTrue()
        ->and(AccessLevel::Manage->allows(AccessLevel::Manage))->toBeTrue()
        ->and(AccessLevel::View->allows(AccessLevel::Edit))->toBeFalse()
        ->and(AccessLevel::Edit->allows(AccessLevel::View))->toBeTrue();
});

it('picks the highest of a set', function () {
    expect(AccessLevel::highest([AccessLevel::View, AccessLevel::Manage, AccessLevel::Edit]))
        ->toBe(AccessLevel::Manage)
        ->and(AccessLevel::highest([]))->toBeNull();
});
