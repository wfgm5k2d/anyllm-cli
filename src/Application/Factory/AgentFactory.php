<?php

declare(strict_types=1);

namespace AnyllmCli\Application\Factory;

use AnyllmCli\Application\Agent\GeminiAgent;
use AnyllmCli\Application\Agent\OpenAiAgent;
use AnyllmCli\Domain\Agent\AgentInterface;
use AnyllmCli\Infrastructure\Api\Adapter\GeminiClient;
use AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient;
use AnyllmCli\Infrastructure\Tool\ListDirectoryTool;
use AnyllmCli\Infrastructure\Tool\ReadFileTool;
use AnyllmCli\Infrastructure\Tool\SearchFileContentTool;
use AnyllmCli\Infrastructure\Tool\ToolRegistry;
use AnyllmCli\Infrastructure\Tool\WriteFileTool;
use RuntimeException;

class AgentFactory
{
    public static function create(array $providerConfig, string $modelName, string $systemPrompt): AgentInterface
    {
        $providerType = $providerConfig['type'] ?? 'openai';

        // 1. Create the Tool Registry and register all tools
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register(new ListDirectoryTool());
        $toolRegistry->register(new ReadFileTool());
        $toolRegistry->register(new WriteFileTool());
        $toolRegistry->register(new SearchFileContentTool());

        // 2. Create the appropriate API client
        if ($providerType === 'google') {
            $apiClient = new GeminiClient($providerConfig, $modelName);
            return new GeminiAgent($apiClient, $toolRegistry, $systemPrompt);
        }

        if ($providerType === 'openai' || $providerType === 'openai_compatible') {
            $apiClient = new OpenAiClient($providerConfig, $modelName);
            return new OpenAiAgent($apiClient, $toolRegistry, $systemPrompt);
        }

        throw new RuntimeException("Unsupported provider type: {$providerType}");
    }
}
