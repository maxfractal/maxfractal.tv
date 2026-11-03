<?php

declare(strict_types=1);

namespace Maxfractal\Http;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
