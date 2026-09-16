<?php

declare(strict_types=1);

namespace App\Enums;

enum AppliesTo: string
{
    case Directory = 'directory';
    case File = 'file';
    case Both = 'both';

    public function includes(string $subject): bool
    {
        return $this === self::Both || $this->value === $subject;
    }
}
