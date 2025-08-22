# Life Dashboards (PHP)

A simplified PHP port of Life Dashboards for easy local run and deployment.

## Features
- Journals with two sections per day: "What I Learnt Today" and "Mistakes I Made Today" (no overwrite)
- File uploads with inline viewing (images, PDFs). No forced downloads
- Avatar upload (local filesystem)
- Combined activity heatmap (Journals + Todos + Habits)
- SQLite database (single file), no external services

## Quick Start
1. Install PHP 8.1+ (with SQLite extension)
2. Run the built-in server:
   ```bash
   php -S 0.0.0.0:8000 -t public
   ```
3. Open `http://localhost:8000`

Data is stored in `data/app.sqlite`. Uploads are in `uploads/`.

## Deploy
- Any PHP hosting that supports SQLite and file uploads will work.
- Point document root to `public/`.

## Notes
- This is intentionally minimal. Add auth if needed.
- To seed habits/todos for heatmap, insert rows into `habit_logs` and `todos`.
