<?php

declare(strict_types=1);

namespace PCIT\Builder\Tests\Conditional;

use PCIT\Builder\Conditional\Tag;
use PCIT\Tests\PCITTestCase;

class TagTest extends PCITTestCase
{
    /**
     * @throws \Exception
     */
    public function test(): void
    {
        $result = (new Tag('^[0-9.]+$', '1.2.0'))->regHandle();
        $this->assertTrue($result);

        $result = (new Tag('^[0-9.]+$', '1.2.0-rc'))->regHandle();
        $this->assertFalse($result);

        $result = (new Tag('^[0-9.]+', '1.2.0-rc'))->regHandle();
        $this->assertTrue($result);

        $result = (new Tag('^[0-9.]+', 'v1.2.0'))->regHandle();
        $this->assertFalse($result);

        $result = (new Tag('^v([0-9.]+)$', 'v1.2.0'))->regHandle();
        $this->assertTrue($result);

        $result = (new Tag('^v([0-9.]+)$', '1.2.0'))->regHandle();
        $this->assertFalse($result);
    }
}
