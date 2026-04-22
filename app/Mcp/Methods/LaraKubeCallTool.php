<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Exceptions\JsonRpcException;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;

class LaraKubeCallTool extends CallTool
{
    /**
     * Handle the tool call by checking for 'callTool' first, then 'handle'.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): \Generator|JsonRpcResponse
    {
        if (is_null($request->get('name'))) {
            throw new JsonRpcException(
                'Missing [name] parameter.',
                -32602,
                $request->id,
            );
        }

        $tool = $context
            ->tools()
            ->first(
                fn ($tool): bool => $tool->name() === $request->params['name'],
                fn () => throw new JsonRpcException(
                    "Tool [{$request->params['name']}] not found.",
                    -32602,
                    $request->id,
                ));

        try {
            $container = Container::getInstance();

            // 1. Try LaraKube-specific callTool first
            if (method_exists($tool, 'callTool')) {
                $response = $container->call([$tool, 'callTool']);
            } else {
                // 2. Fallback to standard handle
                $response = $container->call([$tool, 'handle']);
            }
        } catch (AuthenticationException|AuthorizationException $authException) {
            $response = Response::error($authException->getMessage());
        } catch (ValidationException $validationException) {
            $response = Response::error(ValidationMessages::from($validationException));
        } catch (Throwable $e) {
            $response = Response::error('Internal tool error: '.$e->getMessage());
        }

        return is_iterable($response)
            ? $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($tool))
            : $this->toJsonRpcResponse($request, $response, $this->serializable($tool));
    }
}
