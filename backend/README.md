# PHP API

Apache routes `/api/*` to `public/index.php`. Local database settings belong in
`.env` in this directory; copy `.env.example` to `.env`. The file is ignored by Git.

Archive ingestion lives in `src/` and runs from the repository root:

```bash
php backend/scripts/import_archive.php --manifest=backend/data/computer-chronicles.json
```

The root README describes the pipeline, review rules, and verification queries.
