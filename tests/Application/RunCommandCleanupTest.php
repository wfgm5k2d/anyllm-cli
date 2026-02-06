<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Application;

use AnyllmCli\Application\RunCommand;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Infrastructure\Session\SessionManager;
use AnyllmCli\Infrastructure\Terminal\TerminalManager;
use PHPUnit\Framework\TestCase;

class RunCommandCleanupTest extends TestCase
{
    /**
     * @covers \AnyllmCli\Application\RunCommand::performCleanup
     */
    public function testCleanupDoesNotSaveHistoryWhenInterrupted(): void
    {
        $sessionManagerMock = $this->createMock(SessionManager::class);
        $sessionManagerMock->expects($this->once())
            ->method('saveSession')
            // Assert that the second argument ($shouldLogHistory) is false
            ->with($this->anything(), $this->equalTo(false));

        $terminalManagerMock = $this->createMock(TerminalManager::class);
        $terminalManagerMock->expects($this->once())
            ->method('restoreMode');

        // Use reflection to instantiate RunCommand without calling its constructor,
        // which has side effects like reading configs and argv.
        $reflectionClass = new \ReflectionClass(RunCommand::class);
        $runCommand = $reflectionClass->newInstanceWithoutConstructor();

        // Set private properties needed for the test using reflection
        $this->setPrivateProperty($runCommand, 'sessionManager', $sessionManagerMock);
        $this->setPrivateProperty($runCommand, 'terminalManager', $terminalManagerMock);
        $this->setPrivateProperty($runCommand, 'sessionContext', new SessionContext());
        $this->setPrivateProperty($runCommand, 'startTime', microtime(true)); // Initialize startTime
        $this->setPrivateProperty($runCommand, 'isSessionMode', true);
        $this->setPrivateProperty($runCommand, 'ragMode', 'llm'); // Set a mode where logging would normally occur
        $this->setPrivateProperty($runCommand, 'isCleanedUp', false);

        // This is the key condition for our test: simulate that a request was interrupted.
        $this->setPrivateProperty($runCommand, 'requestInterrupted', true);

        // Execute the method under test
        $runCommand->performCleanup();
    }

    /**
     * @covers \AnyllmCli\Application\RunCommand::performCleanup
     */
    public function testCleanupSavesHistoryWhenNotInterrupted(): void
    {
        $sessionManagerMock = $this->createMock(SessionManager::class);
        $sessionManagerMock->expects($this->once())
            ->method('saveSession')
            // Assert that the second argument ($shouldLogHistory) is true
            ->with($this->anything(), $this->equalTo(true));

        $terminalManagerMock = $this->createMock(TerminalManager::class);
        $terminalManagerMock->expects($this->once())
            ->method('restoreMode');

        $reflectionClass = new \ReflectionClass(RunCommand::class);
        $runCommand = $reflectionClass->newInstanceWithoutConstructor();

        $this->setPrivateProperty($runCommand, 'sessionManager', $sessionManagerMock);
        $this->setPrivateProperty($runCommand, 'terminalManager', $terminalManagerMock);
        $this->setPrivateProperty($runCommand, 'sessionContext', new SessionContext());
        $this->setPrivateProperty($runCommand, 'startTime', microtime(true)); // Initialize startTime
        $this->setPrivateProperty($runCommand, 'isSessionMode', true);
        $this->setPrivateProperty($runCommand, 'ragMode', 'llm');
        $this->setPrivateProperty($runCommand, 'isCleanedUp', false);

        // The key condition: the request was NOT interrupted.
        $this->setPrivateProperty($runCommand, 'requestInterrupted', false);

        // Execute the method under test
        $runCommand->performCleanup();
    }

    /**
     * Helper to set private properties on an object.
     * @param object $object
     * @param string $propertyName
     * @param mixed $value
     * @throws \ReflectionException
     */
    private function setPrivateProperty(object $object, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($propertyName);
        // setAccessible() is not needed since PHP 8.1 and is deprecated in PHP 8.5
        // $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}
