<?php

namespace App\Traits;

trait DetectsWsl
{
    /**
     * Whether we're running inside WSL — where the Windows side (hosts file and
     * certificate trust store) must be targeted too, not just the Linux ones.
     *
     * Matches WSL2 (lowercase "microsoft" in /proc/version, e.g.
     * "…-microsoft-standard-WSL2") as well as WSL1 ("Microsoft"). The previous
     * case-sensitive `str_contains(..., 'Microsoft')` checks missed WSL2
     * entirely — so `larakube trust` installed the CA into the Linux store
     * instead of the Windows Root store, and the Windows browser never trusted
     * the local HTTPS cert.
     */
    protected function isWsl(): bool
    {
        if (getenv('WSL_DISTRO_NAME')) {
            return true;
        }

        return is_file('/proc/version')
            && str_contains(strtolower((string) @file_get_contents('/proc/version')), 'microsoft');
    }
}
