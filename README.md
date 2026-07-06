# NusaEvo ERP

NusaEvo ERP is a modular monolith enterprise business application built using **Laravel**, **Vue 3**, **Inertia.js**, and **TypeScript**.

---

## 🚀 Tech Stack & Requirements
- **Backend**: Laravel 11/12
- **Frontend**: Vue 3 (Inertia.js, Vite, Tailwind CSS, Lucide Icons)
- **Database**: SQLite (default, zero-configuration)
- **Prerequisites**: Node.js & NPM (installed on host) + Docker (installed on host to run PHP/Composer)

---

## 🛠️ Local Setup Guide

Follow these steps to set up and run the project locally on your machine:

### 1. Clone & Configure Environment
First, create your local `.env` configuration:
```bash
cp .env.example .env
```

### 2. Install Dependencies
Install PHP dependencies via Docker (since PHP is run through a container) and Node packages locally:
```bash
# Install PHP dependencies
docker run --rm -v $(pwd):/app -w /app composer:latest composer install

# Install JS/TS dependencies
npm install
```

### 3. Initialize Database & Seed Dummy Data
Create a fresh SQLite database, run migrations, and seed initial mock records (including 58 inventory items):
```bash
docker run --rm -v $(pwd):/app -w /app composer:latest php artisan migrate:fresh --seed
```

### 4. Start Development Servers
You need to run two servers concurrently:

- **Vite Asset Server (Frontend)**:
  ```bash
  npm run dev
  ```
- **Laravel Local Web Server (Backend via Docker)**:
  ```bash
  docker run --name nusaevo-web --rm -p 8000:8000 -v $(pwd):/app -w /app composer:latest php artisan serve --host=0.0.0.0
  ```

Once both are running, open your web browser and navigate to:
👉 **[http://localhost:8000](http://localhost:8000)** (which will automatically redirect you to the Login page).

---

## 🔑 Login Credentials
Use the default administrator account to sign in:
- **Email**: `admin@nusaevo.com`
- **Password**: `password`

---

## 📁 Modular Architecture Structure

This ERP uses a **Modular Monolith** pattern.
- **Modules**: Located in `app/Modules/` (e.g., `Inventory/`, `CRM/`, `Sales/`, etc.).
- **Shared Code**: Shared services, DTOs, enums, traits, or helpers are located in `app/Shared/`.
- **Frontend Pages**: Vue pages are organized modularly under `resources/js/Pages/` (e.g., `Inventory/Items/Index.vue`).
- **Reusable UI Components**: General UI inputs, tables, dropdowns, and feedback panels are in `resources/js/Components/`.

Refer to the **[CLAUDE.md](CLAUDE.md)** file for a list of everyday coding conventions and complete build/run command references.
