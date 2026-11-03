<?php

declare(strict_types=1);

use Maxfractal\Archive\ArchiveService;
use Maxfractal\Archive\MediaInspector;
use Maxfractal\ArchiveException;
use Maxfractal\ArchiveHttpException;
use Maxfractal\ArchiveResponseException;
use Maxfractal\ArchiveTimeoutException;
use Maxfractal\Catalog\CatalogService;
use Maxfractal\Catalog\MediaFileRepository;
use Maxfractal\Config;
use Maxfractal\Database;
use Maxfractal\Http\CurlHttpClient;
use Maxfractal\Http\HttpClient;
use Maxfractal\Http\HttpResponse;
use Maxfractal\Http\NullSleeper;
use Maxfractal\Http\RetryingHttpClient;
use Maxfractal\Http\Sleeper;
use Maxfractal\Import\ArchiveImporter;
use Maxfractal\InvalidIdentifierException;

require dirname(__DIR__) . '/src/bootstrap.php';

final class TestRunner
{
    public int $passed = 0;
    public int $failed = 0;

    public function test(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo "ok  {$name}\n";
        } catch (Throwable $error) {
            $this->failed++;
            echo "FAIL {$name}\n  {$error->getMessage()}\n";
        }
    }
}

final class FakeHttpClient implements HttpClient
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param callable $handler */
    public function __construct(private $handler)
    {
    }

    public function request(string $method, string $url, array $headers = [], ?int $maxBytes = null): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'maxBytes');
        return ($this->handler)($method, $url, $headers, $maxBytes, count($this->calls));
    }
}

final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    public array $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }
}

final class ExplodingMediaFileRepository extends MediaFileRepository
{
    public function upsertAll(int $programId, array $files): void
    {
        throw new RuntimeException('forced media failure');
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)
        );
    }
}

function fixture(string $name): string
{
    $path = __DIR__ . '/fixtures/' . $name;
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Missing fixture ' . $name);
    }
    return $contents;
}

function testConfig(): Config
{
    return Config::load(dirname(__DIR__) . '/.env');
}

/** @param array<string, string> $headers */
function httpResponse(int $status, string $body, array $headers = []): HttpResponse
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower($name)] = $value;
    }
    return new HttpResponse($status, $normalized, $body, 'https://archive.org/test');
}

function service(HttpClient $http): ArchiveService
{
    return new ArchiveService($http, testConfig());
}

/** @return array<string, mixed> */
function sampleItem(string $identifier, string $title = 'Test Title'): array
{
    return [
        'identifier' => $identifier,
        'title' => $title,
        'description' => 'A description',
        'duration_seconds' => 100,
        'source_url' => 'https://archive.org/details/' . $identifier,
        'thumbnail_url' => 'https://archive.org/download/' . $identifier . '/__ia_thumb.jpg',
        'license_label' => null,
        'license_url' => null,
        'rights_notes' => 'No license field was present. Human review is required.',
        'preferred_filename' => 'show.mp4',
        'candidate_count' => 2,
        'media_files' => [
            [
                'filename' => 'show.mp4',
                'format' => 'h.264',
                'mime_type' => 'video/mp4',
                'size_bytes' => 1000,
                'duration_seconds' => 100,
                'media_url' => 'https://archive.org/download/' . $identifier . '/show.mp4',
                'is_preferred' => true,
                'validation_status' => 'pending',
                'supports_byte_ranges' => true,
                'validation_notes' => 'HTML5 playback was not tested.',
                'last_checked_at' => '2026-11-02 12:00:00',
            ],
            [
                'filename' => 'show.ogv',
                'format' => 'Ogg Video',
                'mime_type' => 'video/ogg',
                'size_bytes' => 800,
                'duration_seconds' => 100,
                'media_url' => 'https://archive.org/download/' . $identifier . '/show.ogv',
                'is_preferred' => false,
                'validation_status' => 'pending',
                'supports_byte_ranges' => null,
                'validation_notes' => 'Kept as an additional candidate.',
                'last_checked_at' => '2026-11-02 12:00:00',
            ],
        ],
    ];
}

function cleanupTests(PDO $pdo): void
{
    $pdo->exec("DELETE FROM import_events WHERE archive_identifier LIKE 'zztest%'");
    $pdo->exec("DELETE FROM programs WHERE archive_identifier LIKE 'zztest%'");
}

