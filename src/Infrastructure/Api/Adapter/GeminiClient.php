<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Adapter;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Infrastructure\Api\Response\GeminiResponse;
use AnyllmCli\Infrastructure\Terminal\Style;
use stdClass;

class GeminiClient implements ApiClientInterface
{
    private array $config;
    private string $modelName;

    public function __construct(array $providerConfig, string $modelName)
    {
        $this->config = $providerConfig;
        $this->modelName = $modelName;
    }

    public function chat(array $messages, array $tools, ?callable $onProgress): ApiResponseInterface
    {
        $url = rtrim($this->config['baseURL'], '/') . "/v1beta/models/{$this->modelName}:streamGenerateContent";

        $payload = [];
        $contents = [];
        $systemInstruction = null;

        foreach ($messages as $message) {
            $role = $message['role'];

            if ($role === 'system') {
                $systemInstruction = ['parts' => [['text' => $message['content']]]];
                continue;
            }

            if ($role === 'assistant') {
                $role = 'model';
            }

            if ($role === 'tool') {
                $contents[] = [
                    'role' => 'user', // In Gemini, tool responses are from the user
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $message['name'],
                                'response' => json_decode((string) $message['content'], true),
                            ],
                        ],
                    ],
                ];
                continue;
            }

            if (isset($message['tool_calls'])) {
                $parts = [];
                foreach ($message['tool_calls'] as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall['function']['name'],
                            'args' => json_decode((string) $toolCall['function']['arguments'], true) ?? new stdClass(),
                        ]
                    ];
                }
                $contents[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }

            if (isset($message['content'])) {
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $message['content']]]
                ];
            }
        }

        $payload['contents'] = $contents;

        if ($systemInstruction) {
            $payload['system_instruction'] = $systemInstruction;
        }

        if ($tools) {
            $geminiTools = array_map(fn($tool) => $tool['function'] ?? $tool, $tools);
            $payload['tools'] = [['functionDeclarations' => $geminiTools]];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = [];
        $confHeaders = $this->config['headers'] ?? $this->config['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        if (empty($confHeaders['Content-Type'])) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $responseContent = "";
        $errorBuffer = "";

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseContent, &$errorBuffer, $onProgress) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code >= 400) {
                $errorBuffer .= $data;
                return strlen($data);
            }

            $responseContent .= $data;

            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    $json = json_decode($jsonStr, true);
                    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                        $chunk = $json['candidates'][0]['content']['parts'][0]['text'];
                        if ($onProgress) {
                            $onProgress($chunk);
                        }
                    }
                }
            }
            return strlen($data);
        });

        curl_exec($ch);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            Style::errorBox("Network Error:\n$err");
            return new GeminiResponse('{}');
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            $errorMsg = "HTTP Status: $httpCode";
            $jsonErr = json_decode($errorBuffer, true);
            if (isset($jsonErr['error']['message'])) {
                $errorMsg .= "\nAPI Error: " . $jsonErr['error']['message'];
            } elseif (!empty($errorBuffer)) {
                $errorMsg .= "\nResponse: " . substr($errorBuffer, 0, 800);
            }
            Style::errorBox($errorMsg);
            return new GeminiResponse('{}');
        }

        return new GeminiResponse($responseContent);
    }
}
