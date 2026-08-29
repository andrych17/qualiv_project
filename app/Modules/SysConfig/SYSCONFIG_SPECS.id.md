# Modul SYSCONFIG
## Konfigurasi Sistem, Kontrol Akses & Pengaturan Runtime — Modul Core Fondasional (tanpa cerita standalone; setiap modul lain bergantung padanya)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap modul lain di platform ini sudah bersandar pada `SYSCONFIG` secara konseptual — ia
adalah schema pertama yang tercantum dalam struktur `CLAUDE.md` §4/§7, ia adalah anak tangga
1–2 dari tangga kustomisasi `ARCHITECTURE.md` (consts, serial), dan `ARCHITECTURE.md`
§3.2.A/§3.3 sudah menunjukkan contoh kerja (`LEGAL.CASE_PREFIX`, `LEGAL.URGENT_SETS_PENDING`,
`ConfigSnumService::next()`) yang mengasumsikan `config_consts`/`config_snums` sudah ada dan
berperilaku dengan cara tertentu. Tapi tidak seperti WNE, DMS, CRM, dan setiap modul yang
dibangun setelahnya, `SYSCONFIG` tidak pernah menerima `*_SPECS.md`-nya sendiri. Dibiarkan
informal, ini menciptakan risiko persis yang sama yang berusaha dicegah setiap spesifikasi
lain di platform ini:

- Tiga concern pengaturan tidak punya rumah formal: **modul mana yang saat ini dinyalakan
  tenant**, **pengaturan tenant-wide umum**, dan **pengaturan/switch tingkat-modul** — masing-
  masing saat ini akan diselesaikan secara ad hoc, per modul, jika tidak dibahas di sini.
- Data lookup kecil yang jarang berubah (segelintir kode klasifikasi tetap) memaksa pilihan
  antara `enum`/`match` PHP yang di-hardcode (deploy kode untuk menambah satu nilai) atau
  proliferasi tabel single-purpose yang nyaris kosong — clutter skema nyata bagi solo dev untuk
  dipelihara, untuk data yang sebenarnya hanyalah segelintir baris yang nyaris tidak pernah
  berubah.
- Tidak ada hierarki override formal untuk sebuah setting — hari ini sebuah const secara efektif
  hanya tenant-wide. Tidak ada cara konsisten untuk mengatakan "ini berperilaku berbeda di dalam
  modul Legal" atau "untuk user tertentu ini" tanpa special-casing ad hoc di kode aplikasi, yang
  justru persis apa yang dicegah tangga kustomisasi di `ARCHITECTURE.md`.
- Menu, group, dan hak akses dirujuk sebagai infrastruktur yang sudah ada dan berfungsi
  (`CLAUDE.md` §4: middleware `menu.perm:MENU_CODE`) tapi tidak punya skema formal di mana pun —
  Claude Code tidak punya apa pun yang konkret untuk dibangun atau diperluas.
- Hampir setiap spesifikasi modul lain menjanjikan "ini adalah data yang dapat diedit tenant,
  tidak pernah konstanta yang di-hardcode" (tarif pajak Legal, tabel statutori HCM, jam SLA WNE,
  threshold kontrol Accounting) — `SYSCONFIG` adalah modul yang menjadi sandaran struktural janji
  itu. Tanpa spesifikasi formal, janji itu tidak punya bentuk yang ditegakkan di seluruh platform.

**Kebutuhan klien:**
- Tiga pengaturan diformalkan: **aktivasi modul** (modul mana yang dinyalakan tenant), **general
  settings** (tenant-wide), dan **pengaturan tingkat-modul** (berskop satu modul) — semuanya
  melalui set mekanisme yang konsisten dan minimal, bukan satu tabel bespoke per modul.
