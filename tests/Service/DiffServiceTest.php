<?php

declare(strict_types=1);

namespace Tests\Service;

use AnyllmCli\Service\DiffService;
use PHPUnit\Framework\TestCase;

class DiffServiceTest extends TestCase
{
    public function testCompare()
    {
        $old = "hello\nworld";
        $new = "hello\nnew world";
        $diff = DiffService::compare($old, $new);

        $this->assertIsArray($diff);
        $this->assertCount(3, $diff);

        $this->assertEquals('keep', $diff[0]['type']);
        $this->assertEquals('hello', $diff[0]['old']);

        $this->assertEquals('remove', $diff[1]['type']);
        $this->assertEquals('world', $diff[1]['line']);

        $this->assertEquals('add', $diff[2]['type']);
        $this->assertEquals('new world', $diff[2]['line']);
    }
}
