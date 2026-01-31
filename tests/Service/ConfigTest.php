<?php

declare(strict_types=1);

namespace Tests\Service;

use AnyllmCli\Service\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private string $dummyConfigFile;

    protected function setUp(): void
    {
        $this->dummyConfigFile = getcwd() . DIRECTORY_SEPARATOR . 'anyllm.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dummyConfigFile)) {
            unlink($this->dummyConfigFile);
        }
    }

    public function testGet()
    {
        $configData = [
            'provider' => [
                'openai' => [
                    'name' => 'OpenAI',
                    'models' => [
                        'gpt-4' => [
                            'name' => 'GPT-4'
                        ]
                    ]
                ]
            ]
        ];
        file_put_contents($this->dummyConfigFile, json_encode($configData));

        $config = new Config();
        $this->assertEquals('OpenAI', $config->get('provider.openai.name'));
        $this->assertEquals('GPT-4', $config->get('provider.openai.models.gpt-4.name'));
        $this->assertNull($config->get('non.existent.key'));
    }

    public function testAll()
    {
        $configData = ['foo' => 'bar'];
        file_put_contents($this->dummyConfigFile, json_encode($configData));

        $config = new Config();
        $this->assertEquals($configData, $config->all());
    }
}
