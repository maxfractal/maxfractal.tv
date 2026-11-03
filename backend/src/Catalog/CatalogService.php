<?php

declare(strict_types=1);

namespace Maxfractal\Catalog;

use PDO;
use RuntimeException;

/**
 * Persists one Archive item as one draft program.
 * Re-imports update source metadata and media rows.
 * They do not publish a program, do not approve rights, and do not change channel assignments.
 */
final class CatalogService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProgramRepository $programs,
        private readonly MediaFileRepository $mediaFiles,
        private readonly ImportEventRepository $events,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new ProgramRepository($pdo),
            new MediaFileRepository($pdo),
            new ImportEventRepository($pdo),
        );
    }

    /** @param array<string, mixed> $item
     *  @return array<string, mixed>
     */
    public function persist(array $item): array
    {
        $identifier = (string) ($item['identifier'] ?? '');
        $title = $item['title'] ?? null;
        if (!is_string($title) || trim($title) === '') {
            throw new RuntimeException('Archive item has no title');
        }
        if (strlen($title) > 255) {
            throw new RuntimeException('Archive title exceeds the programs.title column');
        }

        $this->pdo->beginTransaction();
        try {
            $existing = $this->programs->findByArchiveIdentifier($identifier);
            if ($existing === null) {
                $programId = $this->programs->insert($item);
                $outcome = 'imported';
            } else {
                $programId = (int) $existing['id'];
                $this->programs->updateSourceMetadata($programId, $item);
                $this->programs->flagUntouchedRights($programId, (string) ($item['rights_notes'] ?? ''));
                $outcome = 'updated';
            }
            $this->mediaFiles->upsertAll($programId, $item['media_files'] ?? []);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        $stored = $this->programs->findByArchiveIdentifier($identifier);
        $message = $outcome . ' ' . $identifier;
        if (!empty($item['preferred_filename'])) {
            $message .= '; preferred ' . $item['preferred_filename'];
        }
        $this->events->record($identifier, $outcome, $message);

        return [
            'outcome' => $outcome,
            'program_id' => $programId,
            'identifier' => $identifier,
            'title' => $stored['title'] ?? $title,
            'preferred_filename' => $item['preferred_filename'] ?? null,
            'candidate_count' => (int) ($item['candidate_count'] ?? count($item['media_files'] ?? [])),
            'duration_seconds' => $stored['duration_seconds'] !== null ? (int) $stored['duration_seconds'] : null,
            'rights_status' => $stored['rights_status'] ?? null,
            'publication_status' => $stored['publication_status'] ?? null,
            'validation_status' => $this->preferredValidation($item['media_files'] ?? []),
        ];
    }

    public function recordFailure(string $identifier, string $message): void
    {
        $this->events->record($identifier, 'failed', $message);
    }

    /** @return array<string, int|string|null> */
    public function summary(int $channelNumber = 1): array
    {
        $programs = $this->pdo->query(
            "SELECT
                COUNT(*) AS total,
                COALESCE(SUM(publication_status = 'draft'), 0) AS drafts,
                COALESCE(SUM(publication_status = 'published'), 0) AS published,
                COALESCE(SUM(rights_status = 'pending'), 0) AS rights_pending,
                COALESCE(SUM(rights_status = 'unreviewed'), 0) AS rights_unreviewed,
                COALESCE(SUM(rights_status = 'approved'), 0) AS rights_approved
             FROM programs"
        )->fetch();

        $media = $this->pdo->query(
            "SELECT COALESCE(SUM(is_preferred = 1 AND validation_status = 'pending'), 0) AS preferred_pending
             FROM media_files"
        )->fetch();

        $lineup = $this->pdo->prepare(
            'SELECT c.name, COUNT(cp.program_id) AS lineup_count
             FROM channels c
             LEFT JOIN channel_programs cp ON cp.channel_id = c.id AND cp.is_active = 1
             WHERE c.channel_number = ?
             GROUP BY c.id, c.name'
        );
        $lineup->execute([$channelNumber]);
        $channel = $lineup->fetch();

        return [
            'programs' => (int) ($programs['total'] ?? 0),
            'drafts' => (int) ($programs['drafts'] ?? 0),
            'published' => (int) ($programs['published'] ?? 0),
            'rights_pending' => (int) ($programs['rights_pending'] ?? 0),
            'rights_unreviewed' => (int) ($programs['rights_unreviewed'] ?? 0),
            'rights_approved' => (int) ($programs['rights_approved'] ?? 0),
            'preferred_media_pending' => (int) ($media['preferred_pending'] ?? 0),
            'channel_number' => $channelNumber,
            'channel_name' => $channel['name'] ?? null,
            'lineup_count' => (int) ($channel['lineup_count'] ?? 0),
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
}
