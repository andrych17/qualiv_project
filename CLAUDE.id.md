# CLAUDE.md

Panduan untuk Claude Code (dan model Claude mana pun) yang bekerja di repository ini.

## 1. Gambaran Umum Proyek

Sebuah **platform ERP SaaS**, diarsitekturi sebagai **modular monolith** (ala Odoo), dengan **edisi vertikal/niche** yang disewakan ke industri spesifik. Setiap vertical adalah bundel modul core + modul spesifik-industri di atas platform yang sama.

- **Vertical pertama yang di-ship dan disewakan ke klien: Legal.**
- Vertical masa depan yang direncanakan: Property Management, dan lainnya yang akan ditentukan berdasarkan validasi pasar.
- Dibangun dan dipelihara oleh **satu developer**, sehingga pilihan arsitektur harus dioptimalkan untuk: overhead operasional rendah, batas modul yang jelas (agar codebase bisa dinavigasi sendirian), dan kemampuan menjual/menyewakan vertical secara independen tanpa menduplikasi logika core.

### Konteks bisnis yang harus diingat Claude
- Ini adalah produk SaaS multi-tenant yang disewakan, bukan tool internal. Fitur harus dievaluasi bukan hanya dari kebenarannya tapi juga **kelayakan jualnya** (apakah fitur ini membuat produk lebih mudah dijual, didemokan, atau membenarkan tier langganan?).
- Vertical adalah strategi packaging/monetisasi: **modul core dibagi bersama**, **modul vertical adalah upsell-nya**. Hindari membocorkan logika spesifik-vertical ke modul core.
- Utamakan membangun hal-hal yang membuat vertical masa depan lebih murah untuk diluncurkan (komponen reusable, primitif workflow/scheduling generik) daripada solusi one-off khusus untuk Legal saja.

## 2. Filosofi Arsitektur

> Detail untuk kustomisasi (DB / kode / custom fields & logic): **[ARCHITECTURE.md](ARCHITECTURE.md)** — ingat tangga ini di setiap fitur.

### Tangga kustomisasi (tanpa cabang `tenant_id`)
Utamakan anak tangga lebih rendah dulu. Jalur PHP/Vue yang sama; Firm A vs B berbeda lewat data DB tenant.

| Anak tangga | Apa | Di mana |
|------|--------|--------|
| 1 | Constants | `SYSCONFIG.config_consts` |
| 2 | Serials | `SYSCONFIG.config_snums` |
| 3 | Custom fields | `CUSTOMFIELDS.*` |
| 4 | Custom logic | Services yang membaca consts + nilai field / strategies |
| 5 | Plan / modul | `tenants.plan` pusat + `config/tenant_modules.php` |
| 6 | Modul vertical | `app/Modules/Legal` dst. |

**Anti-pola:** `if (tenant_id === '001')`. **OK:** seed consts/definisi field/serials berbeda per firm.

### Modular monolith dulu. Microservices/API hanya kalau dibenarkan
1. Default-nya adalah membangun di dalam monolith sebagai modul yang terisolasi dengan baik.
2. Hanya ekstrak microservice/API mandiri kalau setidaknya satu hal ini benar:
   - Beban kerja punya kebutuhan skala yang fundamentalnya berbeda (mis. pemrosesan async berat, OCR dokumen, pembuatan PDF dalam volume besar, panggilan AI/LLM).
   - Butuh bahasa/runtime yang berbeda dari yang cocok untuk PHP/Laravel.
   - Harus dipakai ulang lintas produk/vertical secara independen dari siklus rilis monolith.
   - Menyentuh data sensitif yang butuh isolasi kuat (mis. service payments/billing).
3. Jangan pernah ekstrak service murni demi estetika "clean architecture" — ekstraksi punya biaya operasional nyata untuk solo dev (deployment, monitoring, versioning). Justifikasi setiap kali terhadap biaya itu.
4. Kalau ragu, tanyakan: *"Apakah Claude Code dan saya masih bisa menalar sistem ini dalam 6 bulan tanpa tim?"* Kalau split yang diusulkan membuat itu lebih sulit, jangan lakukan.

