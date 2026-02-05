<?php

declare(strict_types=1);

namespace AnyllmCli\Application\Factory;

use AnyllmCli\Application\Agent\GeminiAgent;
use AnyllmCli\Application\Agent\OpenAiAgent;
use AnyllmCli\Domain\Agent\AgentInterface;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Api\Adapter\GeminiClient;
use AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient;
use AnyllmCli\Infrastructure\Terminal\DiffRenderer;
use AnyllmCli\Infrastructure\Terminal\DiffService;
use AnyllmCli\Infrastructure\Tool\ListDirectoryTool;
use AnyllmCli\Infrastructure\Tool\ReadFileTool;
use AnyllmCli\Infrastructure\Tool\SearchFileContentTool;
use AnyllmCli\Infrastructure\Tool\ToolRegistry;
use AnyllmCli\Infrastructure\Tool\WriteFileTool;
use AnyllmCli\Infrastructure\Tool\ExecuteShellCommandTool;
use AnyllmCli\Infrastructure\Tool\AddTodoTool;
use AnyllmCli\Infrastructure\Tool\MarkTodoDoneTool;
use AnyllmCli\Infrastructure\Tool\ListTodosTool;
use AnyllmCli\Application\Agent\SmallAgent;
use AnyllmCli\Infrastructure\Service\EditBlockParser;
use RuntimeException;

class AgentFactory
{
    public static function create(
        array $providerConfig,
        string $modelName,
        string $systemPrompt,
        SessionContext $sessionContext,
        int $maxIterations = 10,
        array $modelConfig = [],
        ?TerminalManager $terminalManager = null // Kept for compatibility, not used by clients
    ): AgentInterface {
        $providerType = $providerConfig['type'] ?? 'openai';
        $modelType = $modelConfig['type'] ?? 'large';

        // 1. Create the Tool Registry (for large models)
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register(new ListDirectoryTool());
        $toolRegistry->register(new ReadFileTool());
        $toolRegistry->register(new WriteFileTool());
        $toolRegistry->register(new SearchFileContentTool());
        $toolRegistry->register(new ExecuteShellCommandTool());
        $toolRegistry->register(new AddTodoTool());
        $toolRegistry->register(new MarkTodoDoneTool());
        $toolRegistry->register(new ListTodosTool());

        // 2. Create the Diff services and other shared services
        $diffService = new DiffService();
        $diffRenderer = new DiffRenderer($diffService);

        // 3. Create the appropriate API client
        if ($providerType === 'google') {
            $apiClient = new GeminiClient($providerConfig, $modelName);
        } elseif ($providerType === 'openai' || $providerType === 'openai_compatible') {
            $apiClient = new OpenAiClient($providerConfig, $modelName);
        } else {
            throw new RuntimeException("Unsupported provider type: {$providerType}");
        }

        // 4. Create the Agent based on model type
        if ($modelType === 'small') {
            $editBlockParser = new EditBlockParser($diffRenderer);
            return new SmallAgent($apiClient, $editBlockParser, $systemPrompt, $sessionContext, $maxIterations);
        }

        // Default to large model agents
        if ($providerType === 'google') {
            return new GeminiAgent($apiClient, $toolRegistry, $diffRenderer, $systemPrompt, $sessionContext, $maxIterations);
        }

        // Default for openai and openai_compatible
        return new OpenAiAgent($apiClient, $toolRegistry, $diffRenderer, $systemPrompt, $sessionContext, $maxIterations);
    }
}
