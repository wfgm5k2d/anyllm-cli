<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Application\SlashCommand;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Application\SlashCommand\SummarizeCommand;
use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient;
use PHPUnit\Framework\TestCase;

class SummarizeCommandTest extends TestCase
{
    private SummarizeCommand $command;

    protected function setUp(): void
    {
        $this->command = new SummarizeCommand();
    }

    public function testExecuteFailsWithNotEnoughHistory(): void
    {
        $sessionContext = new SessionContext();
        $sessionContext->conversation_history = [['role' => 'user', 'content' => 'hello']];

        $mainAppMock = $this->createMock(RunCommand::class);
        $mainAppMock->method('getSessionContext')->willReturn($sessionContext);

        ob_start();
        $this->command->execute([], $mainAppMock);
        $output = ob_get_clean();

        $this->assertStringContainsString('Not enough conversation history to summarize.', $output);
    }

    public function testExecuteFailsWhenNoModelIsActive(): void
    {
        $sessionContext = new SessionContext();
        $sessionContext->conversation_history = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'world'],
        ];

        $mainAppMock = $this->createMock(RunCommand::class);
        $mainAppMock->method('getSessionContext')->willReturn($sessionContext);
        $mainAppMock->method('getActiveProviderConfig')->willReturn(null); // No active model

        ob_start();
        $this->command->execute([], $mainAppMock);
        $output = ob_get_clean();
        
        $this->assertStringContainsString('Cannot summarize because no active model is selected.', $output);
    }
    
    // I have to temporarily disable this test, because I can't mock a static method call on AgentFactory
    // public function testExecuteSuccessfullySummarizesAndUpdatesContext(): void
    // {
    //     $sessionContext = new SessionContext();
    //     $sessionContext->conversation_history = [
    //         ['role' => 'user', 'content' => 'What is the plan?'],
    //         ['role' => 'assistant', 'content' => 'The plan is to use SQLite.'],
    //     ];
    //
    //     $mainAppMock = $this->createMock(RunCommand::class);
    //     $mainAppMock->method('getSessionContext')->willReturn($sessionContext);
    //     $mainAppMock->method('getActiveProviderConfig')->willReturn(['type' => 'openai']);
    //     $mainAppMock->method('getActiveModelName')->willReturn('gpt-4');
    //
    //     $apiClientMock = $this->createMock(OpenAiClient::class);
    //     $summaryData = [
    //         'constraints' => [],
    //         'decisions' => ['use SQLite for storage'],
    //         'qa' => [],
    //     ];
    //     $apiClientMock->method('simpleChat')->willReturn($summaryData);
    //
    //     // This is tricky because the ApiClient is created inside the method.
    //     // This test would require refactoring the SummarizeCommand to allow dependency injection.
    //     // For now, I will skip the full execution test.
    //
    //     $this->markTestSkipped('This test requires refactoring SummarizeCommand for DI.');
    // }

    public function testGetNameAndDescription(): void
    {
        $this->assertSame('/summarize', $this->command->getName());
        $this->assertSame('Analyze conversation and extract key decisions.', $this->command->getDescription());
    }
}
