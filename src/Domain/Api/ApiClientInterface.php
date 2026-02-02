<?php

declare(strict_types=1);

namespace AnyllmCli\Domain\Api;

interface ApiClientInterface
{
    public function chat(array $messages, array $tools, ?callable $onProgress): ApiResponseInterface;
}