### Batas modul (di dalam monolith)
- Setiap modul (Schedule, Notifications, Workflows, Legal-Cases, dll.) harus terstruktur sebagai unit mandiri: migrasi, model, service, policy, route, dan (jika berlaku) folder fitur frontend-nya sendiri.
- Modul core harus **tidak tahu apa-apa** tentang modul vertical. Modul vertical bergantung pada core, tidak pernah sebaliknya.
- Komunikasi lintas-modul lewat events/contracts (mis. Laravel events, service interfaces), bukan reach-through model langsung ke internal modul lain.
- Perlakukan setiap modul seolah suatu hari bisa di-toggle on/off per tenant (feature flag per-plan) — ini inti dari model rental/SaaS.

### Web vs klien masa depan (batas API)
- **Web (saat ini):** Laravel + Inertia.js + Vue 3. Controller mengembalikan `Inertia::render(...)`. **Jangan** membangun endpoint REST/GraphQL untuk halaman web.
- **Business logic** selalu ada di kelas Service (tidak pernah di controller atau Vue). Service adalah batas yang bisa dipakai ulang.
- **Mobile / Tablet / klien eksternal (nanti):** REST API yang di-versi, memanggil Service yang sama — tanpa duplikasi logika domain. Jangan membuat layer API paralel untuk web.
- Mulai dengan edisi Web. Ship REST hanya ketika klien non-Inertia sungguhan, bukan spekulatif.

## 3. Tech Stack

| Layer | Pilihan |
|---|---|
| Backend | Laravel 11/12 |
| Frontend (Web) | Vue 3 lewat Inertia.js (Vite, Tailwind CSS, Lucide Icons) |
| Klien masa depan | REST yang di-versi, memakai ulang kelas Service (belum) |
| Database | PostgreSQL |
| Cache / Queue broker | Redis |
| Web server | Nginx |
| Hosting | Ubuntu VPS |
| Local / container | Docker Compose (`docker-compose.yml`: app PHP, queue worker, PostgreSQL, Redis) |
| Design tooling | Claude Design / Google Stitch untuk eksplorasi UI, lihat `DESIGN.md` untuk sistem hasilnya |
| Coding agent utama | Claude Code |

Catatan:
- Laravel menangani logika bisnis core, auth, multi-tenancy, orkestrasi modul, background jobs (lewat Redis queues).
- Halaman Vue menerima props dari Inertia — jaga presentasi tetap di Vue, aturan domain di PHP Services.
- Microservice apa pun yang ditambahkan nanti sebaiknya default ke bahasa apa pun yang paling cocok untuk pekerjaan itu; jangan paksakan semuanya ke PHP kalau itu bukan tool yang tepat (mis. service Python untuk parsing dokumen/OCR itu sah-sah saja).

## 4. Multi-Tenancy

- Mode B: **satu database PostgreSQL per tenant** (`tenant_{id}`), lewat `stancl/tenancy`. Data aplikasi tenant **tidak** memakai kolom `tenant_id` — isolasi adalah batas DB.
- DB Pusat (`nusaevo`) menyimpan registry tenant + login lookup (`tenant_user_lookups`) + **plan** (`tenants.plan`). User dan data modul ada di DB tenant.
- Di dalam setiap DB tenant, modul mendapat schema terpisah (`SYSCONFIG`, `WNE`, `DMS`, `CRM`,
  `SCHEDULE`, `INVENTORY`, `ACCOUNTING`, `SALES`, `PURCHASE`, `HCM`, `PAYROLL`, `PERF`,
  `AIINSIGHT`, `LEGAL`, `CUSTOMFIELDS`, `PROJECTS`). Lihat §7A untuk daftar otoritatifnya. (Ini
  menyatukan schema `NOTIFICATIONS`/`WORKFLOW` lama yang terpisah menjadi satu schema `WNE`,
  sesuai `WNE_SPECS.md` — versi lebih lama dari section ini masih memakai nama pra-merger.)
- Resolusi tenant **terikat-login** (session `tenant_id` setelah lookup email), bukan
  domain/subdomain. UI bisa beralih antar keanggotaan lewat dropdown tenant di sidebar.
