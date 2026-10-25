<?php

namespace App\Services\Fit;

class FitDevFieldDef
{
    public function __construct(
        public int $num,
        public int $size,
        public int $devIndex,
    ) {}
}
