<?php

declare(strict_types=1);

namespace Maxfractal\Catalog;

use PDO;

class MediaFileRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listByProgram(int $programId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, program_id, filename, media_url, format, mime_type, size_bytes,
                    duration_seconds, is_preferred, validation_status, supports_byte_ranges,
                    validation_notes, last_checked_at
             FROM media_files
             WHERE program_id = ?
             ORDER BY is_preferred DESC, filename ASC'
        );
        $statement->execute([$programId]);
        return $statement->fetchAll();
    }

    /** @param list<array<string, mixed>> $files */
    public function upsertAll(int $programId, array $files): void
    {
        $clear = $this->pdo->prepare('UPDATE media_files SET is_preferred = 0 WHERE program_id = ?');
        $clear->execute([$programId]);

        $statement = $this->pdo->prepare(
            'INSERT INTO media_files (
                program_id, filename, media_url, format, mime_type, size_bytes,
                duration_seconds, is_preferred, validation_status, supports_byte_ranges,
                validation_notes, last_checked_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                media_url = VALUES(media_url),
                format = VALUES(format),
                mime_type = VALUES(mime_type),
                size_bytes = VALUES(size_bytes),
                duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds),
                is_preferred = VALUES(is_preferred),
                validation_status = VALUES(validation_status),
                supports_byte_ranges = VALUES(supports_byte_ranges),
                validation_notes = VALUES(validation_notes),
                last_checked_at = VALUES(last_checked_at)'
        );

        foreach ($files as $file) {
            $ranges = $file['supports_byte_ranges'];
            $statement->execute([
                $programId,
                $file['filename'],
                $file['media_url'],
                $file['format'],
                $file['mime_type'],
                $file['size_bytes'],
                $file['duration_seconds'],
                !empty($file['is_preferred']) ? 1 : 0,
                $file['validation_status'],
                $ranges === null ? null : ($ranges ? 1 : 0),
                $file['validation_notes'],
                $file['last_checked_at'],
            ]);
        }
    }
}
