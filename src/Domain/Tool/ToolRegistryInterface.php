<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Tool;

interface ToolRegistryInterface
{
    /**
     * @param ToolInterface $tool
     * @return void
     */
    public function register(ToolInterface $tool): void;

    /**
     * @param string $name
     * @return ToolInterface|null
     */
    public function getTool(string $name): ?ToolInterface;

    /**
     * @return array
     */
    public function getToolsAsJsonSchema(): array;
}
