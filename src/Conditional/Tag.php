<?php

declare(strict_types=1);

namespace PCIT\Runner\Conditional;

class Tag extends Kernel
{
    public function regHandle()
    {
        if (null === $this->current) {
            return true;
        }

        return parent::regHandle();
    }
}
