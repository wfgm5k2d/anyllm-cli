<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Infrastructure\Api\Response;

use AnyllmCli\Infrastructure\Api\Response\GeminiResponse;
use PHPUnit\Framework\TestCase;

class GeminiResponseTest extends TestCase
{
    public function testGetUsageReturnsCorrectStatsFromJsonArray(): void
    {
        $mockJson = <<<JSON
[
  {
    "candidates": [
      {
        "content": {
          "role": "model",
          "parts": [
            {
              "text": "Hello"
            }
          ]
        }
      }
    ]
  },
  {
    "candidates": [
      {
        "content": {
          "role": "model",
          "parts": [
            {
              "text": " there."
            }
          ]
        },
        "finishReason": "STOP"
      }
    ],
    "usageMetadata": {
      "promptTokenCount": 15,
      "candidatesTokenCount": 30,
      "totalTokenCount": 45
    }
  }
]
JSON;

        $response = new GeminiResponse($mockJson);
        $usage = $response->getUsage();

        $this->assertNotNull($usage);
        $this->assertSame(15, $usage->promptTokens);
        $this->assertSame(30, $usage->completionTokens);
        // The constructor calculates total, so 15 + 30 = 45
        $this->assertSame(45, $usage->totalTokens);
    }

    public function testGetUsageReturnsNullWhenNotInJson(): void
    {
        $mockJson = <<<JSON
[
  {
    "candidates": [
      {
        "content": {
          "role": "model",
          "parts": [
            {
              "text": "Hello there."
            }
          ]
        },
        "finishReason": "STOP"
      }
    ]
  }
]
JSON;

        $response = new GeminiResponse($mockJson);
        $usage = $response->getUsage();

        $this->assertNull($usage);
    }
}
