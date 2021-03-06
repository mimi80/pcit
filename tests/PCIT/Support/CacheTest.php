<?php

declare(strict_types=1);

namespace PCIT\Tests\Support;

use KhsCI\Support\Cache;
use PCIT\Tests\PCITTestCase;

class CacheTest extends PCITTestCase
{
    public function test(): void
    {
        Cache::store()->set('key', 'value');

        $this->assertEquals('value', Cache::store()->get('key'));
    }
}
