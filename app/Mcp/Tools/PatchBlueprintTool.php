<?php

namespace App\Mcp\Tools;

use App\Enums\DatabaseDriver;
use App\Enums\LaravelFeature;
use App\Traits\InteractsWithProjectConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;

#[Name('patch-blueprint')]
#[Title('Patch Project Blueprint')]
#[Description('Surgically modifies the .larakube.json blueprint. Use this to add/remove databases, features, or change server variations.')]
class PatchBlueprintTool extends Tool
{
    use InteractsWithProjectConfig;

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

            return Response::error("Error: Project directory not found at '{$path}'.");
        }

        $blueprintPath = $path.'/.larakube.json';

        if (! File::exists($blueprintPath)) {
            chdir($originalCwd);

            return Response::error('Error: .larakube.json not found in '.$path);
        }

        $config = $this->getProjectConfig($path);

        $action = $request->get('action'); // add, remove, set
        $key = $request->get('key');       // databases, features, serverVariation
        $value = $request->get('value');   // mysql, redis, fpm-nginx

        switch ($action) {
            case 'add':
                $this->handleArrayModification($config, $key, $value, true);
                break;
            case 'remove':
                $this->handleArrayModification($config, $key, $value, false);
                break;
            case 'set':
                $setter = 'set'.ucfirst($key);
                if (method_exists($config, $setter)) {
                    $config->$setter($value);
                } else {
                    chdir($originalCwd);

                    return Response::error("Error: Unknown configuration key '{$key}'");
                }
                break;
            default:
                chdir($originalCwd);

                return Response::error("Error: Unknown action '{$action}'");
        }

        $config->saveToFile($path);
        chdir($originalCwd);

        return Response::text("✅ Successfully patched blueprint: '{$action}' on '{$key}' with value '{$value}'. 
👉 Suggest running 'larakube heal' to apply changes.");
    }

    protected function handleArrayModification($config, $key, $value, bool $isAdd): void
    {
        // Simple mapping for common arrays
        $map = [
            'databases' => ['get' => 'getDatabases', 'add' => 'addDatabase', 'remove' => 'removeDatabase', 'enum' => DatabaseDriver::class],
            'features' => ['get' => 'getFeatures', 'add' => 'addFeature', 'remove' => 'removeFeature', 'enum' => LaravelFeature::class],
        ];

        if (! isset($map[$key])) {
            return;
        }

        $entry = $map[$key];
        $enumValue = ($entry['enum'])::tryFrom($value);

        if (! $enumValue) {
            return;
        }

        $method = $isAdd ? $entry['add'] : $entry['remove'];
        $config->$method($enumValue);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['add', 'remove', 'set'])->description('The type of modification'),
            'key' => $schema->string()->enum(['databases', 'features', 'serverVariation', 'phpVersion'])->description('The blueprint key to modify'),
            'value' => $schema->string()->description('The value to add/remove/set (e.g. "mysql", "redis", "frankenphp")'),
            'path' => $schema->string()->description('The filesystem path of the project (optional)')->nullable(),
        ];
    }
}
