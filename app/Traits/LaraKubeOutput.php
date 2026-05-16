<?php

namespace App\Traits;

use App\State;
use Illuminate\Support\Carbon;

use function Termwind\render;

trait LaraKubeOutput
{
    use InteractsWithGlobalConfig;

    /**
     * Render the LaraKube header.
     */
    protected function renderHeader(): void
    {
        if ($this->isAiAgent() || State::$headerRendered) {
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
                    The Professional Kubernetes Orchestrator for Laravel
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
        if ($this->isAiAgent()) {
            return;
        }

        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-blue-500 text-white font-bold uppercase">LaraKube</span>
                <span class="ml-1 text-blue-500">{$message}</span>
            </div>
        HTML);
    }

    /**
     * Render a blank line.
     */
    protected function laraKubeNewLine(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            echo "\n";
        }
    }

    /**
     * Render a raw line of text.
     */
    protected function laraKubeLine(string $message): void
    {
        echo "  $message\n";
    }

    /**
     * Render a warning line.
     */
    protected function laraKubeWarn(string $message): void
    {
        render("<div class='mx-2 mt-1 text-yellow-500'>$message</div>");
    }

    /**
     * Determine if the CLI is running inside an AI agent environment.
     */
    protected function isAiAgent(): bool
    {
        return env('AI_AGENT') === 'true' ||
               env('CURSOR') === 'true' ||
               env('GEMINI_CLI') === 'true' ||
               env('LARAKUBE_JSON') === '1' ||
               str_contains(implode(' ', $_SERVER['argv'] ?? []), 'mcp:start');
    }

    /**
     * Render a JSON response for AI agents.
     */
    protected function renderJson(array $data): int
    {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        return 0;
    }

    /**
     * Render a polite GitHub star prompt once a week.
     */
    protected function renderStarPrompt(): void
    {
        $config = $this->getGlobalConfig();

        $lastShown = $config->getLastStarPromptAt();

        if (! $lastShown || $lastShown->diffInWeeks() > 1) {
            $this->newLine();
            $this->line('  <fg=yellow;options=bold>⭐ Enjoying LaraKube?</> If this tool helped you build a masterpiece, please consider starring us on GitHub:');
            $this->line('  <fg=gray>● CLI:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-cli</>');
            $this->line('  <fg=gray>● Console:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-console</>');
            $this->line('  <fg=gray>● Docs:</> <fg=blue;options=underscore>https://github.com/luchavez-technologies/larakube-docs</>');
            $this->newLine();
            $this->line('  <fg=magenta;options=bold>💖 Support the project:</> <fg=blue;options=underscore>https://github.com/sponsors/luchavez-technologies</>');
            $this->newLine();

            $config->setLastStarPromptAt(Carbon::now());
            $config->save();
        }
    }

    /**
     * Render a LaraKube error line.
     */
    protected function laraKubeError(string $message): void
    {
        render(<<<HTML
            <div class="flex mx-2 mt-1">
                <span class="px-1 bg-red-500 text-white font-bold uppercase">LaraKube</span>
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
