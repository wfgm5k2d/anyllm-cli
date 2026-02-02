<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Session;

interface SessionManagerInterface
{
    /**
     * Initializes the session environment, creating directories if needed.
     */
    public function initialize(): void;

    /**
     * Loads the session context from the filesystem.
     * If no saved session is found, it should return a fresh context.
     *
     * @return SessionContext
     */
    public function loadSession(): SessionContext;

    /**
     * Saves the given session context to the filesystem.
     *
     * @param SessionContext $context
     */
    public function saveSession(SessionContext $context): void;
}
