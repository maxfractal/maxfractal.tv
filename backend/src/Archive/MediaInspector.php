<?php

declare(strict_types=1);

namespace Maxfractal\Archive;

use Maxfractal\ArchiveException;
use Maxfractal\ArchiveTimeoutException;
use Maxfractal\Http\HttpClient;
use Maxfractal\Http\NullSleeper;
use Maxfractal\Http\Sleeper;

/**
 * One Archive item becomes one program.
 * Every video file is kept. Exactly one may be marked preferred.
 * Other videos are not separate programs and are not discarded.
 * A later sprint can split multi-episode items.
 *
 * validation_status stays pending unless the file is unsupported or unavailable.
 * A successful HEAD or a 206 range response does not mean HTML5 playback works.
 */
final class MediaInspector
{
    public const MULTI_VIDEO_LIMITATION = 'Each Internet Archive item is stored as one program. This sprint selects a single preferred media file and keeps every other video candidate. Separate episodes inside one item are not split into separate programs.';

    public function __construct(
        private readonly HttpClient $http,
        private readonly Sleeper $sleeper = new NullSleeper(),
        private readonly int $probeDelayMs = 0,
    ) {
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed>
     */
    public function inspect(array $item, bool $probe = true): array
    {
        $videos = [];
        foreach ($item['files'] ?? [] as $file) {
            if (!is_array($file)) {
                continue;
            }
            $classified = $this->classify($file);
            if ($classified['role'] !== 'video') {
                continue;
            }
            $classified['validation_status'] = $classified['unsupported'] ? 'unsupported' : 'pending';
            $classified['supports_byte_ranges'] = null;
            $classified['validation_notes'] = $this->baseNotes($classified);
            $classified['is_preferred'] = false;
            $classified['last_checked_at'] = null;
            $videos[] = $classified;
        }

        if ($probe) {
            foreach ($videos as $index => $video) {
                if ($index > 0 && $this->probeDelayMs > 0) {
                    $this->sleeper->sleep($this->probeDelayMs);
                }
                $this->probe($videos[$index]);
            }
        }

        $preferredIndex = $this->choosePreferred($videos);
        if ($preferredIndex !== null) {
            $videos[$preferredIndex]['is_preferred'] = true;
            if ($probe && $videos[$preferredIndex]['validation_status'] !== 'unavailable') {
                if ($this->probeDelayMs > 0) {
                    $this->sleeper->sleep($this->probeDelayMs);
                }
                $this->probeRange($videos[$preferredIndex]);
            }
        }

        $duration = $preferredIndex === null ? null : $videos[$preferredIndex]['duration_seconds'];
        if ($duration === null) {
            $duration = $item['duration_seconds'] ?? null;
        }

        $item['duration_seconds'] = $duration;
        $item['media_files'] = $videos;
        $item['candidate_count'] = count($videos);
        $item['preferred_filename'] = $preferredIndex === null ? null : $videos[$preferredIndex]['filename'];
        $item['multi_video_limitation'] = self::MULTI_VIDEO_LIMITATION;
        return $item;
    }

    /** @param array<string, mixed> $file
     *  @return array<string, mixed>
     */
    public function classify(array $file): array
    {
        $name = (string) ($file['filename'] ?? '');
        $format = isset($file['format']) && is_string($file['format']) ? $file['format'] : null;
        $formatNorm = strtolower((string) $format);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $lowerName = strtolower($name);

        $role = 'other';
        if (
            $lowerName === '__ia_thumb.jpg'
            || str_contains($lowerName, '.thumbs/')
            || str_contains($formatNorm, 'thumb')
            || str_contains($formatNorm, 'item tile')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
        ) {
            $role = 'thumbnail';
        } elseif (
            in_array($extension, ['srt', 'vtt', 'ass', 'sub', 'sbv'], true)
            || str_contains($formatNorm, 'subrip')
            || str_contains($formatNorm, 'subtitle')
            || str_contains($formatNorm, 'text track')
            || str_contains($formatNorm, 'caption')
        ) {
            $role = 'subtitle';
        } elseif (
            in_array($extension, ['xml', 'json', 'sqlite', 'torrent', 'txt', 'pdf', 'zip', 'gz', 'csv'], true)
            || str_contains($formatNorm, 'metadata')
            || str_contains($formatNorm, 'bittorrent')
            || str_ends_with($lowerName, '_files.xml')
            || str_ends_with($lowerName, '_meta.xml')
        ) {
            $role = 'metadata';
        } elseif ($this->isVideo($extension, $formatNorm)) {
            $role = 'video';
        }

        $unsupported = $role === 'video' && $this->isUnsupported($extension, $formatNorm);
        $file['role'] = $role;
        $file['unsupported'] = $unsupported;
        $file['playback_rank'] = $unsupported ? 0 : $this->rank($extension, $formatNorm, $lowerName, $file['duration_seconds'] ?? null);
        if ($role !== 'video') {
            $file['playback_rank'] = 0;
        }
        if (!isset($file['mime_type']) || !is_string($file['mime_type']) || $file['mime_type'] === '') {
            $file['mime_type'] = $this->mime($extension);
        }
        return $file;
    }

    /** @param list<array<string, mixed>> $videos */
    private function choosePreferred(array $videos): ?int
    {
        $bestIndex = null;
        $bestRank = 0;
        foreach ($videos as $index => $video) {
            if (($video['validation_status'] ?? '') === 'unavailable' || ($video['unsupported'] ?? false) === true) {
                continue;
            }
            $rank = (int) ($video['playback_rank'] ?? 0);
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $bestIndex = $index;
            }
        }
        return $bestIndex;
    }

