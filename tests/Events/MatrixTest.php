<?php

declare(strict_types=1);

namespace PCIT\Runner\Tests\Events;

use PCIT\Runner\Events\Matrix;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MatrixTest extends TestCase
{
    public function test_parseMatrix(): void
    {
        $yaml = <<<EOF
matrix:
  PHP_VERSION:
    - 7.4.2
    - 7.1.27
  REDIS_VERSION:
    - 5.0.7
  MYSQL_VERSION:
    - 8.0.19
EOF;

        $yaml2 = <<<EOF
matrix:
  include:
    - PHP_VERSION: 7.4.2
      REDIS_VERSION: 5.0.7
      MYSQL_VERSION: 8.0.19
    - PHP_VERSION: 7.1.27
      REDIS_VERSION: 5.0.7
      MYSQL_VERSION: 8.0.19
EOF;

        $result1 = Yaml::parse($yaml);
        $result2 = Yaml::parse($yaml2);

        $result = Matrix::parseMatrix($result1['matrix']);
        $result2 = Matrix::parseMatrix($result2['matrix']);

        $this->assertEquals($result, $result2);
    }
}
