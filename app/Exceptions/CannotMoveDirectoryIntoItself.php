<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class CannotMoveDirectoryIntoItself extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A directory cannot be moved into itself or one of its own descendants.');
    }
}
