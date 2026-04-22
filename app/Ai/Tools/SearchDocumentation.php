<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

class SearchDocumentation extends LaraKubeTool
{
    /**
     * Get the name of the tool.
     */
    public function name(): string
    {
        return 'search_documentation';
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): string
    {
        return 'Search the official LaraKube documentation via the Algolia MCP server for answers about Kubernetes orchestration, configuration, and architectural blueprints.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The natural language query or keyword to search for (e.g. "how to setup frankenphp", "mysql configuration").')
                ->required(),
        ];
    }

    /**
     * MCP Server entry point.
     */
    public function callTool(array $arguments = []): Response
    {
        return $this->runMcp($arguments);
    }

    /**
     * Execute the tool via the official Algolia MCP URL.
     *
     * @throws ConnectionException
     */
    protected function run(array $arguments): string
    {
        $query = $arguments['query'];
        $mcpUrl = 'https://L42693MTAB.algolia.net/mcp/1/ccdl9WLFT-mLi8OqSkmx8A/mcp';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ])->post($mcpUrl, [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'algolia_search_index_LaraKube Documentation',
                'arguments' => [
                    'query' => $query,
                    'userIntent' => 'Searching documentation via LaraKube CLI',
                    'originalQuery' => $query,
                    'sessionId' => (string) Str::uuid(),
                ],
            ],
            'id' => 1,
        ]);

        if ($response->failed()) {
            return 'FAILURE: Unable to reach the LaraKube Documentation MCP server.';
        }

        // The Algolia MCP server returns SSE format: "event: message\ndata: {json}"
        $raw = $response->body();

        if (! str_contains($raw, 'data: ')) {
            return 'FAILURE: Unexpected response format from documentation server.';
        }

        $jsonStr = Str::after($raw, 'data: ');
        $data = json_decode($jsonStr, true);

        if (! isset($data['result']['content'][0]['text'])) {
            return 'No relevant documentation found for your query.';
        }

        $algoliaResult = json_decode($data['result']['content'][0]['text'], true);
        $hits = $algoliaResult['hits'] ?? [];

        if (empty($hits)) {
            return "No documentation matches found for '{$query}'.";
        }

        $results = "### Documentation Search Results for '{$query}':\n\n";

        foreach ($hits as $hit) {
            $title = $hit['hierarchy']['lvl1'] ?? $hit['hierarchy']['lvl0'] ?? 'Untitled Section';
            $url = $hit['url'] ?? 'No URL available';
            $snippet = $hit['_snippetResult']['content']['value'] ?? $hit['content'] ?? 'No preview available.';

            // Clean up snippet from Algolia highlight tags
            $snippet = strip_tags(str_replace(['<span class="algolia-docsearch-suggestion--highlight">', '</span>'], '', $snippet));

            $results .= "#### [{$title}]({$url})\n{$snippet}\n\n";
        }

        return $results;
    }
}
