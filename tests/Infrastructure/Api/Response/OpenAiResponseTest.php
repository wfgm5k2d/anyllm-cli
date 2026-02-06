<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Api\Response;

use AnyllmCli\Infrastructure\Api\Response\OpenAiResponse;
use PHPUnit\Framework\TestCase;

class OpenAiResponseTest extends TestCase
{
    public function testGetUsageReturnsCorrectStatsFromStream(): void
    {
        $mockStream = <<<STREAM
data: {"id":"chatcmpl-123","object":"chat.completion.chunk","created":1694268190,"model":"gpt-4","choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}

data: {"id":"chatcmpl-123","object":"chat.completion.chunk","created":1694268190,"model":"gpt-4","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}

data: {"id":"chatcmpl-123","object":"chat.completion.chunk","created":1694268190,"model":"gpt-4","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":25,"total_tokens":35}}

data: [DONE]

STREAM;

        $response = new OpenAiResponse($mockStream);
        $usage = $response->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(10, $usage->promptTokens);
        $this->assertSame(25, $usage->completionTokens);
        $this->assertSame(35, $usage->totalTokens);
    }

    public function testGetUsageReturnsNullWhenNotInStream(): void
    {
        $mockStream = <<<STREAM
data: {"id":"chatcmpl-123","object":"chat.completion.chunk","created":1694268190,"model":"gpt-4","choices":[{"index":0,"delta":{"content":"Hello"},"finish_reason":null}]}

data: [DONE]

STREAM;

        $response = new OpenAiResponse($mockStream);
        $usage = $response->getUsage();

        $this->assertNull($usage);
    }
}
