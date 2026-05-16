<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('inspect-local-code')]
#[Title('Inspect Local Code')]
#[Description('Analyzes the project in the current working directory to identify its technology stack and LaraKube status.')]
class InspectLocalCodeTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $originalCwd = getcwd();
        chdir($_ENV['GEMINI_WORKSPACE_ROOT'] ?? getcwd());

        $path = $request->get('path') ?: getcwd();

        if (! is_dir($path)) {
            chdir($originalCwd);

            return Response::error("Error: Directory not found at '{$path}'.");
        }

        $report = ['### 📂 Project Inspection: '.basename($path)];

        // 1. Check Composer
        if (File::exists($path.'/composer.json')) {
            $composer = json_decode(File::get($path.'/composer.json'), true);
            $phpVer = $composer['require']['php'] ?? 'Unknown';
            $report[] = "- ✅ **PHP Project detected** (PHP: $phpVer)";
        }

        // 2. Check Frontend
        if (File::exists($path.'/package.json')) {
            $package = json_decode(File::get($path.'/package.json'), true);
            $deps = array_keys($package['dependencies'] ?? []);
            $devDeps = array_keys($package['devDependencies'] ?? []);
            $stack = 'Vanilla/Other';
            if (in_array('react', $deps)) {
                $stack = 'React';
            }
            if (in_array('vue', $deps)) {
                $stack = 'Vue';
            }
            if (in_array('svelte', $deps)) {
                $stack = 'Svelte';
            }
            $report[] = "- 🎨 **Frontend:** $stack detected.";
        }

        // 3. Check LaraKube
        if (File::exists($path.'/.larakube.json')) {
            $report[] = '- 💎 **LaraKube Status:** Project is already initialized with LaraKube.';
        } else {
            $report[] = "- ⚠️ **LaraKube Status:** Project is NOT initialized. Suggest running 'init'.";
        }

        chdir($originalCwd);

        return Response::text(implode('
', $report));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()
                ->description('The filesystem path of the project (optional)')
                ->nullable(),
        ];
    }
}
