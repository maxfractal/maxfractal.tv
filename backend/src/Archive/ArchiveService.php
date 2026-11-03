<?php

declare(strict_types=1);

namespace Maxfractal\Archive;

use Maxfractal\ArchiveHttpException;
use Maxfractal\ArchiveResponseException;
use Maxfractal\Config;
use Maxfractal\Http\HttpClient;
use Maxfractal\InvalidIdentifierException;

/**
 * Reads Internet Archive metadata and search results.
 * Missing fields stay null. This service does not invent titles, durations, or licenses.
 */
final class ArchiveService
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    public static function assertValidIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $identifier) !== 1 || str_contains($identifier, '..')) {
            throw new InvalidIdentifierException('Invalid Internet Archive identifier');
        }
    }

    /** @return array<string, mixed> */
    public function fetchMetadata(string $identifier): array
    {
        self::assertValidIdentifier($identifier);
        $url = $this->config->metadataBase . '/' . rawurlencode($identifier);
        $response = $this->http->request('GET', $url);
        if ($response->status !== 200) {
            throw new ArchiveHttpException(
                'Archive metadata request failed with HTTP ' . $response->status,
                $response->status,
            );
        }

        return $this->normalize($response->body, $identifier);
    }

    /**
     * Internet Archive Advanced Search.
     *
     * @return array{num_found: ?int, docs: list<array<string, ?string>>}
     */
    public function search(string $query, int $rows = 20, int $page = 1): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new ArchiveResponseException('Archive search query is empty');
        }
        $rows = max(1, min(100, $rows));
        $page = max(1, $page);
        $url = $this->config->searchUrl
            . '?'
            . http_build_query([
                'q' => $query,
                'output' => 'json',
                'rows' => $rows,
                'page' => $page,
            ])
            . '&fl[]=identifier&fl[]=title&fl[]=year&fl[]=licenseurl&fl[]=rights';

        $response = $this->http->request('GET', $url);
        if ($response->status !== 200) {
            throw new ArchiveHttpException(
                'Archive search request failed with HTTP ' . $response->status,
                $response->status,
            );
        }

        $payload = $this->decodeObject($response->body);
        $result = $payload['response'] ?? null;
        if (!is_array($result)) {
            throw new ArchiveResponseException('Archive search response has no response object');
        }

        $docs = [];
        foreach ($result['docs'] ?? [] as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $identifier = $this->joined($doc['identifier'] ?? null, ' ');
            if ($identifier === null) {
                continue;
            }
            $docs[] = [
                'identifier' => $identifier,
                'title' => $this->joined($doc['title'] ?? null, ' | '),
                'year' => $this->joined($doc['year'] ?? null, ' | '),
                'licenseurl' => $this->joined($doc['licenseurl'] ?? null, ' | '),
                'rights' => $this->joined($doc['rights'] ?? null, ' | '),
            ];
        }

        return [
            'num_found' => is_numeric($result['numFound'] ?? null) ? (int) $result['numFound'] : null,
            'docs' => $docs,
        ];
    }

    /** @return array<string, mixed> */
    public function normalize(string $json, string $expectedIdentifier): array
    {
        self::assertValidIdentifier($expectedIdentifier);
        $payload = $this->decodeObject($json);
        $metadata = $payload['metadata'] ?? null;
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new ArchiveResponseException('Archive metadata object is missing');
        }

        $identifier = $this->joined($metadata['identifier'] ?? null, ' ');
        if ($identifier === null) {
            throw new ArchiveResponseException('Archive metadata did not include an identifier');
        }
        self::assertValidIdentifier($identifier);
        if ($identifier !== $expectedIdentifier) {
            throw new ArchiveResponseException('Archive metadata identifier did not match the request');
        }

        $description = $this->plainText($this->joined($metadata['description'] ?? null, "\n\n"));
        $licenseUrl = $this->firstUrl($metadata['licenseurl'] ?? null);
        $licenseLabel = $this->shortLabel($metadata['license'] ?? null);
        $title = $this->joined($metadata['title'] ?? null, ' | ');

        $files = [];
        $thumbnail = null;
        foreach ($this->fileRecords($payload['files'] ?? null) as $file) {
            $normalized = $this->normalizeFile($identifier, $file);
            if ($normalized === null) {
                continue;
            }
            $files[] = $normalized;
            if ($thumbnail === null && $this->isThumbnail($normalized)) {
                $thumbnail = $normalized['media_url'];
            }
        }

        foreach ($files as $file) {
            if (strtolower($file['filename']) === '__ia_thumb.jpg') {
                $thumbnail = $file['media_url'];
                break;
            }
        }

        return [
            'identifier' => $identifier,
            'title' => $title,
            'description' => $description,
            'duration_seconds' => self::parseDuration($metadata['runtime'] ?? null),
            'source_url' => $this->config->detailsBase . '/' . rawurlencode($identifier),
            'thumbnail_url' => $thumbnail,
            'license_label' => $licenseLabel,
            'license_url' => $licenseUrl,
            'rights_notes' => $this->rightsNotes($metadata, $description, $licenseUrl, $licenseLabel),
            'files' => $files,
        ];
    }

    public static function parseDuration(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value)) {
            $seconds = (int) round($value);
            return $seconds > 0 ? $seconds : null;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $parsed = self::parseDuration($item);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $seconds = (int) round((float) $value);
            return $seconds > 0 ? $seconds : null;
        }
        if (preg_match('/^(?:(\d+):)?(\d+):(\d{2})$/', $value, $matches) === 1) {
            $hours = ($matches[1] ?? '') === '' ? 0 : (int) $matches[1];
            $seconds = ($hours * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
            return $seconds > 0 ? $seconds : null;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ArchiveResponseException('Archive response was not valid JSON', 0, $error);
        }
        if (!is_array($decoded) || !str_starts_with(ltrim($json), '{')) {
            throw new ArchiveResponseException('Archive response was not a JSON object');
        }
        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    private function fileRecords(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }
        if ($files !== [] && !array_is_list($files) && isset($files['name'])) {
            $files = [$files];
        }
        $records = [];
        foreach ($files as $file) {
            if (is_array($file)) {
                $records[] = $file;
            }
        }
        return $records;
    }

    /** @param array<string, mixed> $file
     *  @return array<string, mixed>|null
     */
    private function normalizeFile(string $identifier, array $file): ?array
    {
        $name = $file['name'] ?? null;
        if (!is_string($name) || !$this->safeFilename($name)) {
            return null;
        }
        $format = $this->joined($file['format'] ?? null, ' | ');
        if ($format !== null && strlen($format) > 100) {
            $format = substr($format, 0, 100);
        }

        return [
            'filename' => $name,
            'format' => $format,
            'mime_type' => null,
            'size_bytes' => $this->positiveInt($file['size'] ?? null),
            'duration_seconds' => self::parseDuration($file['length'] ?? null),
            'media_url' => $this->downloadUrl($identifier, $name),
            'source' => $this->joined($file['source'] ?? null, ' | '),
        ];
    }

    private function downloadUrl(string $identifier, string $filename): string
    {
        $segments = array_map('rawurlencode', explode('/', $filename));
        return $this->config->downloadBase . '/' . rawurlencode($identifier) . '/' . implode('/', $segments);
    }

    private function safeFilename(string $name): bool
    {
        if ($name === '' || strlen($name) > 500 || str_contains($name, "\0") || str_contains($name, '\\')) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $file */
    private function isThumbnail(array $file): bool
    {
        $format = strtolower((string) ($file['format'] ?? ''));
        $name = strtolower((string) $file['filename']);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return str_contains($format, 'thumb')
            || str_contains($format, 'item tile')
            || $name === '__ia_thumb.jpg'
            || str_contains($name, '.thumbs/')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /** @param array<string, mixed> $metadata */
    private function rightsNotes(array $metadata, ?string $description, ?string $licenseUrl, ?string $licenseLabel): string
    {
        $lines = [];
        foreach ([
            'creator' => 'creator',
            'collection' => 'collection',
            'rights' => 'rights',
            'possible-copyright-status' => 'possible-copyright-status',
            'usage' => 'usage',
            'copyright' => 'copyright',
        ] as $key => $label) {
            $text = $this->joined($metadata[$key] ?? null, ' | ');
            if ($text !== null) {
                $lines[] = $label . ': ' . $text;
            }
        }
        if ($licenseLabel !== null) {
            $lines[] = 'license: ' . $licenseLabel;
        }
        if ($licenseUrl !== null) {
            $lines[] = 'licenseurl: ' . $licenseUrl;
        }
        $copyright = $this->copyrightExcerpt($description);
        if ($copyright !== null) {
            $lines[] = 'description: ' . $copyright;
        }
        if ($lines === []) {
            $lines[] = 'No licenseurl, license, or rights field was present in the Archive metadata.';
        }
        $lines[] = 'Rights are not approved. Human review is required before publication.';
        return implode("\n", $lines);
    }

    private function copyrightExcerpt(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        if (preg_match('/copyright\s+[^.]{0,180}\.?/i', $description, $matches) === 1) {
            return trim($matches[0]);
        }
        return null;
    }

    private function joined(mixed $value, string $separator): ?string
    {
        $parts = [];
        $append = static function (mixed $item) use (&$parts): void {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        };
        if (is_array($value)) {
            foreach ($value as $item) {
                $append($item);
            }
        } else {
            $append($value);
        }
        if ($parts === []) {
            return null;
        }
        return implode($separator, $parts);
    }

    private function plainText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", trim($text)) ?? trim($text);
        $text = trim($text);
        return $text === '' ? null : $text;
    }

    private function firstUrl(mixed $value): ?string
    {
        $candidates = is_array($value) ? $value : [$value];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
            if ($scheme === 'http' || $scheme === 'https') {
                return $candidate;
            }
        }
        return null;
    }

    private function shortLabel(mixed $value): ?string
    {
        $text = $this->joined($value, ' | ');
        if ($text === null || strlen($text) > 255) {
            return null;
        }
        return $text;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $number = (int) $value;
            return $number > 0 ? $number : null;
        }
        if (is_float($value) && $value > 0) {
            return (int) round($value);
        }
        return null;
    }
}
