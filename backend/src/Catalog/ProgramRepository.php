<?php

declare(strict_types=1);

namespace Maxfractal\Catalog;

use PDO;

final class ProgramRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByArchiveIdentifier(string $identifier): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, archive_identifier, title, description, duration_seconds, thumbnail_url,
                    source_url, license_label, license_url, rights_status, rights_notes,
                    publication_status, last_imported_at
             FROM programs
             WHERE archive_identifier = ?
             LIMIT 1'
        );
        $statement->execute([$identifier]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $program */
    public function insert(array $program): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO programs (
                archive_identifier, title, description, duration_seconds, thumbnail_url,
                source_url, license_label, license_url, rights_status, rights_notes,
                publication_status, last_imported_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $statement->execute([
            $program['identifier'],
            $program['title'],
            $program['description'],
            $program['duration_seconds'],
            $program['thumbnail_url'],
            $program['source_url'],
            $program['license_label'],
            $program['license_url'],
            'pending',
            $program['rights_notes'],
            'draft',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Refresh source-derived fields. Null incoming values do not erase stored values.
     * Rights and publication columns are intentionally absent.
     *
     * @param array<string, mixed> $program
     */
    public function updateSourceMetadata(int $id, array $program): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE programs
             SET title = COALESCE(?, title),
                 description = COALESCE(?, description),
                 duration_seconds = COALESCE(?, duration_seconds),
                 thumbnail_url = COALESCE(?, thumbnail_url),
                 source_url = COALESCE(?, source_url),
                 license_label = COALESCE(?, license_label),
                 license_url = COALESCE(?, license_url),
                 last_imported_at = NOW()
             WHERE id = ?'
        );
        $statement->execute([
            $program['title'],
            $program['description'],
            $program['duration_seconds'],
            $program['thumbnail_url'],
            $program['source_url'],
            $program['license_label'],
            $program['license_url'],
            $id,
        ]);
    }

    public function flagUntouchedRights(int $id, string $notes): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE programs
             SET rights_status = 'pending', rights_notes = ?
             WHERE id = ? AND rights_status = 'unreviewed' AND rights_notes IS NULL"
        );
        $statement->execute([$notes, $id]);
    }
}