$runner = new TestRunner();
$archive = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')));

$runner->test('valid metadata normalizes source fields without inventing a license', function () use ($archive): void {
    $item = $archive->normalize(fixture('valid_metadata.json'), 'education_2');
    expectSame('Computers in Education', $item['title'], 'title');
    expect(str_contains((string) $item['description'], 'Copyright 1984 Stewart Cheifet Productions'), 'copyright text kept');
    expectSame(null, $item['duration_seconds'], 'runtime was absent');
    expectSame(null, $item['license_url'], 'license url');
    expectSame(null, $item['license_label'], 'license label');
    expect(str_contains($item['rights_notes'], 'computerchronicles'), 'collection recorded');
    expect(str_contains($item['rights_notes'], 'Human review is required'), 'review flag');
    expect(!str_contains(strtolower($item['rights_notes']), 'approved for publication'), 'no approval claim');
    expectSame('https://archive.org/details/education_2', $item['source_url'], 'source');
    expectSame('https://archive.org/download/education_2/__ia_thumb.jpg', $item['thumbnail_url'], 'thumb');
    expectSame(8, count($item['files']), 'all files retained for classification');
});

$runner->test('metadata arrays and runtime are preserved', function () use ($archive): void {
    $item = $archive->normalize(fixture('array_metadata.json'), 'example_array');
    expectSame('Primary Title | Secondary Title', $item['title'], 'array title');
    expect(str_contains((string) $item['description'], 'First paragraph.'), 'array description');
    expectSame(1710, $item['duration_seconds'], 'runtime 28:30');
    expectSame('https://example.org/license', $item['license_url'], 'license url');
    expect(str_contains($item['rights_notes'], 'not approved'), 'still unapproved');
});

$runner->test('missing duration stays null', function () use ($archive): void {
    $item = $archive->normalize(fixture('missing_fields.json'), 'missing_fields');
    expectSame('Untitled Duration', $item['title'], 'title');
    expectSame(null, $item['description'], 'description');
    expectSame(null, $item['duration_seconds'], 'duration');
    expectSame(null, $item['files'][0]['duration_seconds'], 'file duration');
    expect(str_contains($item['rights_notes'], 'No licenseurl'), 'missing rights noted');
});

$runner->test('malformed and incomplete metadata are rejected', function () use ($archive): void {
    foreach (['not-json', '[]', 'null', '"text"', '{', '{"metadata":[]}', '{"metadata":{}}'] as $body) {
        try {
            $archive->normalize($body, 'education_2');
            throw new RuntimeException('accepted malformed payload: ' . $body);
        } catch (ArchiveResponseException) {
        }
    }
    try {
        $archive->normalize('{"metadata":{"identifier":"other_id","title":"X"}}', 'education_2');
        throw new RuntimeException('accepted mismatched identifier');
    } catch (ArchiveResponseException) {
    }
});

$runner->test('invalid identifiers are rejected', function (): void {
    foreach (['', ' ', 'has space', '../etc', 'foo/bar', '-leading', str_repeat('a', 101), 'bad..id'] as $identifier) {
        try {
            ArchiveService::assertValidIdentifier($identifier);
            throw new RuntimeException('accepted ' . $identifier);
        } catch (InvalidIdentifierException) {
        }
    }
    ArchiveService::assertValidIdentifier('education_2');
    ArchiveService::assertValidIdentifier('Artifici1984');
});

$runner->test('duration parser accepts only explicit values', function (): void {
    expectSame(1426, ArchiveService::parseDuration('1426.44'), 'seconds');
    expectSame(1710, ArchiveService::parseDuration('28:30'), 'mm:ss');
    expectSame(3723, ArchiveService::parseDuration('1:02:03'), 'hh:mm:ss');
    expectSame(null, ArchiveService::parseDuration('0'), 'zero');
    expectSame(null, ArchiveService::parseDuration(''), 'empty');
    expectSame(null, ArchiveService::parseDuration(null), 'null');
    expectSame(null, ArchiveService::parseDuration('about thirty minutes'), 'prose');
});