- Setting harus mendukung **override berskop, berlapis di dalam sebuah modul**: sebuah setting
  bisa membawa default module-wide (`appl_id`), override lebih lanjut untuk group user tertentu
  di dalam modul itu (`appl_id` + `group_id`), dan override lebih lanjut untuk user tertentu di
  dalam modul itu (`appl_id` + `user_id`) — diresolusi menjadi satu nilai efektif saat dibaca.
  (`appl_id` adalah nama kolom legacy yang dibawa dari sistem multi-client sebelumnya, di mana
  ia merepresentasikan client/aplikasi yang dilayani. Di arsitektur tenant-per-DB platform ini,
  peran itu sudah ditangani oleh isolasi DB, jadi `appl_id` digunakan-ulang untuk berarti
  **modul** — ruang kode yang sama dengan `tenant_modules.module_code`, §3A.)
- Mekanisme yang mendasari yang sama harus juga berfungsi sebagai **tabel mini-master/enum
  generik** — daftar lookup kecil dan statis (segelintir baris, jarang jika pernah berubah)
  seharusnya tidak membutuhkan migration + model + layar admin khusus masing-masing; sepasang
  kolom payload numerik generik (`num1`, `num2`) dan string (`str1`, `str2`) plus field `note`
  sudah cukup untuk kasus umum.
- Harus memformalkan mekanisme penomoran serial yang sudah dirujuk (`config_snums`,
  `ConfigSnumService::next()`) dan infrastruktur otorisasi menu/group/rights yang sudah dirujuk
  (`menu.perm:MENU_CODE`), karena spesifikasi modul lain mengasumsikan keduanya sudah ada.
- Harus berada **di bawah** setiap modul lain dalam graf dependensi — `SYSCONFIG` tidak boleh
  bergantung pada WNE, DMS, CRM, atau apa pun lainnya, karena modul-modul itu (dan spesifikasi
  mereka) mengasumsikan `SYSCONFIG` sudah tersedia bagi mereka.
- Sadar multi-tenant, isolasi DB-per-tenant yang sama seperti setiap modul lain — tanpa kolom
  `tenant_id` (sesuai `CLAUDE.md` §4/§7); `SYSCONFIG` berada di dalam setiap DB tenant, jadi
  datanya sudah secara alami per-tenant.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — modul ini memblokir setiap modul lain secara konseptual,
> jadi inti yang benar dan minimal lebih penting daripada pengalaman admin yang lengkap sejak
> hari pertama.

**MVP (kirim pertama — ini adalah infrastruktur prasyarat, bukan add-on opsional)**
- **Aktivasi Modul** (`tenant_modules`) — lapisan toggle DB-tenant yang duduk di atas mekanisme
  entitlement DB-pusat yang sudah ada (`tenants.plan` + `config/tenant_modules.php` +
  `TenantFeatureService`, sudah ditandai resolved di `CLAUDE.md` §11). Ini adalah switch
  UX/visibilitas, tidak pernah gerbang entitlement kedua — lihat §3A.
- **Engine Config Consts** (`config_consts`) — mekanisme tunggal yang melayani general settings,
  pengaturan tingkat-modul, dan lookup mini-master/enum kecil, dengan override berskop
  `appl_id`/`group_id`/`user_id` diresolusi menjadi satu nilai efektif. Lihat §3B/§3C/§3E.
- **Engine Config Serials** (`config_snums`) — memformalkan mekanisme running-number atomik
  yang sudah dirujuk di `ARCHITECTURE.md` §3.2.A dan dipakai generator kode-kasus Legal. Lihat
  §3D.
- **Menu, Group & Hak Akses** — memformalkan skema di balik middleware `menu.perm:MENU_CODE`
  yang sudah dirujuk `CLAUDE.md` §4, dan memperkenalkan `groups` sebagai satu konsep bersama
  yang dipakai baik untuk trustee izin-menu *maupun* sebagai dimensi scope untuk override
  `config_consts`. Lihat §3F.
- **Log Audit Config** — log append-only dari setiap perubahan setting/serial/aktivasi, postur
  audit immutable yang sama yang sudah diterapkan setiap modul lain di platform ini untuk data
  sensitif mereka sendiri. Lihat §3G.

**Future Version (secara eksplisit ditunda — jangan dibangun sekarang)**
- **Tabel override entitlement Central** (`central.tenant_module_grants`) — override per-tenant
  di atas default tier-plan (misalnya comping fitur beta, membatasi waktu trial AIInsight untuk
  satu tenant) tanpa deploy kode. Murni aditif di atas mekanisme `config/tenant_modules.php`
  yang sudah ada — bangun hanya begitu ada kebutuhan komersial nyata untuk override per-tenant
  (di luar tier plan) yang muncul.
