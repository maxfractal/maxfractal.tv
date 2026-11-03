<?php

declare(strict_types=1);

namespace Maxfractal\Import;

use Maxfractal\Archive\ArchiveService;
use Maxfractal\Archive\MediaInspector;
use Maxfractal\ArchiveException;
use Maxfractal\Catalog\CatalogService;
use Maxfractal\Http\NullSleeper;
use Maxfractal\Http\Sleeper;
use Maxfractal\InvalidIdentifierException;
use RuntimeException;

final class ArchiveImporter
{
    public function __construct(
        private readonly ArchiveService $archive,
        private readonly MediaInspector $inspector,
        private readonly ?CatalogService $catalog = null,
        private readonly Sleeper $sleeper = new NullSleeper(),
        private readonly int $batchDelayMs = 0,
    ) {
    }

    /** @return array<string, mixed> */
    public function importIdentifier(string $identifier, bool $dryRun = false, bool $probe = true): array
    {
        try {
            ArchiveService::assertValidIdentifier($identifier);
            $item = $this->inspector->inspect($this->archive->fetchMetadata($identifier), $probe);
            if ($dryRun) {
                return $this->dryRunResult($item);
            }
            if ($this->catalog === null) {
                throw new RuntimeException('Catalog service is not configured');
            }
            return $this->catalog->persist($item);
        } catch (ArchiveException | RuntimeException $error) {
            $this->recordFailure($identifier, $error->getMessage());
            return [
                'outcome' => 'failed',
                'identifier' => $identifier,
                'message' => $error->getMessage(),
            ];
        }
    }

    /** @return array{results: list<array<string, mixed>>, counts: array<string, int>} */
    public function importManifest(string $path, bool $dryRun = false, bool $probe = true): array
    {
        $identifiers = $this->readManifest($path);
        $results = [];
        $seen = [];
        foreach ($identifiers as $index => $identifier) {
            if ($index > 0 && $this->batchDelayMs > 0) {
                $this->sleeper->sleep($this->batchDelayMs);
            }
            if (isset($seen[$identifier])) {
                $results[] = [
                    'outcome' => 'skipped',
                    'identifier' => $identifier,
                    'message' => 'Duplicate identifier in this manifest',
                ];
                continue;
            }
            $seen[$identifier] = true;
            $results[] = $this->importIdentifier($identifier, $dryRun, $probe);
        }

        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'dry_run' => 0];
        foreach ($results as $result) {
            $outcome = (string) ($result['outcome'] ?? 'failed');
            if (!isset($counts[$outcome])) {
                $counts[$outcome] = 0;
            }
            $counts[$outcome]++;
        }
        return ['results' => $results, 'counts' => $counts];
    }

    /** @return list<string> */
    public function readManifest(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Manifest not found: ' . $path);
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Manifest is not valid JSON', 0, $error);
        }

        $items = $decoded;
        if (is_array($decoded) && isset($decoded['items'])) {
            $items = $decoded['items'];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('Manifest items must be a list');
        }

        $identifiers = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $identifiers[] = $item;
                continue;
            }
            if (is_array($item) && isset($item['identifier']) && is_string($item['identifier'])) {
                $identifiers[] = $item['identifier'];
                continue;
            }
            throw new RuntimeException('Manifest item is missing an identifier');
        }
        return $identifiers;
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed>
     */
    private function dryRunResult(array $item): array
    {
        return [
            'outcome' => 'dry_run',
            'identifier' => $item['identifier'],
            'title' => $item['title'],
            'preferred_filename' => $item['preferred_filename'],
            'candidate_count' => $item['candidate_count'],
            'duration_seconds' => $item['duration_seconds'],
            'rights_status' => 'pending',
            'publication_status' => 'draft',
            'validation_status' => $this->preferredValidation($item['media_files'] ?? []),
            'message' => 'Dry run; nothing was written',
        ];
    }

    /** @param list<array<string, mixed>> $files */
    private function preferredValidation(array $files): ?string
    {
        foreach ($files as $file) {
            if (!empty($file['is_preferred'])) {
                return isset($file['validation_status']) ? (string) $file['validation_status'] : null;
            }
        }
        return null;
    }

    private function recordFailure(string $identifier, string $message): void
    {
        if ($this->catalog === null || strlen($identifier) > 255) {
            return;
        }
        try {
            ArchiveService::assertValidIdentifier($identifier);
        } catch (InvalidIdentifierException) {
            return;
        }
        try {
            $this->catalog->recordFailure($identifier, $message);
        } catch (\Throwable) {
            // The original import error is still returned to the caller.
        }
    }
}
