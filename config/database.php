<?php

$home = $_SERVER['HOME'] ?? getenv('HOME');
$dbPath = $home.'/.larakube/database.sqlite';

return [

    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => $dbPath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],

    'migrations' => 'migrations',

];
