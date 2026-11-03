<?php

declare(strict_types=1);

namespace Maxfractal\Http;

interface HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?int $maxBytes = null): HttpResponse;
}
