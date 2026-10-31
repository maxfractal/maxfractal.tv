# maxfractal.tv local scaffold

- `frontend/` is the Next.js app; run it on port 3000 with `npm run dev`.
- `backend/` is the PHP API served by XAMPP Apache at `/api/*`.
- Apache's project `.htaccess` denies every project URL except API routes.
- Copy `backend/.env.example` to `backend/.env` for the local MariaDB settings.
  The real `.env` is ignored by Git and must stay outside `backend/public/`.
- The target database name is `maxfractal_tv`.
