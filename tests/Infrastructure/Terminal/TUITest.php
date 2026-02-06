<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Terminal;

use AnyllmCli\Application\SlashCommand\SlashCommandRegistry;
use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Infrastructure\Terminal\TerminalManager;
use AnyllmCli\Infrastructure\Terminal\TUI;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class TUITest extends TestCase
{
    private TUI $tui;
    private ReflectionMethod $prepareSuggestionsMethod;
    private ReflectionProperty $suggestionsProperty;
    private ReflectionProperty $menuVisibleProperty;
    private ReflectionProperty $bufferProperty;

    protected function setUp(): void
    {
        $terminalManagerMock = $this->createMock(TerminalManager::class);
        $configMock = $this->createMock(AnylmJsonConfig::class);
        $commandRegistryMock = $this->createMock(SlashCommandRegistry::class);

        $projectFiles = [
            'src/Application/RunCommand.php',
            'src/Application/Agent/GeminiAgent.php',
            'src/Domain/Agent/AgentInterface.php',
            'README.md',
            'tests/Application/RunCommandCleanupTest.php',
        ];

        $this->tui = new TUI($terminalManagerMock, $configMock, $commandRegistryMock, $projectFiles);
        
        $this->prepareSuggestionsMethod = new ReflectionMethod(TUI::class, 'prepareSuggestions');
        
        $this->suggestionsProperty = new ReflectionProperty(TUI::class, 'currentSuggestions');
        $this->menuVisibleProperty = new ReflectionProperty(TUI::class, 'isMenuVisible');
        $this->bufferProperty = new ReflectionProperty(TUI::class, 'buffer');
    }

    public function testPrepareSuggestionsFindsMatchingFiles(): void
    {
        // Simulate user typing "@RunCommand"
        $this->bufferProperty->setValue($this->tui, '@RunCommand');
        
        $this->prepareSuggestionsMethod->invoke($this->tui);
        
        /** @var array $suggestions */
        $suggestions = $this->suggestionsProperty->getValue($this->tui);
        
        $this->assertTrue($this->menuVisibleProperty->getValue($this->tui));
        $this->assertCount(2, $suggestions);
        $this->assertSame('src/Application/RunCommand.php', $suggestions[0]['name']);
        $this->assertSame('tests/Application/RunCommandCleanupTest.php', $suggestions[1]['name']);
    }

    public function testPrepareSuggestionsIsCaseInsensitive(): void
    {
        // Simulate user typing "@agent" (lowercase)
        $this->bufferProperty->setValue($this->tui, '@agent');

        $this->prepareSuggestionsMethod->invoke($this->tui);

        /** @var array $suggestions */
        $suggestions = $this->suggestionsProperty->getValue($this->tui);

        $this->assertTrue($this->menuVisibleProperty->getValue($this->tui));
        $this->assertCount(2, $suggestions);
        $this->assertSame('src/Application/Agent/GeminiAgent.php', $suggestions[0]['name']);
        $this->assertSame('src/Domain/Agent/AgentInterface.php', $suggestions[1]['name']);
    }

    public function testPrepareSuggestionsReturnsEmptyForNoMatch(): void
    {
        $this->bufferProperty->setValue($this->tui, '@nonexistentfile');
        
        $this->prepareSuggestionsMethod->invoke($this->tui);

        /** @var array $suggestions */
        $suggestions = $this->suggestionsProperty->getValue($this->tui);
        
        $this->assertFalse($this->menuVisibleProperty->getValue($this->tui));
        $this->assertCount(0, $suggestions);
    }
    
    public function testPrepareSuggestionsHidesMenuForEmptySearchTerm(): void
    {
        $this->bufferProperty->setValue($this->tui, '@');
        
        $this->prepareSuggestionsMethod->invoke($this->tui);

        /** @var array $suggestions */
        $suggestions = $this->suggestionsProperty->getValue($this->tui);
        
        // This is now true, because we want to show all files if the search term is empty
        $this->assertFalse($this->menuVisibleProperty->getValue($this->tui));
    }
}