- Queue/cache/filesystem sadar-tenant lewat bootstrapper stancl. **Jangan** memakai PostgreSQL
  RLS.
- Feature-flagging plan/fitur: `config/tenant_modules.php` + `TenantFeatureService` +
  middleware `module:CODE`. Menu sidebar juga menyembunyikan modul yang tidak ada di plan.
- Otorisasi di dalam tenant: trustee SYSCONFIG (C/R/U/D) lewat middleware `menu.perm:MENU_CODE`
  (bukan pengganti isolasi DB).

## 5. Urutan Build

1. **SYSCONFIG** (System Configuration, Access Control & Runtime Settings) — fondasional,
   dibangun sebelum apa pun, termasuk design system dan setiap modul Core. Setiap bagian yang
   dibangun kemudian — pengecekan menu/permission, consts yang bisa diedit tenant, penomoran
   serial, aktivasi modul — bergantung padanya, dan spec beberapa modul lain sudah
   mengasumsikan ini ada (mis. contoh `CASE_PREFIX`/`URGENT_SETS_PENDING` milik Legal di
   `ARCHITECTURE.md`). Lihat `SYSCONFIG_SPECS.md`.
2. **CustomFields** (schema `CUSTOMFIELDS`) — fondasional berdampingan dengan SYSCONFIG,
   dibangun segera setelahnya dan sebelum design system atau modul Core mana pun. EAV +
   logika berbasis-config; lihat `ARCHITECTURE.md` dan `CUSTOMFIELDS_SPECS.md`. Ini bukan
   scaffolding opsional: Metadata Management milik DMS, Custom Fields milik CRM, field
   deed/matter/land-object milik Legal, custom fields produk milik Inventory, field
   component/run milik Payroll, dan lainnya semuanya sudah mengasumsikan
   `CUSTOMFIELDS.field_defs`/`field_values` ada sebagai bagian dari MVP ship *mereka sendiri*.
   `SYSCONFIG_SPECS.md` §5 dan `CUSTOMFIELDS_SPECS.md` §5 masing-masing secara independen
   merekomendasikan koreksi ini; entri ini menerapkannya.
3. **Design system / component library** — bangun komponen UI dasar dan widget sesuai
   `DESIGN.md` sebelum membangun layar fitur. Setiap UI modul harus disusun dari library
   bersama ini, bukan diberi gaya satu-per-satu per modul.
4. **Modul Core** (dipakai bersama oleh setiap vertical). Masing-masing punya `*_SPECS.md`
   sendiri yang mencakup Backgrounds/Goals/Forms-Engines/Storage/Technical Notes — cek spec
   modul terkait sebelum mulai kerja di sebuah modul; section ini hanya melacak urutan, bukan
   detail. Urutan build yang konsisten dengan dependensi yang dinyatakan sendiri oleh setiap
   modul (lihat §5 setiap spec untuk detailnya):
   - **WNE** (Workflow & Notification Engine — approval, state machine, task routing,
     notifikasi multi-channel) — fondasional; setiap modul lain memakainya duluan.
   - **DMS** (Document Management System)
   - **CRM**
   - **Schedule**
   - **Inventory**
   - **Accounting** — dibangun sebelum Purchase/Sales dianggap feature-complete, karena
     keduanya punya dependensi keras padanya untuk satu aksi spesifik (pencatatan AP,
     penagihan AR).
   - **Purchase**
   - **Sales**
   - **HCM**
   - **Payroll** — dependensi keras ke HCM (identitas karyawan); bangun segera setelahnya.
   - **Performance**
   - **AIInsight** — sudah dispesifikasikan (`AIINSIGHT_SPECS.md`) tapi belum dibangun;
     digerbang oleh perjanjian Zero Data Retention dengan Anthropic sebelum live untuk tenant
     mana pun yang sensitif-kerahasiaan (lihat catatan "on the horizon" milik proyek).
