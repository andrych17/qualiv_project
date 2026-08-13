# NusaEvo ERP

NusaEvo ERP is a modular monolith enterprise business application built using **Laravel**, **Vue 3**, **Inertia.js**, and **TypeScript**.

---

## Tech Stack & Requirements
- **Backend**: Laravel (PHP 8.3) via Docker Compose
- **Frontend (Web)**: Vue 3 + Inertia.js (Vite, Tailwind CSS, Lucide Icons) — npm on host
- **Database / cache**: PostgreSQL 16 + Redis 7 — provided by the external `shared-infra` Compose stack (not this repo's `docker-compose.yml`); start it first
- **Prerequisites**: Docker Compose, Node.js & npm

Web UI uses **Inertia** (not REST). Business logic stays in Service classes so a future mobile REST API can reuse the same services.

---

## Local Setup

### 1. Environment
```bash
cp .env.example .env
```
Compose overrides DB/Redis connection settings for the `app` and `queue` containers. Keep `APP_KEY` in `.env` (generated in step 3).

### 2. Build & install
```bash
docker compose build
docker compose run --rm app composer install
npm install
```

### 3. Start stack, key, migrate
```bash
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
npm run dev
```

Open **[http://localhost:8000](http://localhost:8000)**.

Everyday after that: `docker compose up -d` + `npm run dev`.

Common artisan: `docker compose exec app php artisan <command>` (migrate, test, pint, tinker, etc.).

---

## Login Credentials
- **Email**: `admin@nusaevo.com`
- **Password**: `password`

---

## Modular Architecture

- **Modules**: `app/Modules/` (e.g. Inventory, CRM, Sales).
- **Shared Code**: `app/Shared/`.
- **Frontend Pages**: `resources/js/Pages/<Module>/...`.
- **Reusable UI**: `resources/js/Components/`.

See **[CLAUDE.md](CLAUDE.md)** for agent rules. Architecture detail: **[ARCHITECTURE.md](ARCHITECTURE.md)**. Design: **[resources/DESIGN.md](resources/DESIGN.md)**.