- **Kontrol perubahan gaya-regulatori** — mengalihkan perubahan const sensitif melalui workflow
  approval WNE (`workflow_code = sysconfig.const_change_approval`) sebelum berlaku, untuk tenant
  yang menginginkan sepasang mata kedua sebelum sebuah threshold berubah di produksi.
  Mencerminkan workflow Aktivasi Regulatory Rule opsional milik Payroll
  (`PAYROLL_SPECS.md` §3B) — pola reuse-WNE yang sama, bukan logika approval baru.
- **UI diff/preview** untuk nilai const versi baru (membandingkan proposal vs. yang sedang aktif,
  sebelum menyimpan) — berguna, tidak menghambat; kolom `effective_date` yang mendasarinya (§3B)
  sudah mendukungnya tanpa perubahan skema nanti.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> database.

## 3A. Aktivasi Modul (Entry)

**Tujuan:** switch on/off menghadap-tenant untuk modul yang sudah menjadi hak mereka — kontrol
visibilitas murni, tidak pernah gerbang entitlement kedua.

- Field: `module_code` (dari daftar modul platform yang tetap — kode yang sama dengan daftar
  schema di `CLAUDE.md` §7A: `WNE`, `DMS`, `CRM`, `SCHEDULE`, `INVENTORY`, `ACCOUNTING`,
  `PURCHASE`, `SALES`, `HCM`, `PAYROLL`, `PERFORMANCE`, `AIINSIGHT`, `LEGAL`), toggle
  `is_active`, `activated_at`/`activated_by` (auto-set), `notes`.
- Tampilan list: satu baris per modul platform, menampilkan **entitlement** tenant (read-only,
  bersumber dari `tenants.plan` central via `TenantFeatureService`) berdampingan dengan toggle
  `is_active` tenant sendiri — seorang admin melihat baik "apakah kita membayar untuk ini"
  maupun "apakah kita saat ini ingin ini terlihat," dan tidak pernah bisa menyalakan sesuatu
  yang bukan hak tenant.

**Aturan / logika**
- **Visibilitas efektif** (sidebar/menu, akses route) = `entitled` (central) **DAN** `is_active`
  (tabel ini). Entitlement selalu batas atas (ceiling) yang keras; tabel ini hanya bisa
  mempersempitnya lebih lanjut, tidak pernah memperlebarnya.
- **Default adalah opt-out, bukan opt-in**: jika tidak ada baris untuk `module_code` yang
  menjadi hak tenant, itu dianggap aktif — sehingga modul yang baru menjadi hak (misalnya
  upgrade plan) langsung muncul tanpa membutuhkan baris seed di sini.
- Menonaktifkan modul menyembunyikan entri sidebar/menu-nya dan memblokir record baru di
  lapisan routing (middleware `module:CODE` yang sudah ada, `CLAUDE.md` §4) tapi tidak pernah
  menyentuh data yang sudah ada — postur non-destruktif yang sama seperti pola
  deaktivasi/arsip modul lain mana pun.

## 3B. Konsol General & Module Settings (`config_consts`)

**Tujuan:** mekanisme tunggal yang melayani baik "general settings tenant-wide" maupun
"pengaturan tingkat-modul," dengan override berskop opsional.

