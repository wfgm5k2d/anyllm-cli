<?php

declare(strict_types=1);

namespace Tests\Service;

use AnyllmCli\Service\ApiClient;
use AnyllmCli\Terminal\TerminalManager;
use PHPUnit\Framework\TestCase;

class ApiClientTest extends TestCase
{
    public function testCanBeInstantiated()
    {
        $terminalManager = $this->createMock(TerminalManager::class);
        $apiClient = new ApiClient($terminalManager);
        $this->assertInstanceOf(ApiClient::class, $apiClient);
    }
}
