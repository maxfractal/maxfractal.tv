<?php

declare(strict_types=1);

namespace Maxfractal\Http;

use Maxfractal\ArchiveHttpException;
use Maxfractal\ArchiveTimeoutException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $connectTimeout = 5,
        private readonly int $requestTimeout = 20,
        private readonly string $userAgent = 'maxfractal.tv-ingest/0.1 (local catalog import)',
        private readonly int $maxRedirects = 3,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?int $maxBytes = null): HttpResponse
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new ArchiveHttpException('Only GET and HEAD requests are allowed');
        }

        $current = $url;
        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            $this->assertAllowedUrl($current);
            $response = $this->execute($method, $current, $headers, $maxBytes);
            if (!in_array($response->status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }
            if ($hop === $this->maxRedirects) {
                break;
            }
            $location = $response->header('location');
            if ($location === null || trim($location) === '') {
                throw new ArchiveHttpException('Archive redirect did not include a location', $response->status);
            }
            $current = self::resolveUrl($current, trim($location));
        }

        throw new ArchiveHttpException('Archive redirected too many times');
    }

    public static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new ArchiveHttpException('Refusing a malformed URL');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            throw new ArchiveHttpException('Refusing a non-HTTPS Archive URL');
        }
        if ($host !== 'archive.org' && !str_ends_with($host, '.archive.org')) {
            throw new ArchiveHttpException('Refusing a request outside approved Archive hosts');
        }
        if (str_contains($host, '..') || str_contains($url, "\0")) {
            throw new ArchiveHttpException('Refusing a malformed Archive host');
        }
    }

    public static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $scheme . '://' . $host . $port . $location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $slash = strrpos($path, '/');
        $directory = $slash === false ? '' : substr($path, 0, $slash);
        return $scheme . '://' . $host . $port . $directory . '/' . $location;
    }

    /** @param array<string, string> $headers */
    private function execute(string $method, string $url, array $headers, ?int $maxBytes): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new ArchiveHttpException('cURL is not available');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new ArchiveHttpException('Could not start an Archive request');
        }

        $responseHeaders = [];
        $body = '';
        $stoppedEarly = false;
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return $length;
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $key = strtolower(trim($name));
                $content = trim($value);
                if (isset($responseHeaders[$key]) && $responseHeaders[$key] !== '') {
                    $responseHeaders[$key] .= ', ' . $content;
                } else {
                    $responseHeaders[$key] = $content;
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$stoppedEarly, $maxBytes): int {
                $incoming = strlen($chunk);
                if ($maxBytes !== null && strlen($body) + $incoming > $maxBytes) {
                    $room = $maxBytes - strlen($body);
                    if ($room > 0) {
                        $body .= substr($chunk, 0, $room);
                    }
                    $stoppedEarly = true;
                    return 0;
                }
                $body .= $chunk;
                return $incoming;
            },
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($handle, $options);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $effectiveUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        if ($ok === false && !($stoppedEarly && $errno === CURLE_WRITE_ERROR)) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new ArchiveTimeoutException('Archive request timed out');
            }
            throw new ArchiveHttpException($error !== '' ? $error : 'Archive request failed');
        }

        return new HttpResponse($status, $responseHeaders, $body, $effectiveUrl !== '' ? $effectiveUrl : $url);
    }
}