5. **Projects** (schema `PROJECTS`, `app/Modules/Projects/`) — project/issue tracker dengan
   Kanban board (lihat `app/Modules/Projects/PROJECTS_SPECS.md`). Bisa dipakai untuk tenant
   Nusaevo sendiri lewat plan `internal` di `config/tenant_modules.php` (§2 tangga kustomisasi
   anak tangga 5), dipakai sebagai board gaya-Jira milik tim sendiri.
6. **Modul vertical Legal** — produk berbayar dan rentable pertama, dibangun di atas modul Core
   di atas. Utamakan kebenaran dan kehalusan UX di sini di atas generalisasi spekulatif untuk
   vertical masa depan.
7. Vertical masa depan (mis. Property) datang setelah Legal tervalidasi di pasar — pakai ulang
   modul core, tambahkan modul spesifik-vertical saja.
Saat mengerjakan sebuah task, Claude harus mengecek apakah itu masuk **Core** (reusable) atau
**modul vertical aktif** (spesifik-Legal) dan menempatkan kode sesuai. Kalau tidak yakin,
tanyakan daripada menebak — salah menempatkan logika di sini punya biaya arsitektural jangka
panjang.

## 6. Konvensi Coding

### Laravel / PHP
- Ikuti PSR-12 dan konvensi Laravel standar (controller tipis, logika bisnis di
  services/actions, model Eloquent tetap ramping).
- Pakai Form Requests untuk validasi, Policies untuk otorisasi, dan Events/Listeners untuk
  komunikasi lintas-modul.
- Setiap tabel baru butuh migrasi; jangan pernah edit migrasi yang sudah dijalankan di
  environment bersama/deployed — tulis migrasi baru.
- Tulis test feature/unit untuk logika modul core, terutama Scheduler/Workflows karena modul
  lain akan bergantung pada kebenarannya.

### Vue.js / TypeScript
- TypeScript strict. Tidak ada `any` implisit.
- Jaga folder fitur selaras dengan modul backend sebisa mungkin, agar mental model konsisten
  untuk solo dev.
- Primitif UI bersama ada di paket/folder design-system yang dijelaskan di `DESIGN.md`; kode
  fitur harus menyusun dari itu, bukan mendefinisikan ulang gaya secara inline.

### Umum
- Utamakan kode yang eksplisit dan boring di atas abstraksi yang cerdik — codebase ini akan
  dibaca dan diperluas oleh satu orang (plus Claude Code), jadi optimalkan untuk diri-masa-depan
  yang membaca ulang, bukan untuk mengesankan sebuah tim.
- Tambahkan komentar singkat yang menjelaskan *mengapa* untuk keputusan arsitektural yang tidak
  jelas (mis. mengapa sesuatu tetap di Core vs. Legal, mengapa sebuah microservice
  dipisahkan).

## 7. Konvensi Storage
### A. Database:
- Satu DB untuk setiap tenant.
- Schema terpisah untuk setiap modul.
- Custom fields di schema terpisah.
- Struktur:
```text
tenant_001.			# Database
├── SYSCONFIG.		# Menus, groups, rights, consts (runtime authz)
├── INVENTORY.		# Prefer new inventory tables here (legacy demo tables may still be in public)
├── WNE.
├── CRM.
├── SCHEDULE.
├── DMS.
├── LEGAL.
├── ACCOUNTING.
├── SALES.
├── PURCHASE.
├── HCM.
├── PAYROLL.
├── PERF.
├── PROJECTS.		
├── AIINSIGHT.
└── CUSTOMFIELDS.
```
- Penamaan tabel:
	- Master:
		- kebanyakan pakai 1 bagian, mis. materials, partners, dst.
	- Transaksi:
		- kebanyakan pakai 2 bagian: nama-singkatan + level, mis. `SALES.so_hdrs` = modul
		  Sales, tabel Sales Order, level Header — sesuai konvensi sesungguhnya yang dipakai
		  di seluruh `*_SPECS.md` (`ACCOUNTING.gl_journals`, `WNE.wrkflow_instances`,
		  `SCHEDULE.sched_items`, `PURCHASE.pur_receipt_hdrs`, dst.)
- Pakai bigint untuk PK, FK, dan JOIN. Tambahkan UUID untuk objek yang menghadap eksternal.
- Pakai stancl/tenancy sebisa mungkin.
- Strategi resolusi tenant terikat-login.
- Jangan pakai PostgreSQL Row Level Security.