    /** @param array<string, mixed> $video */
    private function probe(array &$video): void
    {
        $video['last_checked_at'] = date('Y-m-d H:i:s');
        try {
            $response = $this->http->request('HEAD', (string) $video['media_url']);
        } catch (ArchiveTimeoutException) {
            $video['validation_status'] = 'pending';
            $video['validation_notes'] .= ' Availability probe timed out. Byte-range support is unknown.';
            return;
        } catch (ArchiveException $error) {
            $video['validation_status'] = 'pending';
            $video['validation_notes'] .= ' Availability probe failed: ' . $error->getMessage();
            return;
        }

        $mime = $this->videoMime($response->header('content-type'));
        if ($mime !== null) {
            $video['mime_type'] = $mime;
        }

        if (in_array($response->status, [200, 204], true)) {
            $accept = strtolower((string) $response->header('accept-ranges'));
            if (str_contains($accept, 'bytes')) {
                $video['supports_byte_ranges'] = true;
                $video['validation_notes'] .= ' HEAD returned ' . $response->status . ' and Accept-Ranges includes bytes. Seeking was not confirmed by playback.';
            } else {
                $video['validation_notes'] .= ' HEAD returned ' . $response->status . ' without Accept-Ranges: bytes. Seeking support is unknown.';
            }
            if ($video['validation_status'] !== 'unsupported') {
                $video['validation_status'] = 'pending';
            }
            return;
        }

        if (in_array($response->status, [403, 404, 410], true)) {
            $video['validation_status'] = 'unavailable';
            $video['validation_notes'] .= ' HEAD returned ' . $response->status . '.';
            return;
        }

        $video['validation_status'] = $video['validation_status'] === 'unsupported' ? 'unsupported' : 'pending';
        $video['validation_notes'] .= ' HEAD returned ' . $response->status . '. Availability is uncertain.';
    }

    /** @param array<string, mixed> $video */
    private function probeRange(array &$video): void
    {
        try {
            $response = $this->http->request('GET', (string) $video['media_url'], ['Range' => 'bytes=0-15'], 64);
        } catch (ArchiveTimeoutException) {
            $video['validation_notes'] .= ' Range request timed out. Seeking remains unconfirmed.';
            return;
        } catch (ArchiveException) {
            $video['validation_notes'] .= ' Range request failed. Seeking remains unconfirmed.';
            return;
        }

        $mime = $this->videoMime($response->header('content-type'));
        if ($mime !== null) {
            $video['mime_type'] = $mime;
        }

        if ($response->status === 206) {
            $video['supports_byte_ranges'] = true;
            $video['validation_notes'] .= ' A 16-byte range request returned 206. HTML5 playback was not tested.';
            return;
        }
        if ($response->status === 200) {
            $video['supports_byte_ranges'] = false;
            $video['validation_notes'] .= ' A range request returned 200, so byte-range seeking was not honored. HTML5 playback was not tested.';
            return;
        }
        $video['validation_notes'] .= ' Range request returned ' . $response->status . '. Seeking remains unconfirmed.';
    }

