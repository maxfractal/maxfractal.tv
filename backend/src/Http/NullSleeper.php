<?php

declare(strict_types=1);

namespace Maxfractal\Http;

final class NullSleeper implements Sleeper
{
    public function sleep(int $milliseconds): void
    {
    }
}