$runner->test('search response uses only returned fields', function (): void {
    $http = new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, fixture('search_response.json')));
    $result = service($http)->search('collection:computerchronicles AND mediatype:movies');
    expectSame(634, $result['num_found'], 'num found');
    expectSame('education_2', $result['docs'][0]['identifier'], 'first id');
    expectSame(null, $result['docs'][0]['licenseurl'], 'absent license');
    expectSame('Artificial Intelligence', $result['docs'][1]['title'], 'array title');
    expect(str_contains($http->calls[0]['url'], 'advancedsearch.php'), 'search endpoint');
});

$runner->test('timeouts and rate limits are handled', function (): void {
    $attempts = 0;
    $http = new FakeHttpClient(static function () use (&$attempts): HttpResponse {
        $attempts++;
        if ($attempts === 1) {
            throw new ArchiveTimeoutException('timed out');
        }
        return httpResponse(200, fixture('missing_fields.json'));
    });
    $sleeper = new RecordingSleeper();
    $retrying = new RetryingHttpClient($http, 1, $sleeper);
    $item = (new ArchiveService($retrying, testConfig()))->fetchMetadata('missing_fields');
    expectSame('Untitled Duration', $item['title'], 'recovered title');
    expectSame(1, count($sleeper->delays), 'one backoff');

    $limited = new FakeHttpClient(static function (string $method, string $url, array $headers, ?int $max, int $call): HttpResponse {
        return $call === 1
            ? httpResponse(429, '', ['Retry-After' => '1'])
            : httpResponse(200, fixture('missing_fields.json'));
    });
    $rateSleeper = new RecordingSleeper();
    $item = (new ArchiveService(new RetryingHttpClient($limited, 1, $rateSleeper), testConfig()))->fetchMetadata('missing_fields');
    expectSame('Untitled Duration', $item['title'], 'rate limit recovered');
    expectSame(1000, $rateSleeper->delays[0], 'retry-after honored');

    $exhausted = new RetryingHttpClient(new FakeHttpClient(static function (): HttpResponse {
        throw new ArchiveTimeoutException('timed out');
    }), 1, new NullSleeper());
    try {
        (new ArchiveService($exhausted, testConfig()))->fetchMetadata('missing_fields');
        throw new RuntimeException('timeout was swallowed');
    } catch (ArchiveTimeoutException) {
    }
});

$runner->test('metadata HTTP errors are not treated as items', function (): void {
    $http = new FakeHttpClient(static fn (): HttpResponse => httpResponse(404, '{"error":"not found"}'));
    try {
        service($http)->fetchMetadata('education_2');
        throw new RuntimeException('404 was accepted');
    } catch (ArchiveHttpException $error) {
        expectSame(404, $error->status, 'status');
    }
});

$runner->test('outbound requests stay on approved Archive hosts', function (): void {
    $client = new CurlHttpClient();
    foreach ([
        'http://archive.org/metadata/education_2',
        'https://archive.org.evil.example/metadata/education_2',
        'https://evil.example/download/education_2/file.mp4',
    ] as $url) {
        try {
            $client->request('GET', $url);
            throw new RuntimeException('allowed ' . $url);
        } catch (ArchiveHttpException) {
        }
    }
});

$runner->test('multiple media candidates keep one preferred file', function (): void {
    $item = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')))
        ->normalize(fixture('valid_metadata.json'), 'education_2');
    $inspected = (new MediaInspector(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, ''))))
        ->inspect($item, false);
    $names = array_column($inspected['media_files'], 'filename');
    sort($names);
    expectSame(
        ['clip.wmv', 'education_2.mp4', 'education_2.mpeg', 'education_2.ogv', 'education_2_512kb.mp4'],
        $names,
        'video candidates'
    );
    expect(!in_array('education_2.asr.srt', $names, true), 'subtitle excluded');
    expect(!in_array('__ia_thumb.jpg', $names, true), 'thumbnail excluded');
    expectSame('education_2.mp4', $inspected['preferred_filename'], 'preferred');
    expectSame(1426, $inspected['duration_seconds'], 'preferred duration');
    $preferred = null;
    $preferredCount = 0;
    foreach ($inspected['media_files'] as $file) {
        if ($file['is_preferred']) {
            $preferredCount++;
            $preferred = $file;
        }
        if ($file['filename'] === 'clip.wmv') {
            expectSame('unsupported', $file['validation_status'], 'wmv');
        }
    }
    expectSame(1, $preferredCount, 'single preferred');
    expectSame('pending', $preferred['validation_status'], 'preferred status');
    expect(str_contains($preferred['validation_notes'], 'HTML5 playback was not tested'), 'playback note');
});

