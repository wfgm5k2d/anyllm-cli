<?php

namespace AnyllmCli\Infrastructure\Config;

use AnyllmCli\Infrastructure\Terminal\Style;

class AnylmJsonConfig
{
    private array $configData;
    private string $configFile;

    public function __construct()
    {
        $this->configFile = getcwd() . DIRECTORY_SEPARATOR . 'anyllm.json';
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        if (!file_exists($this->configFile)) {
            Style::errorBox("Configuration file not found:\n" . $this->configFile . "\nPlease create it.");
            exit(1);
        }
        $json = file_get_contents($this->configFile);
        $this->configData = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Style::errorBox("Invalid JSON in config file:\n" . json_last_error_msg());
            exit(1);
        }
    }

    public function get(string $key, $default = null)
    {
        $parts = explode('.', $key);
        $current = $this->configData;
        foreach ($parts as $part) {
            if (isset($current[$part])) {
                $current = $current[$part];
            } else {
                return $default;
            }
        }
        return $current;
    }

    public function all(): array
    {
        return $this->configData;
    }
}
