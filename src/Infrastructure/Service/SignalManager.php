<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Service;

/**
 * A static class to manage global signal-related state.
 * This provides a simple way for the pcntl signal handler to communicate
 * with other parts of the application, like the API clients and the TUI.
 */
class SignalManager
{
    /**
     * Is an agent or a blocking API call currently executing?
     * @var bool
     */
    public static bool $isAgentRunning = false;

    /**
     * Has a cancellation (Ctrl+C) been requested during an agent run?
     * @var bool
     */
    public static bool $cancellationRequested = false;

    /**
     * A counter for SIGINT signals received when the agent is not running.
     * The TUI can check this to handle graceful exit.
     * @var int
     */
    public static int $sigintCount = 0;
}
