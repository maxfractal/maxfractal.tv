# Repository Guidelines

## Project Structure

- `frontend/` is the Next.js App Router application. Screens and layout live in `frontend/src/app/`; shared global styles are in `globals.css`.
- `backend/public/index.php` is the PHP API entry point. Keep only request-facing API code under `backend/public/`; configuration belongs in `backend/.env`.
- The frontend calls the Apache-hosted API at `/api/*`; Next.js runs on port 3000. No test or dedicated asset directories are configured yet.

## Development Commands

Run frontend commands from `frontend/`:

- `npm install` installs dependencies from `package.json`.
- `npm run dev` starts the development server on port 3000.
- `npm run build` creates the production build.
- `npm start` serves the production build on port 3000 after building.

For PHP syntax validation, run `php -l backend/public/index.php` from the repository root. With XAMPP Apache and MariaDB running, the API health routes are `/api/health` and `/api/db-health`.

## Style and Naming

Use two spaces for TypeScript, TSX, JSON, and CSS. Name React components in PascalCase, functions and variables in camelCase, and route directories in lowercase. Keep endpoints focused, return JSON with suitable HTTP status codes, and avoid adding product behavior beyond the task.

## Testing and Verification

No automated test framework is configured. Run `npm run build` for frontend changes and `php -l` for changed PHP files. For API or Apache routing changes, request the health endpoint and check that source and configuration paths are not publicly accessible. Report only checks actually completed.

## Commits and Pull Requests

The repository has no commit history yet, so no existing message convention can be confirmed. Use concise Conventional Commit subjects, such as `feat(api): add health route`. Pull requests should describe the change, note verification performed, link relevant issues when applicable, and include screenshots for visible frontend changes.

## Configuration and Security

Keep real local settings in `backend/.env` and `frontend/.env.local`; use the example files as templates. Never put credentials in `backend/public/` or frontend source, and never commit local secrets. Apache should expose API routes only.