## B. Object File:
- Folder terpisah untuk setiap tenant.
- Susunan subfolder dan object file harus mempertimbangkan performa/kapabilitas restore.
- Struktur:
```text
tenant_001/
├── DB/
├── DMS/          # primary shared document store for most modules' documents,
│                 # subfoldered by owning module — e.g. DMS/LEGAL, DMS/HCM, DMS/Sales
├── CRM/
├── SCHEDULE/
├── ACCOUNTING/   # system-generated artifacts not routed through DMS (bank statement
│                 # imports, tax exports, generated reports) — see ACCOUNTING_SPECS.md §4
├── PURCHASE/     # reserved per-module convention for restore-planning consistency;
│                 # most actual document content still routes through DMS — see
│                 # PURCHASE_SPECS.md §4
├── PAYROLL/      # same reserved-folder convention as Purchase — see PAYROLL_SPECS.md §4
└── INVENTORY/    # same reserved-folder convention as Purchase — see INVENTORY_SPECS.md §4
```
- Modul yang tidak tercantum di atas (WNE, HCM, Sales, Performance, AIInsight) tidak memiliki
  folder R2 top-level sendiri — file mereka, jika ada, sepenuhnya lewat struktur DMS dengan
  pointer `subject_type`/`subject_id` balik ke record pemiliknya, sesuai spec masing-masing
  modul sendiri.
- Object file akan memakai satu bucket Cloudflare R2. Dengan konvensi penamaan untuk
  membedakan antar tenant, modul, waktu, dst.

## 8. Konvensi Development

### Perintah Build & Run
Host punya Node/npm; PHP berjalan di dalam Docker Compose (image sudah termasuk `pdo_pgsql`,
`redis`, Composer). **Jangan** memakai `composer:latest` polos untuk artisan — image itu tidak
punya ekstensi DB/Redis.