    /** @param array<string, mixed> $file */
    private function baseNotes(array $file): string
    {
        $format = is_string($file['format'] ?? null) && $file['format'] !== '' ? $file['format'] : 'unlabeled';
        $extension = strtolower(pathinfo((string) $file['filename'], PATHINFO_EXTENSION));
        $formatNorm = strtolower($format);
        if (($file['unsupported'] ?? false) === true) {
            $note = 'Format ' . $format . ' is not a browser-native HTML5 candidate. HTML5 playback was not tested.';
        } elseif ($this->isH264($formatNorm) && in_array($extension, ['mp4', 'm4v'], true)) {
            $note = 'Archive format label is ' . $format . ' in an MP4 container. The codec was not independently verified. HTML5 playback was not tested.';
        } elseif (in_array($extension, ['mp4', 'm4v'], true)) {
            $note = 'MP4 container with Archive format label ' . $format . '. A browser-compatible codec was not verified. HTML5 playback was not tested.';
        } else {
            $note = 'Archive format label is ' . $format . '. Browser playback is uncertain. HTML5 playback was not tested.';
        }
        if (($file['duration_seconds'] ?? null) === null) {
            $note .= ' File duration was not present in Archive metadata.';
        }
        return $note;
    }

    private function isVideo(string $extension, string $formatNorm): bool
    {
        if (in_array($extension, ['mp4', 'm4v', 'webm', 'ogv', 'mpeg', 'mpg', 'mov', 'mkv', 'avi', 'wmv', 'flv', 'm2v', 'ts', 'm2ts'], true)) {
            return true;
        }
        if ($extension === 'ogg' && str_contains($formatNorm, 'video')) {
            return true;
        }
        return preg_match('/h\.?264|mpeg-?4|mpeg-?2|ogg video|matroska|quicktime|webm|flash video|windows media/', $formatNorm) === 1
            && !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'srt', 'vtt', 'xml', 'json', 'txt'], true);
    }

    private function isUnsupported(string $extension, string $formatNorm): bool
    {
        return in_array($extension, ['wmv', 'flv', 'swf', 'asf', 'rm', 'ram'], true)
            || str_contains($formatNorm, 'windows media')
            || str_contains($formatNorm, 'flash video');
    }

    private function isH264(string $formatNorm): bool
    {
        return str_contains($formatNorm, 'h.264')
            || str_contains($formatNorm, 'h264')
            || str_contains($formatNorm, 'avc');
    }

    private function rank(string $extension, string $formatNorm, string $filename, mixed $duration): int
    {
        $score = 1;
        if (in_array($extension, ['mp4', 'm4v'], true)) {
            $score += 50;
        }
        if ($this->isH264($formatNorm)) {
            $score += 40;
        }
        if (str_contains($formatNorm, 'mpeg4') || str_contains($formatNorm, 'mpeg-4')) {
            $score += 10;
        }
        if (preg_match('/(?:^|[_\-.])(?:64|256|512)kb(?:[_\-.]|$)/', $filename) === 1) {
            $score -= 20;
        }
        if (str_contains($filename, '_edit')) {
            $score -= 5;
        }
        if ($extension === 'webm') {
            $score += 30;
        }
        if ($extension === 'ogv' || $extension === 'ogg') {
            $score += 20;
        }
        if (in_array($extension, ['mpeg', 'mpg', 'm2v'], true)) {
            $score += 5;
        }
        if ($extension === 'mov' || $extension === 'mkv') {
            $score += 8;
        }
        if ($duration !== null) {
            $score += 1;
        }
        return $score;
    }

    private function mime(string $extension): ?string
    {
        return match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            'mpeg', 'mpg', 'm2v' => 'video/mpeg',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'wmv', 'asf' => 'video/x-ms-wmv',
            'flv' => 'video/x-flv',
            default => null,
        };
    }

    private function videoMime(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        $mime = strtolower(trim(explode(';', $header)[0]));
        return str_starts_with($mime, 'video/') ? $mime : null;
    }
}
