<?php

namespace App\Traits;

trait InteractsWithOs
{
    protected function isDarwin(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    protected function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    protected function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }
}
