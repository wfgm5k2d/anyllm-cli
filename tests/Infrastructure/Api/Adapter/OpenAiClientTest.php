<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Api\Adapter;

use AnyllmCli\Infrastructure\Api\Adapter\OpenAiClient;
use AnyllmCli\Infrastructure\Api\Response\OpenAiResponse;
use AnyllmCli\Infrastructure\Service\SignalManager;
use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\TestCase;

class OpenAiClientTest extends TestCase
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

        $apiResponseStream = <<<STREAM
data: {"id":"chatcmpl-123", "choices": [{"delta": {"content": "Hello"}}]}

data: {"id":"chatcmpl-123", "choices": [{"delta": {"content": " there"}}]}

data: [DONE]
STREAM;

        $curl_multi_exec = $this->getFunctionMock($namespace, "curl_multi_exec");
        $curl_multi_exec->expects($this->atLeastOnce())
            ->willReturnCallback(function ($mh, &$active) use (&$writeCallback, $apiResponseStream) {
                static $called = false;
                if (!$called) {
                    $this->assertNotNull($writeCallback, "Write callback was not set");
                    // Simulate the stream by passing each line to the callback
                    foreach (explode("\n", $apiResponseStream) as $line) {
                        call_user_func($writeCallback, curl_init(), $line . "\n");
                    }
                    $active = true;
                    $called = true;
                } else {
                    $active = false;
                }
                return CURLM_OK;
            });


        // --- Execution ---
        SignalManager::$cancellationRequested = false;
        $config = ['baseURL' => 'https://api.openai.com/v1', 'headers' => ['Authorization' => 'Bearer test-key']];
        $client = new OpenAiClient($config, 'gpt-4');
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $response = $client->chat($messages, [], null);

        // --- Assertion ---
        $this->assertInstanceOf(OpenAiResponse::class, $response);
        $this->assertEquals('Hello there', $response->getMessageContent());
        $this->assertFalse($response->hasToolCalls());
    }
}
