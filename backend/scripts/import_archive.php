#!/usr/bin/env php
<?php

declare(strict_types=1);

use Maxfractal\Archive\ArchiveService;
use Maxfractal\Archive\MediaInspector;
use Maxfractal\Catalog\CatalogService;
use Maxfractal\Config;
use Maxfractal\Database;
use Maxfractal\Http\CurlHttpClient;
use Maxfractal\Http\RetryingHttpClient;
use Maxfractal\Http\SystemSleeper;
use Maxfractal\Import\ArchiveImporter;

require dirname(__DIR__) . '/src/bootstrap.php';

$options = parseArguments($argv);
if ($options['help'] === true) {
    fwrite(STDOUT, usage());
    exit(0);
}
if ($options['error'] !== null) {
    fwrite(STDERR, $options['error'] . "\n\n" . usage());
    exit(1);
}

$root = dirname(__DIR__, 2);
$config = Config::load(dirname(__DIR__) . '/.env');
$http = new RetryingHttpClient(
    new CurlHttpClient($config->connectTimeout, $config->requestTimeout, $config->userAgent),
    $config->maxRetries,
    new SystemSleeper(),
);
$archive = new ArchiveService($http, $config);
$inspector = new MediaInspector($http, new SystemSleeper(), 100);

$catalog = null;
if ($options['dry_run'] === false) {
    try {
        $catalog = CatalogService::fromPdo(Database::connect($config));
    } catch (Throwable $error) {
        fwrite(STDERR, "Database connection failed.\n");
        exit(1);
    }
}

$importer = new ArchiveImporter($archive, $inspector, $catalog, new SystemSleeper(), 400);

try {
    if ($options['identifier'] !== null) {
        $result = $importer->importIdentifier($options['identifier'], $options['dry_run']);
        $batch = [
            'results' => [$result],
            'counts' => [
                'imported' => $result['outcome'] === 'imported' ? 1 : 0,
                'updated' => $result['outcome'] === 'updated' ? 1 : 0,
                'skipped' => $result['outcome'] === 'skipped' ? 1 : 0,
                'failed' => $result['outcome'] === 'failed' ? 1 : 0,
                'dry_run' => $result['outcome'] === 'dry_run' ? 1 : 0,
            ],
        ];
    } else {
        $manifest = $options['manifest'];
        if (!str_starts_with($manifest, '/')) {
            $manifest = $root . '/' . $manifest;
        }
        $batch = $importer->importManifest($manifest, $options['dry_run']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

foreach ($batch['results'] as $result) {
    fwrite(STDOUT, formatResult($result) . "\n");
    if (($result['outcome'] ?? '') === 'failed' && isset($result['message'])) {
        fwrite(STDOUT, '  ' . $result['message'] . "\n");
    }
}

$counts = $batch['counts'];
fwrite(STDOUT, sprintf(
    "Summary: imported=%d updated=%d skipped=%d failed=%d dry_run=%d\n",
    $counts['imported'] ?? 0,
    $counts['updated'] ?? 0,
    $counts['skipped'] ?? 0,
    $counts['failed'] ?? 0,
    $counts['dry_run'] ?? 0,
));
fwrite(STDOUT, MediaInspector::MULTI_VIDEO_LIMITATION . "\n");
fwrite(STDOUT, "HTML5 playback was not tested. Pending media is not confirmed playable.\n");

if ($catalog !== null) {
    $summary = $catalog->summary(1);
    fwrite(STDOUT, sprintf(
        "Catalog: programs=%d drafts=%d published=%d rights_pending=%d rights_approved=%d preferred_media_pending=%d\n",
        $summary['programs'],
        $summary['drafts'],
        $summary['published'],
        $summary['rights_pending'],
        $summary['rights_approved'],
        $summary['preferred_media_pending'],
    ));
    $channel = $summary['channel_name'] ?? 'Channel 01';
    if ($summary['lineup_count'] === 0) {
        fwrite(STDOUT, "Channel 01 ({$channel}) lineup is empty. No reviewed and approved program is assigned.\n");
    } else {
        fwrite(STDOUT, "Channel 01 ({$channel}) lineup has {$summary['lineup_count']} active assignment(s).\n");
    }
} else {
    fwrite(STDOUT, "Dry run: catalog totals were not changed.\n");
}

exit(($counts['failed'] ?? 0) > 0 ? 2 : 0);

/** @param list<string> $argv
 *  @return array{help: bool, dry_run: bool, identifier: ?string, manifest: ?string, error: ?string}
 */
function parseArguments(array $argv): array
{
    $parsed = [
        'help' => false,
        'dry_run' => false,
        'identifier' => null,
        'manifest' => null,
        'error' => null,
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $parsed['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $parsed['dry_run'] = true;
            continue;
        }
        if (str_starts_with($argument, '--identifier=')) {
            $parsed['identifier'] = substr($argument, strlen('--identifier='));
            continue;
        }
        if (str_starts_with($argument, '--manifest=')) {
            $parsed['manifest'] = substr($argument, strlen('--manifest='));
            continue;
        }
        $parsed['error'] = 'Unknown argument: ' . $argument;
        return $parsed;
    }
    if ($parsed['help']) {
        return $parsed;
    }
    if ($parsed['identifier'] !== null && $parsed['manifest'] !== null) {
        $parsed['error'] = 'Pass either --identifier or --manifest, not both.';
        return $parsed;
    }
    if ($parsed['identifier'] === null && $parsed['manifest'] === null) {
        $parsed['error'] = 'Pass --identifier=ARCHIVE_IDENTIFIER or --manifest=PATH.';
    }
    return $parsed;
}

/** @param array<string, mixed> $result */
function formatResult(array $result): string
{
    return sprintf(
        '%s  %s  preferred=%s  candidates=%s  media=%s  rights=%s  publication=%s  duration=%s',
        (string) ($result['identifier'] ?? ''),
        (string) ($result['outcome'] ?? 'failed'),
        $result['preferred_filename'] ?? '-',
        (string) ($result['candidate_count'] ?? '-'),
        $result['validation_status'] ?? '-',
        $result['rights_status'] ?? '-',
        $result['publication_status'] ?? '-',
        $result['duration_seconds'] ?? '-',
    );
}

function usage(): string
{
    return <<<TXT
Usage:
  php backend/scripts/import_archive.php --identifier=ARCHIVE_IDENTIFIER [--dry-run]
  php backend/scripts/import_archive.php --manifest=backend/data/computer-chronicles.json [--dry-run]

Imports one Internet Archive item as one draft program.
Rights stay pending and nothing is published or added to a channel.

TXT;
}
