# CLAUDE.md - NusaEvo ERP

## Build & Run Commands

As the local host does not have PHP/Composer installed globally, all PHP and artisan commands must be run via Docker using `composer:latest`. Local Node.js / NPM commands can be run directly on the host.

### Local Development Setup
- **Install PHP dependencies**: `docker run --rm -v $(pwd):/app -w /app composer:latest composer install`
- **Install Node dependencies**: `npm install`
- **Run Vite dev server**: `npm run dev`
- **Run Laravel dev server**: `docker run --name nusaevo-web --rm -p 8000:8000 -v $(pwd):/app -w /app composer:latest php artisan serve --host=0.0.0.0`
- **Run DB migrations**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan migrate`
- **Run DB seeders**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan db:seed`
- **Fresh migration & seed**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan migrate:fresh --seed`

### Build Production Assets
- **Vite production build**: `npm run build`

### Code Quality & Formatting
- **PHP Linting (Laravel Pint)**: `docker run --rm -v $(pwd):/app -w /app composer:latest ./vendor/bin/pint`
- **TypeScript Checking**: `npm run build` (runs `vue-tsc`)

### Running Tests
- **Run PHPUnit tests**: `docker run --rm -v $(pwd):/app -w /app composer:latest php artisan test`

---

## Codebase Guidelines & Conventions

### 1. Modular Monolith Architecture
- Business modules live in `app/Modules/<ModuleName>/`.
- Each module contains:
  - `Controllers/` (Thin controllers only)
  - `Models/` (Eloquent models with query scopes)
  - `Requests/` (Store/Update FormRequest validation classes)
  - `Services/` (All business logic and DB transactions)
  - `Data/` (DTOs / Data objects)
  - `Enums/` (Status values and constants)
  - `Routes/` (Routings named `web.php`)
- Shared/core utilities live in `app/Shared/` (`Actions/`, `DTOs/`, `Enums/`, `Services/`, `Traits/`, `Helpers/`).
- Module routes are loaded dynamically from `routes/web.php`.

### 2. Frontend Page Structure
- Vue pages live in `resources/js/Pages/<ModuleName>/Items/` (e.g. `Index.vue`, `Create.vue`, `Edit.vue`).
- Shared frontend layouts, navigation, forms, and table components live in `resources/js/Components/` (`layout/`, `navigation/`, `forms/`, `tables/`, `filters/`, `modals/`, `feedback/`).

### 3. Coding Conventions
- **Controllers**: Keep controllers thin. Validate requests using Form Requests, delegate execution to Service classes, and return Inertia responses.
- **TypeScript**: Use strict TypeScript in Vue files. Explicitly define types and interfaces for backend-passed props.
- **Tailwind CSS**: Use utility classes directly for layouts and UI styling. Maintain clean structure and consistent spacing.
- **Lucide Icons**: Render Lucide icons dynamically in layouts and sidebars using the `<component :is="..." />` helper.