- Field: `appl_id` (nullable — **scope modul**, ruang kode yang sama dengan
  `tenant_modules.module_code`, §3A; kode biasa, bukan tabel master ber-FK sendiri, karena ini
  adalah set kecil terkendali yang sudah dienumerasi di tempat lain — nama legacy dari sistem
  sebelumnya di mana ia berarti "client"; digunakan-ulang di sini karena isolasi tenant sudah
  menjadi batas DB), `group_id` (nullable, FK → `SYSCONFIG.groups.id`, §3F — override **group
  user**, bermakna dalam kombinasi dengan `appl_id`: "group ini, di dalam modul ini"), `user_id`
  (nullable, FK → `users.id` milik tenant — override user individu, bermakna dalam kombinasi
  dengan `appl_id`: "user ini, di dalam modul ini"), `const_group` (namespace logis dari setting
  — kode modul seperti `LEGAL`, atau `SYSTEM` untuk setting platform-wide), `group_code` (key
  spesifik di dalam namespace itu, misalnya `CASE_PREFIX`, `DEFAULT_LOCALE`), `value` (text —
  nilai setting skalar, di-cast sesuai `value_type` di `ConfigService`), `value_type`
  (`text`/`number`/`bool`/`date` — VARCHAR + CHECK, bukan enum Postgres native, sesuai konvensi
  "field status/type sebagai VARCHAR + CHECK" yang sudah ditetapkan platform ini sendiri),
  `num1`/`num2` (slot payload numerik nullable), `str1`/`str2` (slot payload string nullable),
  `note` (teks bebas), `seq` (urutan tampilan/sort), `effective_date` (nullable — untuk setting
  sederhana yang berubah sesuai jadwal), `is_active`.
- Tampilan list: dapat difilter/dikelompokkan berdasarkan `const_group`, sehingga admin yang
  menjelajah setting Legal tidak pernah harus melewati setting AIInsight. Entri baris
  menyesuaikan field yang terlihat sesuai `value_type` — setting skalar menampilkan satu input
  sesuai tipenya; baris yang dipakai sebagai anggota enum (§3C) sebaliknya menampilkan field
  `num1`/`num2`/`str1`/`str2`/`note`.
- Untuk menambahkan override berskop bagi setting yang sudah ada, admin menambahkan **baris
  baru** dengan `const_group`/`group_code` yang sama tapi `appl_id`/`group_id`/`user_id` yang
  tidak null — tidak pernah mengedit baris global di tempat.

**Aturan / logika**
- Uniqueness ditegakkan pada `(appl_id, group_id, user_id, const_group, group_code)` — tepat
  satu baris per kombinasi scope, tanpa duplikat ambigu.
- Setiap penulisan dicatat ke `config_audit_logs` (§3G) — sebuah const yang dibalik bisa secara
  diam-diam mengubah perilaku bisnis di seluruh platform (sesuai contoh
  `LEGAL.URGENT_SETS_PENDING` sendiri di `ARCHITECTURE.md`), jadi harus bisa direkonstruksi
  siapa mengubah apa, kapan, dan dari/ke nilai apa.
- Menonaktifkan (`is_active = false`) adalah jalur penghapusan default, bukan delete — prinsip
  non-destruktif yang sama yang dipakai di tempat lain di platform ini.
- Kesederhanaan "ponytail: satu kolom text `value`, di-cast di lapisan service" yang sama yang
  sudah ditetapkan untuk `CUSTOMFIELDS.field_values` (`ARCHITECTURE.md` §1.2) — ceiling-nya,
  jika pernah dibutuhkan, adalah kolom typed atau JSONB, bukan dibangun sekarang.

## 3C. Manajemen Mini Master / Enum (tabel sama, lensa-enum tampilan)

**Tujuan:** mengelola daftar lookup kecil dan statis sebagai data alih-alih `enum` PHP yang
di-hardcode, untuk daftar yang benar-benar cukup kecil (segelintir baris, jarang jika pernah
berubah) sehingga tabel+migration+model khusus akan berlebihan.

- Baris `config_consts` yang sama: `const_group` bertindak sebagai namespace enum (misalnya
  `const_group = 'GENDER'`), setiap anggota adalah satu baris dengan `group_code` sebagai kode
  stabilnya (`M`/`F`), `str1` sebagai label tampilannya, `str2` sebagai bentuk pendek/singkatan
  opsional, `num1`/`num2` sebagai atribut numerik opsional (misalnya bobot sort atau
  multiplier), `note` untuk deskripsi menghadap-admin, `seq` untuk urutan tampilan.
- UI menyajikan ini sebagai editor daftar terurut sederhana (tambah baris / urutkan ulang /
  nonaktifkan) — lensa yang lebih ramah di atas tabel dan engine yang persis sama seperti §3B,
  bukan skema terpisah.
