# maxfractal.tv

Local television catalog. The Next.js app runs on port 3000. XAMPP Apache serves the PHP API at `/api/*` and denies every other project URL. Copy `backend/.env.example` to `backend/.env` for MariaDB. The real `.env` is ignored by Git and stays outside `backend/public/`.

```bash
cd frontend
npm install
npm run dev
```

API checks, with Apache and MariaDB running: `/api/health` and `/api/db-health`.

## Ingestion architecture

Internet Archive items enter through a CLI importer. The HTTP API is unchanged.

1. `ArchiveService` loads `https://archive.org/metadata/{identifier}` and can query `https://archive.org/advancedsearch.php`. Identifiers are validated, requests stay on `archive.org` and `*.archive.org`, and missing fields stay null.
2. `MediaInspector` classifies files, keeps every video candidate, and selects one preferred file. Thumbnails, subtitles, and metadata files are not stored as media. Probes are HEAD requests plus one 16-byte range request for the preferred file.
3. `CatalogService` writes `programs` and `media_files` in one transaction. `archive_identifier` is the unique external key. A second import updates that row instead of inserting another.

One Archive item is one program, even when the item contains several video files. Those files are kept as non-preferred `media_files` rows. They are not split into episodes.

Technical readiness, rights, and publication are separate:

| Question | Column | Value written by the importer |
| --- | --- | --- |
| Was a plausible video selected? | `media_files.validation_status` | `pending`, `unsupported`, or `unavailable` |
| May it be broadcast? | `programs.rights_status` | `pending` on first import |
| Is it public on a channel? | `programs.publication_status` | `draft` on first insert |

`pending` means a person still needs to review it. A successful HEAD or HTTP 206 does not mean the browser can play the file. This sprint does not set a playback-verified status and does not schedule anything.

## Environment

`backend/.env` supplies the database. Archive settings have defaults and can be overridden:

```
ARCHIVE_METADATA_BASE=https://archive.org/metadata
ARCHIVE_SEARCH_URL=https://archive.org/advancedsearch.php
ARCHIVE_DOWNLOAD_BASE=https://archive.org/download
ARCHIVE_DETAILS_BASE=https://archive.org/details
ARCHIVE_CONNECT_TIMEOUT=5
ARCHIVE_REQUEST_TIMEOUT=20
ARCHIVE_MAX_RETRIES=3
ARCHIVE_USER_AGENT=maxfractal.tv-ingest/0.1 (local catalog import)
```

Apply the additive migration once. It adds license and probe columns and an `import_events` table. It does not drop data.

```bash
/Applications/XAMPP/xamppfiles/bin/mysql -h 127.0.0.1 -u root maxfractal_tv < backend/database/migrations/2026_11_02_archive_ingestion.sql
```

`backend/database/schema.sql` is not in this repository. The live `maxfractal_tv` database is the source of the original five tables.

## CLI

From the repository root:

```bash
php backend/scripts/import_archive.php --identifier=education_2
php backend/scripts/import_archive.php --identifier=education_2 --dry-run
php backend/scripts/import_archive.php --manifest=backend/data/computer-chronicles.json
```

`--dry-run` still reads the Archive and probes media. It does not write to MySQL.

Exit `0` when every item is imported, updated, skipped, or dry-run. Exit `1` for a bad invocation or an unreadable manifest. Exit `2` when one or more items fail. A batch continues after an item failure, and that item's transaction is rolled back.

The manifest `backend/data/computer-chronicles.json` lists six Computer Chronicles items found with:

```
collection:computerchronicles AND mediatype:movies
```

The identifiers were checked against Archive metadata on 2026-11-02.

## Database checks

```sql
SELECT archive_identifier, title, duration_seconds, rights_status, publication_status
FROM programs
ORDER BY id;

SELECT p.archive_identifier, m.filename, m.format, m.duration_seconds,
       m.is_preferred, m.validation_status, m.supports_byte_ranges
FROM media_files m
JOIN programs p ON p.id = m.program_id
ORDER BY p.id, m.is_preferred DESC, m.filename;

SELECT outcome, archive_identifier, message, created_at
FROM import_events
ORDER BY id DESC;

SELECT c.channel_number, c.name, COUNT(cp.program_id) AS lineup
FROM channels c
LEFT JOIN channel_programs cp ON cp.channel_id = c.id AND cp.is_active = 1
GROUP BY c.id;
```

