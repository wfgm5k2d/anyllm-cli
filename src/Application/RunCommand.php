<?php

namespace AnyllmCli\Application;

use AnyllmCli\Application\Factory\AgentFactory;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Config\AnylmJsonConfig;
use AnyllmCli\Infrastructure\Service\RepoMapGenerator;
use AnyllmCli\Infrastructure\Service\KnowledgeBaseService;
use AnyllmCli\Infrastructure\Service\ProjectIdentifierService;
use AnyllmCli\Infrastructure\Session\SessionManager;
use AnyllmCli\Infrastructure\Terminal\Style;
use AnyllmCli\Infrastructure\Terminal\TerminalManager;
use AnyllmCli\Infrastructure\Terminal\TUI;

class RunCommand
{
    private AnylmJsonConfig $config;
    private TerminalManager $terminalManager;
    private TUI $tui;
    private SessionManager $sessionManager;
    private RepoMapGenerator $repoMapGenerator;
    private ProjectIdentifierService $projectIdentifierService;
    private KnowledgeBaseService $knowledgeBaseService;
    private bool $isSessionMode = false;
    private SessionContext $sessionContext;
    private bool $isCleanedUp = false;

    public function __construct()
    {
        // --- Global Handlers ---
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            Style::errorBox("PHP Error [$errno]: $errstr\nLocation: $errfile:$errline");
            return true;
        });

        set_exception_handler(function ($e) {
            Style::errorBox("Uncaught Exception:\n" . $e->getMessage() . "\nLocation: " . $e->getFile() . ":" . $e->getLine());
            exit(1);
        });
        // ------------------------------------------

        $this->config = new AnylmJsonConfig();
        $this->terminalManager = new TerminalManager();
        $this->tui = new TUI($this->terminalManager, $this->config);
        $this->sessionManager = new SessionManager(getcwd());
        $this->repoMapGenerator = new RepoMapGenerator(getcwd());
        $this->projectIdentifierService = new ProjectIdentifierService(getcwd());
        $this->knowledgeBaseService = new KnowledgeBaseService(getcwd());
        $this->sessionContext = new SessionContext();

        $this->detectSessionMode();
        $this->setupSignalHandler();
        register_shutdown_function([$this, 'performCleanup']);
    }

    private function setupSignalHandler(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSigint']);
        }
    }

    public function handleSigint(): never
    {
        echo PHP_EOL . Style::GRAY . "Ctrl+C detected. Shutting down gracefully..." . Style::RESET . PHP_EOL;
        $this->performCleanup();
        exit();
    }

    public function performCleanup(): void
    {
        if ($this->isCleanedUp) {
            return;
        }
        $this->terminalManager->restoreMode();
        if ($this->isSessionMode) {
            $this->sessionManager->saveSession($this->sessionContext);
        }
        $this->isCleanedUp = true;
    }

    private function detectSessionMode(): void
    {
        $sessionFlagFound = in_array('--session', $_SERVER['argv'], true);
        $sessionInConfig = $this->config->get('session', false) === true;
        $this->isSessionMode = $sessionFlagFound || $sessionInConfig;
    }

    public function run(): void
    {
        Style::banner();

        if (empty($this->config->get('provider'))) {
            Style::error("No providers configured in anyllm.json");
            exit(1);
        }

        // --- Session Handling ---
        if ($this->isSessionMode) {
            Style::info("Session mode enabled. Context will be loaded and saved.");
            $this->sessionManager->initialize();
            $this->sessionContext = $this->sessionManager->loadSession();
        }

        // --- Project Identification ---
        if ($this->sessionContext->isNewSession) {
            $this->sessionContext->project = $this->projectIdentifierService->identify();
        }

        // --- Knowledge Base ---
        $this->sessionContext->knowledge_base = $this->knowledgeBaseService->findKnowledge();

        // --- Repo Map Generation ---
        $this->repoMapGenerator->performInitialScan();
        // --------------------------

        $selection = $this->tui->selectModelTUI();

        if (!$selection) {
            echo Style::GRAY . "Exit." . Style::RESET . PHP_EOL;
            exit(0);
        }

        $modelName = $selection['model_name'];
        $providerConfig = $selection['provider_config'];

        Style::info("Using Provider: " . Style::PURPLE . $selection['provider_name'] . Style::RESET);
        Style::info("Using Model:    " . Style::BOLD . $selection['model_key'] . Style::RESET);

        // The system prompt is now generated inside the loop to have the latest context
        $this->startLoop($providerConfig, $modelName);
    }

    private function startLoop(array $providerConfig, string $modelName): void
    {
        while (true) {
            $input = $this->tui->readInputTUI();
            if (empty($input)) continue;

            if (in_array($input, ['/exit', '/quit', 'exit', 'quit'])) {
                echo Style::GRAY . "Goodbye." . Style::RESET . PHP_EOL;
                break;
            }
            if ($input === '/clear') {
                echo "\033[2J\033[H";
                Style::banner();
                continue;
            }

            $processedInput = $this->tui->processInputFiles($input);
            
            // Generate the prompt with the latest context right before execution
            $systemPrompt = $this->getSystemPrompt($this->sessionContext, $processedInput);

            echo $systemPrompt;
            exit;

            $agent = AgentFactory::create($providerConfig, $modelName, $systemPrompt, $this->sessionContext);

            echo PHP_EOL . Style::PURPLE . "✦ " . Style::RESET;

            $agent->execute($processedInput, function ($chunk) {
                echo $chunk;
                if (ob_get_length()) ob_flush();
                flush();
            });

            echo PHP_EOL . PHP_EOL;
        }
    }

    private function getSystemPrompt(SessionContext $context, string $currentInput): string
    {
        $osInfo = php_uname();
        $cwd = getcwd();

        // Generate dynamic repo map based on current input
        $mapData = $this->repoMapGenerator->generate($currentInput, $context);
        $context->repo_map = $mapData['repo_map'];
        $context->code_highlights = $mapData['code_highlights'];
        
        $sessionXml = $context->toXmlPrompt();

        return <<<PROMPT
You are a powerful AI assistant running in a CLI on a user's local machine. Your primary function is to execute user requests by calling functions to interact with the local filesystem.

**System Information:**
- Operating System: $osInfo
- Current Working Directory: $cwd

**SESSION CONTEXT:**
This block contains the summary of the project and conversation history. Use it to understand the user's goals.
$sessionXml

**CRITICAL INSTRUCTIONS:**
1.  You MUST use the provided tools (functions) to interact with the filesystem. Do not ask the user for permission; you are expected to use them.
2.  Think step-by-step. When the user gives a command, figure out which tool can fulfill the request and call it with the correct arguments.
3.  If a file path is required, use the relative path from the current working directory.
4.  When you have completed the user's request or have provided the requested information, simply stop. Do not output a summary message like "I have successfully...".
5.  Your responses should be concise and directly related to the task.
PROMPT;
    }
}

