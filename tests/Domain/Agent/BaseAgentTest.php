<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Domain\Agent;

use AnyllmCli\Domain\Agent\BaseAgent;
use AnyllmCli\Domain\Api\ApiClientInterface;
use AnyllmCli\Domain\Session\SessionContext;
use AnyllmCli\Domain\Tool\ToolRegistryInterface;
use AnyllmCli\Infrastructure\Terminal\DiffRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BaseAgentTest extends TestCase
{
    private BaseAgent $agent;
    private ReflectionMethod $truncateOutputMethod;

    protected function setUp(): void
    {
        // We have to use a concrete implementation of the abstract BaseAgent,
        // so we'll create an anonymous class for it.
        $this->agent = new class(
            $this->createMock(ApiClientInterface::class),
            $this->createMock(ToolRegistryInterface::class),
            $this->createMock(DiffRenderer::class),
            'system_prompt',
            $this->createMock(SessionContext::class)
        ) extends BaseAgent {};

        $this->truncateOutputMethod = new ReflectionMethod(BaseAgent::class, 'truncateOutput');
    }

    public function testTruncateOutputWithShortString(): void
    {
        $input = "Hello, world!
This is a test.";
        $result = $this->truncateOutputMethod->invoke($this->agent, $input);
        $this->assertSame($input, $result);
    }

    public function testTruncateOutputWithExactLineLimit(): void
    {
        $input = "Line 1
Line 2
Line 3";
        $result = $this->truncateOutputMethod->invoke($this->agent, $input);
        $this->assertSame($input, $result);
    }

    public function testTruncateOutputWithLongString(): void
    {
        $input = "Line 1
Line 2
Line 3
Line 4
Line 5";
        $expected = "Line 1
Line 2
Line 3...";
        $result = $this->truncateOutputMethod->invoke($this->agent, $input);
        $this->assertSame($expected, $result);
    }

    public function testTruncateOutputWithCustomLineLimit(): void
    {
        $input = "Line 1
Line 2
Line 3";
        $expected = "Line 1...";
        $result = $this->truncateOutputMethod->invoke($this->agent, $input, 1);
        $this->assertSame($expected, $result);
    }

    public function testTruncateOutputWithEmptyString(): void
    {
        $input = "";
        $result = $this->truncateOutputMethod->invoke($this->agent, $input);
        $this->assertSame("", $result);
    }
}
