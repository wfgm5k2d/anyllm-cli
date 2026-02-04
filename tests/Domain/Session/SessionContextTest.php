<?php

declare(strict_types=1);

namespace AnyllmCli\Tests\Domain\Session;

use AnyllmCli\Domain\Session\SessionContext;
use PHPUnit\Framework\TestCase;

class SessionContextTest extends TestCase
{
    public function testToXmlPromptGeneratesCorrectXmlWithData(): void
    {
        $context = new SessionContext();
        $context->isNewSession = false;

        $context->task = [
            'summary' => 'Test task',
            'type' => 'TEST',
            'artifact' => 'Test artifact',
            'stack' => 'PHP',
            'constraints' => 'None',
        ];

        $context->project = [
            'name' => 'test-project',
            'path' => '/tmp/test-project',
            'entry_point' => 'main.php',
        ];

        $context->summarized_history = [
            [
                'request' => 'first request',
                'outcome' => 'first outcome',
                'timestamp' => '2023-01-01T12:00:00Z',
            ]
        ];

        $context->repo_map = "tests/\n  - test.php";

        $xml = $context->toXmlPrompt();

        $this->assertStringContainsString('<SESSION_CONTEXT>', $xml);
        $this->assertStringContainsString('</SESSION_CONTEXT>', $xml);

        // Test Task block
        $this->assertStringContainsString('<task>', $xml);
        $this->assertStringContainsString('<summary>Test task</summary>', $xml);
        $this->assertStringContainsString('<type>TEST</type>', $xml);
        $this->assertStringContainsString('</task>', $xml);

        // Test Project block
        $this->assertStringContainsString('<project>', $xml);
        $this->assertStringContainsString('<name>test-project</name>', $xml);
        $this->assertStringContainsString('<path>/tmp/test-project</path>', $xml);
        $this->assertStringContainsString('</project>', $xml);

        // Test Conversation History block
        $this->assertStringContainsString('<conversation_history>', $xml);
        $this->assertStringContainsString('<episode timestamp="2023-01-01T12:00:00Z">', $xml);
        $this->assertStringContainsString('<request><![CDATA[first request]]></request>', $xml);
        $this->assertStringContainsString('<outcome><![CDATA[first outcome]]></outcome>', $xml);
        $this->assertStringContainsString('</conversation_history>', $xml);

        // Test Repo Map block
        $this->assertStringContainsString('<repo_map>', $xml);
        $this->assertStringContainsString('<![CDATA[', $xml);
        $this->assertStringContainsString("tests/\n  - test.php", $xml);
        $this->assertStringContainsString('</repo_map>', $xml);
    }

    public function testToXmlPromptHandlesEmptyContext(): void
    {
        $context = new SessionContext();
        $xml = $context->toXmlPrompt();

        $this->assertStringContainsString('<SESSION_CONTEXT>', $xml);
        $this->assertStringContainsString('</SESSION_CONTEXT>', $xml);

        // Assert that optional blocks are not present
        $this->assertStringNotContainsString('<task>', $xml);
        $this->assertStringNotContainsString('<project>', $xml);
        $this->assertStringNotContainsString('<conversation_history>', $xml);
        $this->assertStringNotContainsString('<repo_map>', $xml);
    }
}
