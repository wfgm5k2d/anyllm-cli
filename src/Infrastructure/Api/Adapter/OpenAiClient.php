<?php

declare(strict_types=1);

namespace AnyllmCli\Infrastructure\Api\Adapter;

use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Api\ApiResponseInterface;
use AnyllmCli\Infrastructure\Api\Response\OpenAiResponse;
use AnyllmCli\Infrastructure\Terminal\Style;

class OpenAiClient implements ApiClientInterface
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
        $baseUrl = rtrim($this->config['baseURL'], '/');
        $url = (strpos($baseUrl, 'chat/completions') !== false) ? $baseUrl : $baseUrl . '/chat/completions';

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

        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => true
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

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
                    if ($jsonStr === '[DONE]') continue;

                    $json = json_decode($jsonStr, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        if ($onProgress) {
                            $onProgress($chunk);
                        }
                    }
                }
            }
            return strlen($data);
        });

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            Style::errorBox("Network Error:\n$err");
            // Return an empty response on error
            return new OpenAiResponse('{}');
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
            return new OpenAiResponse('{}');
        }

        // The full response content is now in $responseContent
        return new OpenAiResponse($responseContent);
    }
}