A repeat import should keep the same `programs.id` for an identifier. `COUNT(*)` grouped by `archive_identifier` should stay at 1.

## Media limits

- Preferred file order is an MP4 whose Archive format label contains h.264, then other MP4 labels, then WebM, Ogg, and MPEG. Low-bitrate derivatives rank lower. WMV and FLV are stored as `unsupported` and are not preferred.
- The Archive format label is not a codec verification. `validation_status` remains `pending` for a plausible file.
- Byte ranges are recorded from `Accept-Ranges` and a 16-byte `Range` request. Seeking in a player was not tested.
- If duration is absent from both the preferred file and item `runtime`, `duration_seconds` stays null.
- Files removed from an Archive item on a later import are left in `media_files`. They are not deleted automatically.
- Multi-episode items need a later import rule. Do not treat every extra file as its own program until that exists.

## Rights review

The importer never sets `rights_status` to `approved` and never sets `publication_status` to `published`. On the first insert, rights are `pending` and the program is a `draft`. `rights_notes` quote license, rights, collection, creator, and copyright text when those fields exist. If they do not, the note says they were absent.

A later import refreshes title, description, duration, thumbnail, source URL, and license columns when the new value is non-null. It does not replace `rights_status` or `rights_notes` once those columns have been set. Approved, rejected, and edited notes therefore survive a re-import. It also does not change `publication_status` or rows in `channel_programs`.

Channel 01, Computer Chronicles, stays empty until a reviewer explicitly assigns a program. An assignment is appropriate only after rights are approved and publication is no longer `draft`. None of the imported Computer Chronicles items meet that bar: the inspected metadata has no `licenseurl`, and several descriptions name Stewart Cheifet Productions as the copyright holder.

To record a review, update the row directly. Then, only if it should air, assign it:

```sql
UPDATE programs
SET rights_status = 'approved',
    rights_notes = 'Reviewed: license and broadcast decision recorded here.',
    publication_status = 'published'
WHERE archive_identifier = 'ARCHIVE_IDENTIFIER';

INSERT INTO channel_programs (channel_id, program_id, sort_order, is_active)
SELECT c.id, p.id, 1, 1
FROM channels c
JOIN programs p ON p.archive_identifier = 'ARCHIVE_IDENTIFIER'
WHERE c.channel_number = 1;
```

## Troubleshooting

- `Could not read package.json` from `npm run dev`: run npm inside `frontend/`.
- `Invalid Internet Archive identifier`: use the Archive identifier, not a title or a `/details/` URL.
- Metadata HTTP errors, timeouts, and malformed JSON fail that item. Existing rows are left as they were, and `import_events.outcome` is `failed` when the identifier itself is valid.
- HTTP 429 and 502/503/504 are retried, honoring `Retry-After` up to 15 seconds.
- A probe timeout leaves the file `pending`. A 403, 404, or 410 marks it `unavailable`.
- If the migration was not applied, inserts fail on `license_label` or `import_events`. Run the SQL file above.
- PHP syntax: `php -l backend/scripts/import_archive.php`. Tests: `php backend/tests/run.php`.

## What the scheduler will read

A later scheduling engine can build `schedule_entries` from Channel 01 only where all of these are true:

- `channel_programs.is_active = 1`
- `programs.publication_status = 'published'`
- `programs.rights_status = 'approved'`
- the chosen `media_files` row belongs to that program
- `media_files.duration_seconds` is a positive duration

`schedule_entries` stores `channel_id`, `program_id`, `media_file_id`, `start_time`, and `end_time`, and `end_time` must be after `start_time`. `validation_status = 'pending'` is not enough for that engine. Playback still has to be confirmed, and this importer does not do that confirmation.