$runner->test('unsupported-only items have no preferred file', function (): void {
    $json = json_encode([
        'metadata' => ['identifier' => 'only_wmv', 'title' => 'Windows clip'],
        'files' => [['name' => 'clip.wmv', 'format' => 'Windows Media', 'size' => '10']],
    ], JSON_THROW_ON_ERROR);
    $item = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')))->normalize($json, 'only_wmv');
    $inspected = (new MediaInspector(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, ''))))->inspect($item, false);
    expectSame(null, $inspected['preferred_filename'], 'preferred');
    expectSame('unsupported', $inspected['media_files'][0]['validation_status'], 'status');
    expectSame(1, $inspected['candidate_count'], 'candidate retained');
});

$runner->test('missing file duration remains pending', function (): void {
    $item = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')))
        ->normalize(fixture('missing_fields.json'), 'missing_fields');
    $inspected = (new MediaInspector(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, ''))))->inspect($item, false);
    expectSame(null, $inspected['duration_seconds'], 'duration');
    expectSame('pending', $inspected['media_files'][0]['validation_status'], 'status');
    expect(str_contains($inspected['media_files'][0]['validation_notes'], 'duration was not present'), 'duration note');
    expect(str_contains($inspected['media_files'][0]['validation_notes'], 'codec was not verified'), 'codec note');
});

$runner->test('probes record availability without claiming playback', function (): void {
    $calls = 0;
    $http = new FakeHttpClient(static function (string $method, string $url, array $headers, ?int $maxBytes) use (&$calls): HttpResponse {
        $calls++;
        if ($method === 'HEAD') {
            return httpResponse(200, '', ['Accept-Ranges' => 'bytes', 'Content-Type' => 'video/mp4']);
        }
        expectSame(64, $maxBytes, 'range body cap');
        expectSame('bytes=0-15', $headers['Range'], 'range header');
        return httpResponse(206, '0123456789abcdef', ['Content-Type' => 'video/mp4', 'Content-Range' => 'bytes 0-15/100']);
    });
    $item = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')))
        ->normalize(fixture('missing_fields.json'), 'missing_fields');
    $inspected = (new MediaInspector($http))->inspect($item, true);
    $file = $inspected['media_files'][0];
    expectSame('pending', $file['validation_status'], 'status');
    expectSame(true, $file['supports_byte_ranges'], 'ranges');
    expect(str_contains($file['validation_notes'], '206'), 'range result');
    expect(str_contains($file['validation_notes'], 'HTML5 playback was not tested'), 'no playback claim');
    expect($calls >= 2, 'head and range');
});

$runner->test('unavailable and timed-out probes stay uncertain or unavailable', function (): void {
    $missing = service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}')))
        ->normalize(fixture('valid_metadata.json'), 'education_2');
    $http = new FakeHttpClient(static function (string $method, string $url): HttpResponse {
        if (str_contains($url, 'education_2.mp4') && !str_contains($url, '512kb')) {
            return httpResponse(404, '');
        }
        if (str_contains($url, 'education_2.ogv')) {
            throw new ArchiveTimeoutException('timed out');
        }
        return httpResponse(200, '', ['Accept-Ranges' => 'bytes']);
    });
    $inspected = (new MediaInspector($http))->inspect($missing, true);
    $byName = [];
    foreach ($inspected['media_files'] as $file) {
        $byName[$file['filename']] = $file;
    }
    expectSame('unavailable', $byName['education_2.mp4']['validation_status'], 'missing mp4');
    expectSame(false, $byName['education_2.mp4']['is_preferred'], 'missing mp4 not preferred');
    expectSame('pending', $byName['education_2.ogv']['validation_status'], 'timeout stays pending');
    expect($inspected['preferred_filename'] !== 'education_2.mp4', 'preferred moved off the 404');
    expect($inspected['preferred_filename'] !== null, 'another candidate remains preferred');
});

$pdo = Database::connect(testConfig());
cleanupTests($pdo);

