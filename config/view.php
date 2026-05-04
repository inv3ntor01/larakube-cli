<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        Phar::running() ? sys_get_temp_dir() : storage_path('framework/views')
    ),

];
