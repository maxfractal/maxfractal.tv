<?php

declare(strict_types=1);

namespace Maxfractal;

final class Config
{
    public function __construct(
        public readonly string $dbHost,
        public readonly string $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $metadataBase,
        public readonly string $searchUrl,
        public readonly string $downloadBase,
        public readonly string $detailsBase,
        public readonly int $connectTimeout,
        public readonly int $requestTimeout,
        public readonly int $maxRetries,
        public readonly string $userAgent,
    ) {
    }

    public static function load(string $envFile): self
    {
        $values = [];
        if (is_file($envFile)) {
            $parsed = parse_ini_file($envFile, false, INI_SCANNER_RAW);
            $values = is_array($parsed) ? $parsed : [];
        }

        $read = static function (string $key, string $default) use ($values): string {
            $value = $values[$key] ?? $default;
            if (!is_string($value) && !is_numeric($value)) {
                return $default;
            }
            $text = trim((string) $value);
            return $text === '' ? $default : $text;
        };

        return new self(
            dbHost: $read('DB_HOST', '127.0.0.1'),
            dbPort: $read('DB_PORT', '3306'),
            dbName: $read('DB_NAME', 'maxfractal_tv'),
            dbUser: $read('DB_USER', 'root'),
            dbPassword: array_key_exists('DB_PASSWORD', $values) ? (string) $values['DB_PASSWORD'] : '',
            metadataBase: rtrim($read('ARCHIVE_METADATA_BASE', 'https://archive.org/metadata'), '/'),
            searchUrl: $read('ARCHIVE_SEARCH_URL', 'https://archive.org/advancedsearch.php'),
            downloadBase: rtrim($read('ARCHIVE_DOWNLOAD_BASE', 'https://archive.org/download'), '/'),
            detailsBase: rtrim($read('ARCHIVE_DETAILS_BASE', 'https://archive.org/details'), '/'),
            connectTimeout: max(1, (int) $read('ARCHIVE_CONNECT_TIMEOUT', '5')),
            requestTimeout: max(1, (int) $read('ARCHIVE_REQUEST_TIMEOUT', '20')),
            maxRetries: max(0, (int) $read('ARCHIVE_MAX_RETRIES', '3')),
            userAgent: $read('ARCHIVE_USER_AGENT', 'maxfractal.tv-ingest/0.1 (local catalog import)'),
        );
    }
}
