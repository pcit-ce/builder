<?php

declare(strict_types=1);

namespace PCIT\Runner\Tests\Events;

use PCIT\Framework\Support\DB;
use PCIT\Runner\Events\Cache;
use PCIT\Support\CacheKey;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class CacheTest extends TestCase
{
    public $yaml;

    public $cache;

    /**
     * @throws \Exception
     */
    public function common(): void
    {
        $result = Yaml::parse($this->yaml);

        $json = json_encode($result);

        $stub = $this->createMock(Cache::class);

        $stub->method('getPrefix')->willReturn('gittype_rid_branch');

        $cache = new Cache(1,
            1, '', 'github', 1,
            'master', json_decode($json)->cache);

        $cache->handle();

        $this->cache = \Cache::store()->get(CacheKey::cacheKey(1));
    }

    /**
     * @throws \Exception
     */
    public function test_single_array(): void
    {
        DB::close();

        $yaml = <<<'EOF'
cache:
  - dir
EOF;

        $this->yaml = $yaml;

        $this->common();

        $this->assertEquals('INPUT_CACHE=dir', json_decode($this->cache)->Env[6]);
    }

    /**
     * @throws \Exception
     */
    public function test_array(): void
    {
        DB::close();

        $yaml = <<<'EOF'
cache:
  - dir
  - dir2
EOF;

        $this->yaml = $yaml;

        $this->common();

        $this->assertEquals('INPUT_CACHE=dir,dir2', json_decode($this->cache)->Env[6]);
    }

    /**
     * @throws \Exception
     */
    public function test_string(): void
    {
        DB::close();

        $yaml = <<<'EOF'
cache: dir
EOF;

        $this->yaml = $yaml;

        $this->common();

        $this->assertEquals('INPUT_CACHE=dir', json_decode($this->cache)->Env[6]);
    }
}