$runner->test('duplicate imports update the same program', function () use ($pdo): void {
    $catalog = CatalogService::fromPdo($pdo);
    $first = $catalog->persist(sampleItem('zztestdupe', 'Original Title'));
    $again = sampleItem('zztestdupe', 'Updated Title');
    $again['description'] = null;
    $second = $catalog->persist($again);
    expectSame('imported', $first['outcome'], 'first outcome');
    expectSame('updated', $second['outcome'], 'second outcome');
    expectSame($first['program_id'], $second['program_id'], 'same id');
    $count = $pdo->prepare('SELECT COUNT(*) FROM programs WHERE archive_identifier = ?');
    $count->execute(['zztestdupe']);
    expectSame(1, (int) $count->fetchColumn(), 'program rows');
    $media = $pdo->prepare('SELECT COUNT(*) FROM media_files WHERE program_id = ?');
    $media->execute([$first['program_id']]);
    expectSame(2, (int) $media->fetchColumn(), 'media rows');
    $title = $pdo->prepare('SELECT title, description, publication_status, rights_status FROM programs WHERE id = ?');
    $title->execute([$first['program_id']]);
    $row = $title->fetch();
    expectSame('Updated Title', $row['title'], 'title updated');
    expectSame('A description', $row['description'], 'null description did not erase');
    expectSame('draft', $row['publication_status'], 'remains draft');
    expectSame('pending', $row['rights_status'], 'rights pending');
});

$runner->test('rights and publication decisions survive re-import', function () use ($pdo): void {
    $catalog = CatalogService::fromPdo($pdo);
    $saved = $catalog->persist(sampleItem('zztestrights', 'Before Review'));
    $pdo->prepare(
        "UPDATE programs
         SET rights_status = 'approved', rights_notes = 'Reviewed by editor', publication_status = 'published'
         WHERE id = ?"
    )->execute([$saved['program_id']]);
    $channel = $pdo->query('SELECT id FROM channels WHERE channel_number = 1')->fetch();
    $pdo->prepare('INSERT INTO channel_programs (channel_id, program_id, sort_order, is_active) VALUES (?, ?, 1, 1)')
        ->execute([(int) $channel['id'], $saved['program_id']]);

    $updated = sampleItem('zztestrights', 'After Import');
    $updated['rights_notes'] = 'This source note must not replace the review.';
    $catalog->persist($updated);

    $row = $pdo->prepare('SELECT title, rights_status, rights_notes, publication_status FROM programs WHERE id = ?');
    $row->execute([$saved['program_id']]);
    $program = $row->fetch();
    expectSame('After Import', $program['title'], 'source title');
    expectSame('approved', $program['rights_status'], 'rights preserved');
    expectSame('Reviewed by editor', $program['rights_notes'], 'notes preserved');
    expectSame('published', $program['publication_status'], 'publication preserved');
    $lineup = $pdo->prepare('SELECT COUNT(*) FROM channel_programs WHERE program_id = ?');
    $lineup->execute([$saved['program_id']]);
    expectSame(1, (int) $lineup->fetchColumn(), 'channel assignment preserved');
});