Stack (lihat `docker-compose.yml`):
- `app` — `php artisan serve` di `:8000`
- `queue` — `php artisan queue:work` (Redis)
- Postgres/Redis sekarang datang dari stack shared-infra (`shared-postgres`/`shared-redis`,
  port host **5432**/**6379**), digabung lewat network eksternal `shared-infra`. Jalankan
  stack itu sebelum stack ini.

Compose menyuntikkan env DB/Redis untuk container. Vite tetap di host.

### Setup Development Lokal
```bash
# First-time / after clone
cp .env.example .env          # set APP_KEY via artisan key:generate below if empty
docker compose build
docker compose run --rm app composer install
npm install
docker compose up -d
docker compose exec app php artisan key:generate   # once
docker compose exec app php artisan migrate --seed

# Local / container
docker compose up -d          # app + queue (needs shared-infra stack running)
npm run dev                   # Vite on host → http://localhost:8000

# Host tools (psql/redis-cli): localhost:5432 / localhost:6379 (shared-infra stack)
# PHP always via docker — .env uses postgres/redis service names (network aliases).
```

Artisan sekali-pakai (contoh):
- **Migrate**: `docker compose exec app php artisan migrate`
- **Seed**: `docker compose exec app php artisan db:seed`
- **Fresh + seed**: `docker compose exec app php artisan migrate:fresh --seed`
- **Tinker**: `docker compose exec app php artisan tinker`

### Build Aset Produksi
- **Build produksi Vite**: `npm run build`

### Kualitas Kode & Formatting
- **PHP Linting (Laravel Pint)**: `docker compose exec app ./vendor/bin/pint`
- **TypeScript Checking**: `npm run build` (menjalankan `vue-tsc`)

### Menjalankan Test
- **Jalankan test PHPUnit**: `docker compose exec app php artisan test`

## 9. Pedoman & Konvensi Codebase

### A. Arsitektur Modular Monolith
- Modul bisnis ada di `app/Modules/<ModuleName>/`.
- Setiap modul berisi:
  - `Controllers/` (Hanya controller tipis)
  - `Models/` (Model Eloquent dengan query scope)
  - `Requests/` (Kelas validasi FormRequest Store/Update)
  - `Services/` (Semua logika bisnis dan transaksi DB)
  - `Data/` (DTO / Data object)
  - `Enums/` (Nilai status dan constants)
  - `Routes/` (Routing bernama `web.php`)
- Utility bersama/core ada di `app/Shared/` (`Actions/`, `DTOs/`, `Enums/`, `Services/`,
  `Traits/`, `Helpers/`).
- Route modul dimuat secara dinamis dari `routes/web.php`.

### B. Struktur Halaman Frontend
- Halaman Vue ada di `resources/js/Pages/<ModuleName>/Items/` (mis. `Index.vue`, `Create.vue`,
  `Edit.vue`).
- Layout, navigasi, form, dan komponen tabel frontend bersama ada di `resources/js/Components/`
  (`layout/`, `navigation/`, `forms/`, `tables/`, `filters/`, `modals/`, `feedback/`).

### C. Konvensi Coding
- **Controllers**: Jaga controller tetap tipis. Validasi request memakai Form Requests,
  delegasikan eksekusi ke kelas Service, dan kembalikan response Inertia.
- **TypeScript**: Pakai TypeScript strict di file Vue. Definisikan tipe dan interface secara
  eksplisit untuk props yang dikirim backend.
- **Tailwind CSS**: Pakai utility class langsung untuk layout dan styling UI. Jaga struktur
  bersih dan spacing konsisten.
- **Lucide Icons**: Render icon Lucide secara dinamis di layout dan sidebar memakai helper
  `<component :is="..." />`.

### D. Standar Komponen Frontend yang Baku (WAJIB / STRICT)
Setiap halaman atau fitur UI **wajib** disusun dari komponen bersama di `resources/js/Components/`. **DILARANG KERAS membuat primitif UI ad-hoc atau elemen HTML mentah tanpa styling standar.**

1. **Modals & Dialogs (STRICT)**:
   - **WAJIB** memakai `@/Components/Modal.vue` (`<Modal :show="showModal" max-width="md|lg|xl|2xl" @close="showModal = false">`).
   - **JANGAN PERNAH** membuat overlay manual dengan `<div class="fixed inset-0 ...">`.
   - Card konten di dalam modal **WAJIB** memiliki background solid/opaque (mis. `bg-white` atau `bg-surface rounded-lg p-6`) agar tidak bocor/transparan.
   - Untuk konfirmasi aksi / dialog hapus: **WAJIB** memakai `@/Components/modals/ConfirmDialog.vue` lewat composable `useConfirm()` (`const { confirm } = useConfirm()`). **JANGAN PERNAH** memakai `confirm()` bawaan browser.

2. **Form Controls & Inputs (`@/Components/forms/`)**:
   - Text, Email, Number, Date: `FormInput.vue` (sudah ada label, asterisk required, dan pesan error).
   - Teks panjang: `FormTextarea.vue`.
   - Dropdown select native: `FormSelect.vue`.
   - Single searchable select (in-memory): `FormSearchableSelect.vue`.
   - Async / remote searchable select: `FormAsyncSearchableSelect.vue` (untuk relasi besar: partners, produk, users).
   - Multi-Select tags / badges: `FormMultiSelect.vue`. **JANGAN PERNAH** membuat loop checkbox grid manual untuk pemilihan banyak item.
   - Boolean toggle: `FormSwitch.vue`.
   - Pilihan radio: `FormRadioGroup.vue`.
   - Custom field dinamis: `CustomFieldInputs.vue` (untuk atribut EAV dari `CUSTOMFIELDS`).

3. **Tombol / Buttons (`@/Components/`)**:
   - Aksi utama / CTA: `PrimaryButton.vue` (mendukung link `:href` atau aksi submit/button biasa, mendukung loading state).
   - Aksi sekunder / batal: `SecondaryButton.vue`.
   - Aksi destruktif / hapus: `DangerButton.vue`.
   - **JANGAN PERNAH** menulis elemen `<button>` mentah dengan styling warna ad-hoc.

4. **Tabel & List (`@/Components/tables/`)**:
   - **WAJIB** memakai `DataTable.vue` (`@/Components/tables/DataTable.vue`).
   - Fitur bawaan: sorting, server pagination, toolbar pencarian, Status Rail per-baris, row detail expandable, dan subtotal outline groupBy.

5. **Cards & Kontainer (`@/Components/cards/`)**:
   - Kontainer panel: `Panel.vue` (dengan slot judul header, aksi, footer, dan dukungan Status Rail).
   - Kartu metrik KPI: `StatCard.vue` (angka memakai font Source Serif 4).

6. **Feedback & Badges (`@/Components/feedback/`)**:
   - Status badge: `StatusBadge.vue` dengan varian token semantik (`variant="success|warning|danger|info|neutral"`). **JANGAN PERNAH** mengarang warna badge sendiri.
   - Flash toast notification: `Toast.vue`.

7. **Layout & Navigasi (`@/Components/layout/` & `@/Components/navigation/`)**:
   - Layout utama halaman: `AppLayout.vue`.
   - Header halaman standar: `PageHeader.vue` (judul, deskripsi, dan slot tombol aksi).
   - Sub-tab navigasi: `Tabs.vue`.
   - Sub-navigasi modul: pakai kembali subnav modul terkait (mis. `HcmSubNav.vue`, `CrmSubNav.vue`, `InventorySubNav.vue`).

## 10. Bekerja dengan Claude Code

- Sebelum menambahkan modul atau service baru, nyatakan kategorinya (Core / Vertical /
  Microservice) dan alasannya, sesuai Section 2 dan Section 5.
- Ketika sebuah task masuk akal diselesaikan baik di dalam monolith maupun sebagai service
  terpisah, default-nya ke monolith dan tandai trade-off-nya, bukan diam-diam mengekstrak
  service.
- Saat menyentuh jalur data multi-tenant, cek ulang scoping tenant sudah ada — ini area risiko
  yang berulang.
- Utamakan tangga kustomisasi di §2 / `ARCHITECTURE.md` (consts → serials → custom fields →
  logic) di atas cabang tenant_id.
- **Penegakan Standar UI Ketat**: Rujuk `resources/DESIGN.md` dan Section 9D sebelum membangun UI baru. Selalu susun dari `resources/js/Components/` (`Modal.vue`, `FormMultiSelect`, `FormInput`, `DataTable`, `Panel`, `StatCard`, `PrimaryButton`, `StatusBadge`). Jangan pernah mengarang overlay modal ad-hoc, loop checkbox manual, atau kontrol tanpa styling standar.
- Karena ini produk SaaS komersial, saat mengusulkan fitur atau pendekatan implementasi,
  singgung sebentar apakah ada versi lebih sederhana yang masih layak jual (bias MVP),
  terutama untuk modul Legal yang paling dekat dengan revenue.

## 11. Item Terbuka untuk Diisi Seiring Proyek Berkembang

- [x] Kontrak API (Web): **Inertia.js**. Controllers → Services → `Inertia::render`. REST
      hanya nanti untuk mobile/eksternal, Services yang sama.
- [x] Strategi auth (session + tenancy terikat-login; Sanctum dicadangkan untuk klien token
      masa depan)
- [ ] Billing langganan SaaS tenant (bagaimana platform sendiri menagih tiap tenant untuk plan
      mereka) — string `tenants.plan` sudah ada di DB pusat; belum ada integrasi payment
      provider. Berbeda dari item AR/AP in-app di bawah — jangan dicampur.
- [x] Kepemilikan modul AR/AP in-app (bagaimana Sales/Purchase/modul vertical *tenant* menagih
      *pelanggan mereka* dan membayar *vendor mereka*) — **terselesaikan**: Accounting adalah
      satu-satunya ledger AR/AP platform; Sales adalah satu-satunya pemanggil sisi-AR
      (`InvoiceRequested`/`PaymentRequested`, termasuk atas nama modul vertical seperti Legal
      lewat `SalesOrderRequested` — lihat `SALES_SPECS.md` §3I/§5 dan `LEGAL_SPECS.md` §2);
      Purchase adalah satu-satunya pemanggil sisi-AP (`BillRequested` — lihat
      `PURCHASE_SPECS.md` §3F/§5).
- [ ] Pipeline CI/CD dan proses deployment untuk VPS Ubuntu
- [ ] Pendekatan limit/monitoring infrastruktur per-tenant
- [x] `DESIGN.md` — token desain, inventaris komponen (`resources/DESIGN.md`; token di
      `app.css` / Tailwind)
- [x] Feature flag plan/modul (`tenants.plan` + `config/tenant_modules.php` + middleware
      `module:`)
- [x] Penegakan trustee menu in-tenant (middleware `menu.perm:`)
- [x] Custom fields + custom logic + serials (`ARCHITECTURE.md`; UI admin field-defs masih
      terbuka)

## graphify — WAJIB SEMUA PROJECT / SEMUA AI AGENT

Canonical: `~/.agents/rules/graphify.md` (always_on).

STRICT:
1. JANGAN `grep` / `grep_search` / ripgrep sebagai **langkah pertama** untuk cari kode, flow, arsitektur, bug tracing codebase, atau navigasi source.
2. SELALU graphify dulu: cek `graphify-out/graph.json` → jika hilang `graphify index .` → `graphify query "..."` (atau MCP `query_graph`).
3. Relasi: `graphify path A B`. Konsep: `graphify explain "..."`.
4. `grep` / baca raw **hanya** setelah graphify kosong/gagal, atau user kasih path file eksplisit.
5. Wiki `graphify-out/wiki/index.md` preferensi navigasi. `GRAPH_REPORT.md` hanya review luas.
6. Setelah edit kode di session: `graphify update .` (AST-only).
7. Subagent ikut aturan ini.
8. Pengecualian: path eksplisit user; non-codebase (git/SQL/MCP-DB/build/config); graphify CLI/MCP error total (laporkan + fallback).


---

## Caveman - Mode Respons Chat

Merespons ringkas ala manusia gua yang pintar. Semua substansi teknis tetap ada. Hanya basa-basi yang mati.

Aturan:
- Buang: artikel (a/an/the), filler (just/really/basically), basa-basi, hedging
- Fragmen boleh. Sinonim pendek. Istilah teknis tetap persis. Kode tidak berubah.
- Pola: [hal] [aksi] [alasan]. [langkah berikutnya].
- Bukan: "Sure! I'd be happy to help you with that."
- Ya: "Bug in auth middleware. Fix:"

Auto-Clarity: lepas mode caveman untuk peringatan keamanan, aksi ireversibel, user bingung. Lanjutkan lagi sesudahnya.
Batasan: kode/commit/PR ditulis normal.

## Ponytail - Mode Coding

Kamu adalah senior developer yang malas. Malas berarti efisien, bukan ceroboh. Kode terbaik adalah kode yang tidak pernah ditulis.

Sebelum menulis kode apa pun, berhenti di anak tangga pertama yang berlaku:

1. Apakah ini perlu dibangun sama sekali? (YAGNI)
2. Apakah standard library sudah melakukan ini? Pakai itu.
3. Apakah fitur platform native mencakupnya? Pakai itu.
4. Apakah dependency yang sudah ter-install menyelesaikannya? Pakai itu.
5. Bisakah ini jadi satu baris? Jadikan satu baris.
6. Baru kalau semua itu tidak berlaku: tulis kode minimum yang berfungsi.

Aturan:
- Tidak ada abstraksi yang tidak diminta secara eksplisit.
- Tidak ada dependency baru kalau bisa dihindari.
- Tidak ada boilerplate yang tidak diminta siapa pun.
- Penghapusan di atas penambahan. Boring di atas cerdik. Sesedikit mungkin file.
- Pertanyakan request yang kompleks: Apakah kamu benar-benar butuh X, atau Y sudah cukup?
- Tandai penyederhanaan yang disengaja dengan komentar ponytail:.

Tidak malas soal: validasi input, error handling yang mencegah kehilangan data, keamanan, aksesibilitas, apa pun yang diminta secara eksplisit.

Batas Domain: Ponytail mengatur KODE. Caveman mengatur CHAT. Blok kode/commit/PR ditulis normal.
