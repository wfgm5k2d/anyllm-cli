<?php

namespace AnyllmCli\Service;

use AnyllmCli\Terminal\Style;
use AnyllmCli\Terminal\TerminalManager;

class ApiClient
{
    private TerminalManager $terminalManager;

    public function __construct(TerminalManager $terminalManager)
    {
        $this->terminalManager = $terminalManager;
    }

    public function streamResponse(array $activeProviderConfig, string $selectedModelName, array &$history): ?string
    {
        $baseUrl = rtrim($activeProviderConfig['baseURL'], '/');
        $url = (strpos($baseUrl, 'chat/completions') !== false) ? $baseUrl : $baseUrl . '/chat/completions';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $headers = [];
        $confHeaders = $activeProviderConfig['headers'] ?? $activeProviderConfig['header'] ?? [];
        foreach ($confHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $selectedModelName,
            'messages' => $history,
            'stream' => true
        ]));

        $responseStarted = false;
        $content = "";
        $errorBuffer = "";

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$content, &$errorBuffer, &$responseStarted) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($code >= 400) {
                $errorBuffer .= $data;
                return strlen($data);
            }

            if (!$responseStarted) {
                $responseStarted = true;
                Style::clearLine();
            }

            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if (strpos($line, 'data: ') === 0) {
                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') continue;
                    $json = json_decode($jsonStr, true);
                    if (isset($json['choices'][0]['delta']['content'])) {
                        $c = $json['choices'][0]['delta']['content'];
                        echo $c;
                        $content .= $c;
                        if (ob_get_length()) ob_flush();
                        flush();
                    }
                }
            }
            return strlen($data);
        });

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $active = null;
        $f = 0;
        $anim = ['.', '..', '...'];

        stream_set_blocking(STDIN, false);
        $this->terminalManager->setRawMode();
        $interrupted = false;

        do {
            $s = curl_multi_exec($mh, $active);
            $input = fread(STDIN, 1);
            if ($input !== false && strlen($input) > 0) {
                if (ord($input) === 3) {
                    $interrupted = true;
                    break;
                }
            }
            if (!$responseStarted && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400) {
                Style::clearLine();
                echo Style::GRAY . "Think" . $anim[$f++ % 3] . Style::RESET;
                usleep(300000);
            } else {
                curl_multi_select($mh, 0.1);
            }
        } while ($active && $s == CURLM_OK);

        $this->terminalManager->restoreMode();
        stream_set_blocking(STDIN, true);

        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
            Style::errorBox("Network Error:\n$err");
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        if ($httpCode >= 400) {
            $errorMsg = "HTTP Status: $httpCode";
            $jsonErr = json_decode($errorBuffer, true);
            if (isset($jsonErr['error']['message'])) {
                $errorMsg .= "\nAPI Error: " . $jsonErr['error']['message'];
            } elseif (!empty($errorBuffer)) {
                $errorMsg .= "\nResponse: " . substr($errorBuffer, 0, 800);
            }
            Style::errorBox($errorMsg);
            return null;
        }

        if ($interrupted) {
            echo PHP_EOL . Style::YELLOW . "Request cancelled." . Style::RESET . PHP_EOL;
            return null;
        }

        return $content;
    }
}
