<?php

declare(strict_types=1);

namespace Maxfractal\Http;

use Maxfractal\ArchiveTimeoutException;

final class RetryingHttpClient implements HttpClient
{
    public function __construct(
        private readonly HttpClient $inner,
        private readonly int $maxRetries = 3,
        private readonly Sleeper $sleeper = new NullSleeper(),
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?int $maxBytes = null): HttpResponse
    {
        $attempt = 0;
        $fallbackMs = 500;

        while (true) {
            try {
                $response = $this->inner->request($method, $url, $headers, $maxBytes);
            } catch (ArchiveTimeoutException $error) {
                if ($attempt >= $this->maxRetries) {
                    throw $error;
                }
                $this->sleeper->sleep($fallbackMs);
                $fallbackMs = min($fallbackMs * 2, 8000);
                $attempt++;
                continue;
            }

            if (!$this->shouldRetry($response->status) || $attempt >= $this->maxRetries) {
                return $response;
            }

            $this->sleeper->sleep($this->retryDelayMs($response, $fallbackMs));
            $fallbackMs = min($fallbackMs * 2, 8000);
            $attempt++;
        }
    }

    private function shouldRetry(int $status): bool
    {
        return in_array($status, [429, 500, 502, 503, 504], true);
    }

    private function retryDelayMs(HttpResponse $response, int $fallback): int
    {
        $header = $response->header('retry-after');
        if ($header === null || trim($header) === '') {
            return $fallback;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            return min(15000, max(0, (int) $header * 1000));
        }
        $when = strtotime($header);
        if ($when === false) {
            return $fallback;
        }
        return min(15000, max(0, ($when - time()) * 1000));
    }
}
