<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessLevel: string
{
    case View = 'view';
    case Edit = 'edit';
    case Manage = 'manage';

    public function rank(): int
    {
        return match ($this) {
            self::View => 1,
            self::Edit => 2,
            self::Manage => 3,
        };
    }

    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    /** @param  array<int, self>  $levels */
    public static function highest(array $levels): ?self
    {
        return array_reduce(
            $levels,
            static fn (?self $carry, self $level): self => $carry === null || $level->rank() > $carry->rank() ? $level : $carry,
        );
    }
}
