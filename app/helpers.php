<?php

if (! function_exists('home_path')) {
    /**
     * Get the path to the user's home directory.
     */
    function home_path(string $path = ''): string
    {
        $home = $_SERVER['HOME'] ?? ($_SERVER['HOMEDRIVE'] ?? '').($_SERVER['HOMEPATH'] ?? '');

        return $home.($path ? DIRECTORY_SEPARATOR.$path : $path);
    }
}