- `ConfigService::getGroup(constGroup)` (§3E) adalah satu titik integrasi yang dipanggil
  komponen dropdown/select untuk me-render opsi, terlepas dari apakah kode pemanggil
  menganggap data itu "sebuah setting" atau "sebuah enum."

**Aturan / logika — batas scope eksplisit (penting, untuk mencegah scope creep nanti):**
- Mekanisme ini hanya untuk daftar **kecil, kardinalitas-rendah, sedikit-atribut, jarang
  berubah**. Sebuah lookup yang butuh atribut lebih banyak daripada yang disediakan
  `num1`/`num2`/`str1`/`str2`/`note`, butuh berpartisipasi dalam relasi foreign-key yang nyata,
  butuh banyak baris, atau tumbuh/berubah melalui penggunaan bisnis biasa tetap menjadi tabel
  yang dapat diedit tenant yang layak di skema modulnya sendiri — persis seperti yang sudah
  benar dispesifikasikan (`CRM.partner_role_types`, `LEGAL.deed_types`, `HCM.leave_types`,
  `INVENTORY.adjustment_reasons`, dan setiap tabel lookup yang dapat diedit tenant lain di
  seluruh platform). `config_consts`-sebagai-enum menggantikan `enum` PHP yang di-hardcode,
  bukan tabel master kelas-satu milik sebuah modul sendiri.
- Ini juga **bukan** tempat tabel tarif statutori ber-versi milik Payroll atau Accounting
  berada (bracket PTKP, tarif TER, persentase BPJS, bracket pajak) — itu adalah tabel yang
  benar-benar multi-kolom, ter-versi secara ketat, dan kritis-untuk-perhitungan dan dengan
  benar tetap berada di skema modul pemiliknya (`PAYROLL.ter_rate_brackets`,
  `ACCOUNTING.tax_codes`, dll.), sesuai spesifikasi modul-modul itu sendiri. `effective_date` di
  sini ada untuk setting nilai-tunggal sederhana yang kebetulan berubah sesuai jadwal
  (misalnya lead-time reminder yang flat), bukan untuk menyerap tabel-tabel itu.

## 3D. Engine Nomor Serial (`config_snums`)

**Tujuan:** memformalkan mekanisme running-number atomik yang sudah dijelaskan
`ARCHITECTURE.md` §3.2.A dan sudah dipakai `PrefixedCaseCodeGenerator` Legal.

