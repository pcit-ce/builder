<?php

declare(strict_types=1);

namespace PCIT\Builder\Tests\Events;

use PCIT\Builder\Events\Cache;
use PCIT\Support\DB;
use PCIT\Tests\PCITTestCase;
use Symfony\Component\Yaml\Yaml;

class CacheTest extends PCITTestCase
{
    public $yaml;

    public $cache;

    /**
     * @throws \Exception
     */
    public function common(): void
    {
        $array = Yaml::parse($this->yaml);

        $json = json_encode($array);

        $stub = $this->createMock(Cache::class);

        $stub->method('getPrefix')->willReturn('gittype_rid_branch');

        $cache = new Cache(1, '/pcit', json_decode($json)->cache);

        $cache->handle();

        $this->cache = \PCIT\Support\Cache::store()->hGet('/pcit/cache', '1');
    }

    /**
     * @throws \Exception
     * @group dont-test
     */
    public function testDirectories(): void
    {
        DB::close();

        $yaml = <<<'EOF'
cache:
  directories:
  - dir
EOF;

        $this->yaml = $yaml;

        $this->common();

        var_dump($this->cache);

        $this->assertObjectHasAttribute('directories', json_decode($this->cache));
    }
}
