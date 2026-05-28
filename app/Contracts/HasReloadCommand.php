<?php

namespace App\Contracts;

interface HasReloadCommand
{
    /**
     * The in-pod command (typically artisan) that triggers a PHP code reload.
     * Return null if this component does not require an explicit reload step
     * (PHP-FPM rereads files per request, cron jobs spawn fresh PHP).
     */
    public function getReloadCommand(): ?string;
}