- `ConfigSnumService::next(snumCode): string` — `SELECT ... FOR UPDATE` atomik + increment.
- Field: `snum_code` (unik, misalnya `LEGAL_CASE_LASTID`), `description`, `last_cnt` (nilai
  counter saat ini), `wrap_high` (nullable — ceiling wrap-around, sesuai perilaku "wrap pada
  `wrap_high`" yang sudah dirujuk di `ARCHITECTURE.md`), `padding_length` (lebar zero-pad),
  `reset_rule` (`never` / `yearly` / `monthly` — VARCHAR + CHECK, mereset counter pada batas
  periode).
- Layar admin (`/config/serials`, sudah dirujuk di `ARCHITECTURE.md` §3.2.A): list + koreksi
  counter manual — override manual sebuah serial berjalan dicatat (§3G), tidak pernah edit diam-
  diam.

**Aturan / logika**
- Scope locking adalah per baris `snum_code` — dua request konkuren untuk kode yang sama tidak
  pernah menerima nomor yang sama, disiplin konkurensi yang sama yang sudah diterapkan lapisan
  costing Inventory dan penomoran protokol Legal di tempat lain di platform ini.
- **Bukan** pengganti untuk penomoran ledger berskop-komposit milik modul sendiri.
  `LEGAL.protocol_entries.sequence_number` bebas-gap dalam `(book_id, year)` — scope yang lebih
  sempit dan signifikan secara hukum yang dengan benar tetap berada di dalam transaksi `LEGAL`
  sendiri, dikunci pada baris `protocol_books`-nya sendiri (`LEGAL_SPECS.md` §5), tidak
  dialihkan melalui engine generik ini. `config_snums` hanya untuk running number
  tenant/module-wide yang sederhana.

## 3E. Engine Resolusi Config (Service)

**Tujuan:** satu service yang bisa digunakan ulang yang dipanggil setiap modul lain untuk
membaca sebuah setting — API konsumsi runtime aktual di balik §3B/§3C.

- `ConfigService::get(constGroup, groupCode, ?applId, ?groupId, ?userId): mixed` — meresolusi
  baris paling spesifik yang cocok via **precedence dua-tingkat**:
  1. **Tier modul** — utamakan baris di mana `appl_id` cocok dengan modul saat ini pemanggil;
     jika tidak ada untuk `(const_group, group_code)` itu, fallback ke baris di mana `appl_id
     IS NULL` (default platform-wide yang tidak berskop modul mana pun).
  2. **Di dalam tier modul itu**, utamakan: kecocokan `user_id` > kecocokan `group_id` > tidak
     keduanya (default tingkat-modul biasa).
  - Secara konkret, untuk `LEGAL.SIGNING_REMINDER_DAYS`: baris dengan `appl_id = 'LEGAL'` dan
    `group_id`/`user_id` keduanya null menetapkan nilai untuk semua orang di modul Legal; baris
    kedua dengan `appl_id = 'LEGAL', group_id = <tim Notaris>` meng-override-nya hanya untuk
    group itu; baris ketiga dengan `appl_id = 'LEGAL', user_id = <PPAT tertentu>`
    meng-override-nya hanya untuk orang itu — ketiga baris hidup berdampingan, dan `get()`
    mengembalikan yang paling spesifik untuk user yang bertanya.
  - Mengembalikan `value` yang sudah di-cast untuk setting skalar, atau accessor berkunci ke
    `num1`/`num2`/`str1`/`str2`/`note` untuk lookup anggota enum.
- `ConfigService::getGroup(constGroup, ?applId, ?groupId, ?userId): Collection` — setiap
  anggota aktif dari sebuah `const_group`, diresolusi-scope per baris di mana override ada,
  diurutkan berdasarkan `seq` — yang dipanggil komponen dropdown/select (§3C).
- `ConfigService::set(...)` — satu-satunya jalur tulis; selalu mencatat ke `config_audit_logs`
  (§3G).
- **Caching**: hasil di-cache di Redis, dengan key
  `tenant:{db}:config:{constGroup}:{applId}:{groupId}:{userId}`, diinvalidasi pada panggilan
  `set()` mana pun yang menyentuh `const_group` itu. Ini adalah data yang read-heavy, write-rare
  (nilai seperti `LEGAL.URGENT_SETS_PENDING` mungkin diperiksa pada setiap penyimpanan kasus) —
  persis bentuk yang menjadi alasan caching Redis (sudah diprovisioning sesuai `CLAUDE.md` §3)
  ada.

**Aturan / logika**
- Resolusi tidak pernah menggabungkan sebagian dua baris (misalnya mengambil `value` dari
  override tingkat-group dan `note` dari baris default-modul) — tepat satu baris pemenang,
  utuh, sesuai precedence dua-tingkat di atas. Predictable dibanding clever.
- `group_id`/`user_id` diset **tanpa** `appl_id` (yaitu `appl_id IS NULL`) adalah kombinasi
  valid, meski kurang umum — override platform-wide untuk group atau user yang sama sekali
  tidak berskop modul. Logika dua-tingkat menanganinya secara otomatis (ia hanya hidup di tier
  fallback "tidak ada kecocokan modul"), tanpa special-casing yang dibutuhkan.

### 3F. Menu, Group & Hak Akses (Trustee)

**Tujuan:** memformalkan skema di balik infrastruktur otorisasi dan navigasi terpadu — navigasi
hierarkis multi-level, hak akses granular dengan fallback otomatis ke parent, serta caching
performa tinggi dengan Redis/Session.

- `SYSCONFIG.config_menus` — pohon menu hierarkis (`parent_id`, self-referencing ke `id` parent),
  `code`, `menu_header`, `menu_caption`, `menu_link`, `icon`, `seq`, `module_code`, `status_code`.
  - Entri modul tingkat atas memiliki `parent_id = null`. Submenu (misal `SCHEDULE_TASKS`,
    `CRM_LEADS`, `PERFORMANCE_KPIS`) memiliki `parent_id` yang mengarah ke parent menu-nya.
  - `ConfigService::menusForUser($userId)` mengembalikan tree menu dengan array `children` bersarang
    yang difilter berdasarkan hak akses dan aktivasi modul, dirender sebagai Accordion di
    `AppSidebar.vue`.
  - Seluruh tab bar horizontal di dalam halaman didepresiasi dan digantikan oleh Accordion Sidebar
    Kiri terpadu.
- `SYSCONFIG.config_groups` — role/tim (`name`, `description`). **Konsep yang sama yang dipakai baik
  untuk trustee izin-menu maupun sebagai dimensi scope `group_id` pada `config_consts` (§3B/§3E)**.
- `SYSCONFIG.config_user_groups` — pivot, `group_id` × `user_id`.
- `SYSCONFIG.config_rights` — `group_id` × `menu_code`, flag boolean C/R/U/D.
  - **Fallback Hak Akses:** Jika suatu submenu belum memiliki entri spesifik di `config_rights`,
    `ConfigService::permissionsForUserMenu()` secara otomatis memeriksa dan menurunkan hak akses
    dari menu parent-nya untuk grup user tersebut.
- **Disiplin Caching Performa:**
  - `ConfigService` menerapkan in-memory request memoization dan Redis session caching
    (`sysconfig_menus_*`, `sysconfig_perms_*`) sehingga navigasi halaman tidak melakukan query database berulang.
  - `ConfigService::clearCache()` membersihkan cache memo dan session pada setiap mutasi grup,
    menu, role user, atau saat berpindah tenant.
- Layar Admin: CRUD menu tree (`/config/menus`), CRUD grup + penetapan anggota (`/config/groups`),
  manajemen user (`/config/users`), dan matriks hak akses.

## 3G. Log Audit Config

- `SYSCONFIG.config_audit_logs` — append-only, satu baris per penulisan ke `config_consts` /
  `config_snums` / `tenant_modules` (`action`: `created` / `updated` / `deactivated` /
  `serial_corrected`), aktor, timestamp, snapshot nilai sebelum/sesudah — postur audit
  immutable yang sama seperti `dms.access_logs`, `wne.wrkflow_audit_logs`, `acct.audit_logs`,
  dan setiap log append-only lain di seluruh platform ini.
- Tidak ada update/delete yang diizinkan pada tabel ini di lapisan aplikasi, aturan yang sama
  seperti di tempat lain.

# 4. Penyimpanan

**Database (schema `SYSCONFIG`, DB tenant — konsisten dengan `CLAUDE.md` §7A; tanpa kolom
`tenant_id`, DB-per-tenant adalah batas isolasi):**

**Tabel master / config**
- `SYSCONFIG.tenant_modules` — toggle aktivasi modul (§3A).
- `SYSCONFIG.config_consts` — engine settings + mini-master/enum (§3B/§3C).
- `SYSCONFIG.config_snums` — generator nomor serial (§3D).
- `SYSCONFIG.menus` — pohon menu hierarkis (§3F).
- `SYSCONFIG.groups` — role/tim (§3F) — dibagi oleh override scope config dan trustee menu.
- `SYSCONFIG.group_members` — pivot, group × user (§3F).
- `SYSCONFIG.menu_rights` — trustee C/R/U/D, menu × group (§3F).

**Tabel log**
- `SYSCONFIG.config_audit_logs` — append-only (§3G).

**Penyimpanan file objek:** tidak diperlukan — modul ini tidak punya dokumen miliknya sendiri.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, tapi **dasar dari graf dependensi**. Tidak seperti setiap
modul Core lain (WNE, DMS, CRM, Schedule, ...), `SYSCONFIG` punya **zero dependency pada modul
lain mana pun, termasuk WNE**. Modul lain boleh opsional memicu `NotificationRequested` ke WNE
saat sebuah const sensitif berubah (jika WNE ter-install untuk tenant), tapi operasi
`SYSCONFIG` sendiri — pemeriksaan auth/permission, resolusi config, generasi serial — tidak
boleh pernah membutuhkan WNE, karena WNE (dan setiap modul selanjutnya) meresolusi
consts/permissions-nya sendiri melalui modul ini. Menjaga arah ini satu-arah menghindari
dependensi fondasional sirkular.

**Koreksi urutan pembangunan (rekomendasi mengamandemen `CLAUDE.md` §5):** Urutan pembangunan
modul Core `CLAUDE.md` §5 saat ini dimulai dengan WNE, tapi spesifikasi setiap modul sudah
mengasumsikan `config_consts`/`config_snums` ada (contoh `CASE_PREFIX`/`URGENT_SETS_PENDING`
Legal mendahului spesifikasi ini) dan bootstrap tenancy itu sendiri butuh `groups`/
`menu_rights` untuk middleware permission sejak hari pertama. `CLAUDE.md` §4/§7 sudah
mencantumkan `SYSCONFIG` pertama dalam struktur skema — bagian urutan-pembangunan di §5 hanya
belum menyusul itu. Direkomendasikan `SYSCONFIG` dibangun segera setelah sistem desain, sebelum
WNE.

**Seeding:** `TenantFlavorSeeder` (sudah dirujuk di `ARCHITECTURE.md` §1.4) adalah tempat baris
`config_consts` default per-tenant di-seed pada saat provisioning tenant — Firm A vs. Firm B
yang berbeda const-nya (`CASE_PREFIX`, `URGENT_SETS_PENDING`) adalah persis mekanisme ini, yang
sudah dipakai sebelum modul ini punya spesifikasi formal.

**Non-goal eksplisit (untuk mencegah scope creep):**
- `config_consts` hanya untuk setting skalar dan enumerasi statis kecil — tidak pernah
  pengganti umum untuk tabel master milik modul sendiri yang dapat diedit tenant (tipe role,
  tipe akta, tipe cuti, ...) atau untuk tabel tarif statutori ber-versi milik modul sendiri
  (Payroll, Accounting). Lihat batas scope di §3C.
- Modul ini tidak membangun UI untuk manajemen *plan*/billing tenant — itu tetap menjadi
  concern `tenants.plan` DB-pusat (`CLAUDE.md` §11); `tenant_modules` (§3A) hanya pernah
  mempersempit apa yang plan sudah berikan, tidak pernah mengelola plan itu sendiri.

**Konvensi casting nilai:** `value_type` (dan setiap kolom bergaya status/type lain di modul ini
— `reset_rule`, `action` pada log audit) adalah `VARCHAR` + `CHECK`, tidak pernah `ENUM`
Postgres native — sesuai konvensi platform ini sendiri yang sudah ditetapkan (menghindari
penulisan-ulang tipe yang mengganggu setiap kali nilai baru dibutuhkan, alasan yang sama yang
sudah diterapkan di setiap modul lain di codebase ini).

**Catatan kelayakan jual (marketability):** infrastruktur tak terlihat, tapi ini adalah yang
membuat janji "ini dapat diedit tenant, tidak pernah konstanta yang di-hardcode" milik setiap
modul lain benar-benar nyata di seluruh platform — layak diketahui keberadaannya sebagai
mekanisme di balik cerita itu, meskipun tidak pernah didemokan sendiri.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3F (menu/group/rights — dibutuhkan
untuk pemeriksaan permission mana pun di mana pun di platform) → 3B + 3G bersama (CRUD
config_consts, diaudit sejak penulisan pertama, tidak ditempel nanti) → 3E (service resolusi +
caching Redis — inilah yang membuka setiap spesifikasi modul lain yang sudah merujuk pada
membaca sebuah const) → 3D (config_snums) → 3A (tenant_modules) → 3C (UI lensa-enum di atas
tabel 3B yang sama, murah begitu 3B ada) — **kirim di sini** — setiap modul Core lain sekarang
bisa mengasumsikan pembangunannya sendiri bahwa `SYSCONFIG` nyata, persis seperti yang sudah
diasumsikan spesifikasi mereka.
