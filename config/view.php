<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    // Compiled Blade views. Inside a PHAR we must write to a writable temp
    // dir, but it MUST be namespaced per binary build — otherwise an upgraded
    // binary reuses the previous version's compiled views (Blade's
    // source-vs-compiled mtime freshness check is unreliable across PHAR
    // upgrades), causing "Undefined property" errors when a template changed.
    // Keying the directory on the PHAR file's mtime gives each build its own
    // cache and forces a clean recompile after every install/rebuild.
    'compiled' => env(
        'VIEW_COMPILED_PATH',
        (static function () {
            $pharPath = Phar::running(false);

            return $pharPath !== ''
                ? sys_get_temp_dir().'/larakube-views-'.filemtime($pharPath)
                : storage_path('framework/views');
        })(),
    ),

];
