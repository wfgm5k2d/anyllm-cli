<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Api\Adapter;

use AnyllmCli\Infrastructure\Api\Adapter\GeminiClient;
use AnyllmCli\Infrastructure\Api\Response\GeminiResponse;
use AnyllmCli\Infrastructure\Service\SignalManager;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class GeminiClientTest extends TestCase
{
    use PHPMock;

    public function testChatHandlesSuccessfulStreamResponse(): void
    {
        $namespace = 'AnyllmCli\Infrastructure\Api\Adapter';
        $writeCallback = null;

        // Mock global curl functions
        $this->getFunctionMock($namespace, "curl_init")->expects($this->once())->willReturn(curl_init());
        $this->getFunctionMock($namespace, "curl_multi_init")->expects($this->once())->willReturn(curl_multi_init());
        $this->getFunctionMock($namespace, "curl_multi_add_handle")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_remove_handle")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_close")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_select")->expects($this->any());
        $this->getFunctionMock($namespace, "usleep")->expects($this->any());

        // Capture the write_function callback
        $curl_setopt = $this->getFunctionMock($namespace, "curl_setopt");
        $curl_setopt->expects($this->any())
            ->willReturnCallback(function ($ch, $option, $value) use (&$writeCallback) {
                if ($option === CURLOPT_WRITEFUNCTION) {
                    $writeCallback = $value;
                }
                return true;
            });

        $this->getFunctionMock($namespace, "curl_getinfo")
            ->expects($this->any())
            ->with($this->anything(), CURLINFO_HTTP_CODE)
            ->willReturn(200);

        $this->getFunctionMock($namespace, "curl_errno")->expects($this->once())->willReturn(0);

        $apiResponseJson = '[{"candidates":[{"content":{"parts":[{"text":"Hi there!"}]}}]}]';
        $curl_multi_exec = $this->getFunctionMock($namespace, "curl_multi_exec");
        $curl_multi_exec->expects($this->atLeastOnce())
            ->willReturnCallback(function ($mh, &$active) use (&$writeCallback, $apiResponseJson) {
                static $called = false;
                if (!$called) {
                    $this->assertNotNull($writeCallback, "Write callback was not set");
                    call_user_func($writeCallback, curl_init(), $apiResponseJson);
                    $active = true;
                    $called = true;
                } else {
                    $active = false;
                }
                return CURLM_OK;
            });

        // --- Execution ---
        SignalManager::$cancellationRequested = false;
        $config = ['baseURL' => 'https://gemini.example.com', 'headers' => ['X-API-Key' => 'test-key']];
        $client = new GeminiClient($config, 'gemini-pro');
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $response = $client->chat($messages, [], null);

        // --- Assertion ---
        $this->assertInstanceOf(GeminiResponse::class, $response);
        $this->assertEquals('Hi there!', $response->getMessageContent());
        $this->assertFalse($response->hasToolCalls());
    }

    public function testChatHandlesEmptyResponse(): void
    {
        $namespace = 'AnyllmCli\Infrastructure\Api\Adapter';
        $writeCallback = null;

        // Mock global curl functions
        $this->getFunctionMock($namespace, "curl_init")->expects($this->once())->willReturn(curl_init());
        $this->getFunctionMock($namespace, "curl_multi_init")->expects($this->once())->willReturn(curl_multi_init());
        $this->getFunctionMock($namespace, "curl_multi_add_handle")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_remove_handle")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_close")->expects($this->once());
        $this->getFunctionMock($namespace, "curl_multi_select")->expects($this->any());
        $this->getFunctionMock($namespace, "usleep")->expects($this->any());

        // Capture the write_function callback
        $curl_setopt = $this->getFunctionMock($namespace, "curl_setopt");
        $curl_setopt->expects($this->any())
            ->willReturnCallback(function ($ch, $option, $value) use (&$writeCallback) {
                if ($option === CURLOPT_WRITEFUNCTION) {
                    $writeCallback = $value;
                }
                return true;
            });

        $this->getFunctionMock($namespace, "curl_getinfo")->expects($this->any())->willReturn(200);
        $this->getFunctionMock($namespace, "curl_errno")->expects($this->once())->willReturn(0);

        $curl_multi_exec = $this->getFunctionMock($namespace, "curl_multi_exec");
        $curl_multi_exec->expects($this->atLeastOnce())
             ->willReturnCallback(function ($mh, &$active) use (&$writeCallback) {
                static $called = false;
                if (!$called) {
                    $this->assertNotNull($writeCallback, "Write callback was not set");
                    // This is the bug condition: an empty string response
                    call_user_func($writeCallback, curl_init(), '');
                    $active = true;
                    $called = true;
                } else {
                    $active = false;
                }
                return CURLM_OK;
            });


        // --- Execution ---
        SignalManager::$cancellationRequested = false;
        $config = ['baseURL' => 'https://gemini.example.com', 'headers' => ['X-API-Key' => 'test-key']];
        $client = new GeminiClient($config, 'gemini-pro');
        $messages = [['role' => 'user', 'content' => 'Hello']];

        $response = $client->chat($messages, [], null);

        // --- Assertion ---
        $this->assertInstanceOf(GeminiResponse::class, $response);
        $this->assertNull($response->getMessageContent());
    }
}