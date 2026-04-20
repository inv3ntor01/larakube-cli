<?php

namespace App\Traits;

use App\State;

use function Termwind\render;

trait LaraKubeOutput
{
    use InteractsWithGlobalConfig;

    /**
     * Render the LaraKube header.
     */
    protected function renderHeader(): void
    {
        if (State::$headerRendered) {
            return;
        }

        $lines = [
            ' ██╗      █████╗ ██████╗  █████╗ ██╗  ██╗██╗   ██╗██████╗ ███████╗',
            ' ██║     ██╔══██╗██╔══██╗██╔══██╗██║ ██╔╝██║   ██║██╔══██╗██╔════╝',
            ' ██║     ███████║██████╔╝███████║█████╔╝ ██║   ██║██████╔╝█████╗  ',
            ' ██║     ██╔══██║██╔══██╗██╔══██║██╔═██╗ ██║   ██║██╔══██╗██╔══╝  ',
            ' ███████╗██║  ██║██║  ██║██║  ██║██║  ██╗╚██████╔╝██████╔╝███████╗',
            ' ╚══════╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝ ╚═════╝ ╚═════╝ ╚══════╝',
        ];

        $gradients = [
            'Nordic' => [31, 31, 24, 24, 24, 23],
            'Slate' => [244, 242, 241, 240, 239, 238],
            'DeepSea' => [25, 25, 19, 18, 18, 17],
            'Forest' => [28, 28, 22, 22, 22, 22],
        ];

        $themeName = array_rand($gradients);
        $gradient = $gradients[$themeName];

        echo "\n";
        foreach ($lines as $index => $line) {
            $color = $gradient[$index] ?? 240;
            echo "  \e[38;5;{$color}m{$line}\e[0m\n";
        }

        render(<<<'HTML'
            <div class="mx-2 mt-2">
                <div class="px-2 py-0.5 bg-blue-900 text-blue-200 font-bold uppercase w-66 justify-center">
                    Kubernetes for Laravel from Development to Deployment
                </div>
            </div>
        HTML);

        State::$headerRendered = true;
    }

    /**
     * Render a LaraKube info line.
     */
    protected function laraKubeInfo(string $message): void
    {
        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-blue-500 text-white font-bold">LARAKUBE</span>
                <span class="ml-1 text-blue-500">{$message}</span>
            </div>
        HTML);
    }

    /**
     * Render a polite GitHub star prompt once a week.
     */
    protected function renderStarPrompt(): void
    {
        $config = $this->getGlobalConfig();
        $lastShown = $config['last_starred_prompt_at'] ?? 0;
        $oneWeekAgo = time() - (7 * 24 * 60 * 60);

        if ($lastShown > $oneWeekAgo) {
            return;
        }

        $this->line('');
        $this->line('  <fg=yellow;options=bold>⭐ Enjoying LaraKube?</> If this tool helped you build a masterpiece, please consider starring us on GitHub:');
        $this->line('  <fg=gray>● CLI:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-cli</>');
        $this->line('  <fg=gray>● Docs:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-docs</>');
        $this->line('');

        $config['last_starred_prompt_at'] = time();
        $this->setGlobalConfig($config);
    }

    /**
     * Render a LaraKube error line.
     */
    protected function laraKubeError(string $message): void
    {
        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-red-500 text-white font-bold">LARAKUBE</span>
                <span class="ml-1 text-red-500">{$message}</span>
            </div>
        HTML);
    }

    /**
     * Run a task with a spinner.
     */
    protected function withSpin(string $message, callable $callback): mixed
    {
        return $this->task($message, $callback);
    }
}
