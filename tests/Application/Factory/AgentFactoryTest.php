<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Application\Factory;

use AnyllmCli\Application\Agent\GeminiAgent;
use AnyllmCli\Application\Agent\OpenAiAgent;
use AnyllmCli\Application\Factory\AgentFactory;
use AnyllmCli\Domain\Session\SessionContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AgentFactoryTest extends TestCase
{
    private SessionContext $sessionContext;

    protected function setUp(): void
    {
        $this->sessionContext = new SessionContext();
    }

    public function testCreateReturnsOpenAiAgentForOpenAiType(): void
    {
        $config = ['type' => 'openai', 'baseURL' => 'https://api.openai.com/v1'];
        $agent = AgentFactory::create($config, 'gpt-4', 'system prompt', $this->sessionContext);

        $this->assertInstanceOf(OpenAiAgent::class, $agent);
    }

    public function testCreateReturnsOpenAiAgentForOpenAiCompatibleType(): void
    {
        $config = ['type' => 'openai_compatible', 'baseURL' => 'http://localhost:8080'];
        $agent = AgentFactory::create($config, 'some-model', 'system prompt', $this->sessionContext);

        $this->assertInstanceOf(OpenAiAgent::class, $agent);
    }

    public function testCreateReturnsGeminiAgentForGoogleType(): void
    {
        $config = ['type' => 'google', 'baseURL' => 'https://generativelanguage.googleapis.com'];
        $agent = AgentFactory::create($config, 'gemini-pro', 'system prompt', $this->sessionContext);

        $this->assertInstanceOf(GeminiAgent::class, $agent);
    }

    public function testCreateThrowsExceptionForUnsupportedProvider(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported provider type: foobar');

        $config = ['type' => 'foobar'];
        AgentFactory::create($config, 'some-model', 'system prompt', $this->sessionContext);
    }

    public function testCreateReturnsOpenAiAgentAsDefault(): void
    {
        // Config with no type specified
        $config = ['baseURL' => 'https://api.openai.com/v1'];
        $agent = AgentFactory::create($config, 'gpt-4', 'system prompt', $this->sessionContext);

        $this->assertInstanceOf(OpenAiAgent::class, $agent);
    }
}
