<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function getParameters(): array;

    public function execute(array $arguments): string;
}
