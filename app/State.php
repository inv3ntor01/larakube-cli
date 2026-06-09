<?php

namespace App;

class State
{
    public static bool $headerRendered = false;

    /**
     * Sensitive values registered for redaction in CLI output (keyed by value).
     * Lives here, not on the LaraKubeOutput trait, because that trait is also
     * mixed into the driver enums — and PHP enums may not declare properties.
     *
     * @var array<string, true>
     */
    public static array $registeredSecrets = [];
}
