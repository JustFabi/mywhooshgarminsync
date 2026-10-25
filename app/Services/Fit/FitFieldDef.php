<?php

namespace App\Services\Fit;

class FitFieldDef
{
    public function __construct(
        public int $num,
        public int $size,
        public int $baseType,
    ) {}
}
