<?php

declare(strict_types=1);

namespace Maxfractal\Catalog;

use PDO;

final class ImportEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $identifier, string $outcome, string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO import_events (archive_identifier, outcome, message) VALUES (?, ?, ?)'
        );
        $statement->execute([
            substr($identifier, 0, 255),
            substr($outcome, 0, 30),
            $message,
        ]);
    }
}
