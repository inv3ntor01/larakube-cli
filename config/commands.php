<?php

use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Database\Console\DbSeedCommand;
use Illuminate\Database\Console\Factories\FactoryMakeCommand;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand;
use Illuminate\Database\Console\Seeds\SeederMakeCommand;
use Illuminate\Foundation\Console\ComponentMakeCommand;
use Illuminate\Foundation\Console\ConsoleMakeCommand;
use Illuminate\Foundation\Console\ModelMakeCommand;
use Illuminate\Foundation\Console\TestMakeCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Laravel\Ai\Console\Commands\MakeAgentCommand;
use Laravel\Ai\Console\Commands\MakeAgentMiddlewareCommand;
use Laravel\Ai\Console\Commands\MakeToolCommand;
use Laravel\Mcp\Console\Commands\InspectorCommand;
use Laravel\Mcp\Console\Commands\MakePromptCommand;
use Laravel\Mcp\Console\Commands\MakeResourceCommand;
use Laravel\Mcp\Console\Commands\MakeServerCommand;
use Laravel\Mcp\Console\Commands\MakeToolCommand as McpMakeToolCommand;
use Laravel\Mcp\Console\Commands\StartCommand;
use LaravelZero\Framework\Commands\BuildCommand;
use LaravelZero\Framework\Commands\InstallCommand;
use LaravelZero\Framework\Commands\RenameCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Command
    |--------------------------------------------------------------------------
    */

    'default' => SummaryCommand::class,

    /*
    |--------------------------------------------------------------------------
    | Commands Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [app_path('Commands')],

    /*
    |--------------------------------------------------------------------------
    | Added Commands
    |--------------------------------------------------------------------------
    */

    'add' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Hidden Commands
    |--------------------------------------------------------------------------
    */

    'hidden' => [
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,

        // Hide standard Laravel/PHP extensions
        FreshCommand::class,
        Illuminate\Database\Console\Migrations\InstallCommand::class,
        RefreshCommand::class,
        ResetCommand::class,
        RollbackCommand::class,
        StatusCommand::class,
        ComponentMakeCommand::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Removed Commands
    |--------------------------------------------------------------------------
    */

    'remove' => (Phar::running() !== '') ? [
        // Remove build and rename tools ONLY from the standalone binary
        BuildCommand::class,
        RenameCommand::class,
        InstallCommand::class,

        // Remove all make: commands
        ConsoleMakeCommand::class,
        MigrateMakeCommand::class,
        TestMakeCommand::class,
        FactoryMakeCommand::class,
        SeederMakeCommand::class,
        ModelMakeCommand::class,

        // Hide AI/Agent and MCP development tools
        MakeAgentCommand::class,
        MakeAgentMiddlewareCommand::class,
        MakeToolCommand::class,
        InspectorCommand::class,
        MakePromptCommand::class,
        MakeResourceCommand::class,
        MakeServerCommand::class,
        McpMakeToolCommand::class,
        StartCommand::class,

        // Remove seeding as it's not needed in the binary
        DbSeedCommand::class,
    ] : [],
];
