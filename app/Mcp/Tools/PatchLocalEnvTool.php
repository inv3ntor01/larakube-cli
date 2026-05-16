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

#[Name('patch-local-env')]
#[Title('Patch Local Env')]
#[Description("Updates or adds a key-value pair in the project's local .env file.")]
class PatchLocalEnvTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $originalCwd = getcwd();
        chdir($_ENV['GEMINI_WORKSPACE_ROOT'] ?? getcwd());

        $key = $request->get('key');
        $value = $request->get('value');
        $path = $request->get('path') ?: getcwd();

        if (! is_dir($path)) {
            chdir($originalCwd);

            return Response::error("Error: Project directory not found at '{$path}'.");
        }

        $envPath = $path.'/.env';

        if (! File::exists($envPath)) {
            chdir($originalCwd);

            return Response::error('Error: .env file not found in '.$path);
        }

        $content = File::get($envPath);
        $newContent = $content;

        if (preg_match("/^{$key}=.*/m", $content)) {
            $newContent = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $content);
        } else {
            $newContent .= "\n{$key}={$value}\n";
        }

        File::put($envPath, $newContent);
        chdir($originalCwd);

        return Response::text("✅ Successfully patched .env: {$key} is now set to '{$value}'.");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('The environment variable key (e.g. DB_HOST)'),
            'value' => $schema->string()->description('The new value for the key'),
            'path' => $schema->string()->description('The filesystem path of the project (optional)')->nullable(),
        ];
    }
}