$runner->test('persistence rolls back when media insert fails', function () use ($pdo): void {
    $catalog = new CatalogService(
        $pdo,
        new Maxfractal\Catalog\ProgramRepository($pdo),
        new ExplodingMediaFileRepository($pdo),
        new Maxfractal\Catalog\ImportEventRepository($pdo),
    );
    try {
        $catalog->persist(sampleItem('zztestrollback', 'Should Roll Back'));
        throw new RuntimeException('failure was not raised');
    } catch (RuntimeException $error) {
        expectSame('forced media failure', $error->getMessage(), 'error');
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM programs WHERE archive_identifier = ?');
    $count->execute(['zztestrollback']);
    expectSame(0, (int) $count->fetchColumn(), 'program absent');
    $catalog->recordFailure('zztestrollback', 'forced media failure');
    $events = $pdo->prepare("SELECT COUNT(*) FROM import_events WHERE archive_identifier = ? AND outcome = 'failed'");
    $events->execute(['zztestrollback']);
    expectSame(1, (int) $events->fetchColumn(), 'failure recorded');
});

$runner->test('batch import is repeatable and skips manifest duplicates', function () use ($pdo): void {
    $bodies = [
        'zztestone' => json_encode([
            'metadata' => ['identifier' => 'zztestone', 'title' => 'One'],
            'files' => [['name' => 'one.mp4', 'format' => 'h.264', 'length' => '12', 'size' => '20']],
        ], JSON_THROW_ON_ERROR),
        'zztesttwo' => json_encode([
            'metadata' => ['identifier' => 'zztesttwo', 'title' => 'Two'],
            'files' => [['name' => 'two.mp4', 'format' => 'h.264', 'length' => '14', 'size' => '22']],
        ], JSON_THROW_ON_ERROR),
    ];
    $http = new FakeHttpClient(static function (string $method, string $url) use ($bodies): HttpResponse {
        if (preg_match('#/metadata/([^/?]+)#', $url, $matches) !== 1) {
            throw new RuntimeException('unexpected URL ' . $url);
        }
        $identifier = rawurldecode($matches[1]);
        if (isset($bodies[$identifier])) {
            return httpResponse(200, $bodies[$identifier]);
        }
        return httpResponse(200, json_encode([
            'metadata' => ['identifier' => $identifier, 'title' => 'Dry'],
            'files' => [['name' => 'dry.mp4', 'format' => 'h.264', 'length' => '5', 'size' => '10']],
        ], JSON_THROW_ON_ERROR));
    });
    $importer = new ArchiveImporter(
        new ArchiveService($http, testConfig()),
        new MediaInspector($http),
        CatalogService::fromPdo($pdo),
    );
    $manifest = tempnam(sys_get_temp_dir(), 'mf-manifest-');
    if ($manifest === false) {
        throw new RuntimeException('temp manifest failed');
    }
    file_put_contents($manifest, json_encode(['items' => ['zztestone', 'zztestone', 'zztesttwo']], JSON_THROW_ON_ERROR));
    $first = $importer->importManifest($manifest, false, false);
    $second = $importer->importManifest($manifest, false, false);
    expectSame(2, $first['counts']['imported'], 'imported');
    expectSame(1, $first['counts']['skipped'], 'skipped');
    expectSame(0, $first['counts']['failed'], 'failed');
    expectSame(2, $second['counts']['updated'], 'updated');
    expectSame(1, $second['counts']['skipped'], 'skipped again');
    $count = $pdo->query("SELECT COUNT(*) FROM programs WHERE archive_identifier IN ('zztestone', 'zztesttwo')")->fetchColumn();
    expectSame(2, (int) $count, 'rows');
    $dry = $importer->importIdentifier('zztestdry', true, false);
    expectSame('dry_run', $dry['outcome'], 'dry run');
    $dryCount = $pdo->prepare('SELECT COUNT(*) FROM programs WHERE archive_identifier = ?');
    $dryCount->execute(['zztestdry']);
    expectSame(0, (int) $dryCount->fetchColumn(), 'dry run wrote nothing');
    unlink($manifest);
});

$runner->test('importer records an invalid identifier without writing a program', function () use ($pdo): void {
    $importer = new ArchiveImporter(
        service(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, '{}'))),
        new MediaInspector(new FakeHttpClient(static fn (): HttpResponse => httpResponse(200, ''))),
        CatalogService::fromPdo($pdo),
    );
    $result = $importer->importIdentifier('not valid', false, false);
    expectSame('failed', $result['outcome'], 'outcome');
    $count = $pdo->query("SELECT COUNT(*) FROM programs WHERE archive_identifier = 'not valid'")->fetchColumn();
    expectSame(0, (int) $count, 'no row');
});

cleanupTests($pdo);

$runner->test('live Archive metadata and a bounded range request', function (): void {
    $config = testConfig();
    $http = new CurlHttpClient($config->connectTimeout, $config->requestTimeout, $config->userAgent);
    $item = (new ArchiveService($http, $config))->fetchMetadata('education_2');
    expectSame('Computers in Education', $item['title'], 'live title');
    $names = array_column($item['files'], 'filename');
    expect(in_array('education_2.mp4', $names, true), 'live mp4 present');

    $search = (new ArchiveService($http, $config))->search('collection:computerchronicles AND mediatype:movies', 5);
    expect(($search['num_found'] ?? 0) > 100, 'live search found the collection');
    expect(count($search['docs']) > 0, 'live search docs');

    $ranged = $http->request(
        'GET',
        'https://archive.org/download/education_2/education_2.mp4',
        ['Range' => 'bytes=0-15'],
        64,
    );
    expectSame(206, $ranged->status, 'live range status');
    expect(strlen($ranged->body) <= 64, 'live range body stayed bounded');
});

echo "\n{$runner->passed} passed, {$runner->failed} failed\n";
exit($runner->failed > 0 ? 1 : 0);
