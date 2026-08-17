# Modul CustomFields
## Engine Entity-Attribute-Value (EAV) untuk Field Spesifik-Tenant — Modul Core Fondasional (tanpa cerita standalone; setiap modul lain bergantung padanya)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap tenant di platform ini pada akhirnya menginginkan sebuah field yang tidak dimiliki skema
inti: sebuah firma hukum menginginkan "Nomor Izin Advokat" pada Contact, seorang property
manager menginginkan "Nomor Unit" pada Contact yang sama itu, sebuah kasus Legal butuh "Nomor
Register Pengadilan" dan "Tanggal Sidang," sebuah item Inventory butuh flag "Fragile". Tangga
kustomisasi `CLAUDE.md` §2 sendiri menempatkan ini di anak tangga 3 — "utamakan anak tangga
lebih rendah dulu" — tepat di bawah Constants dan Serials, dan di atas menulis logika bespoke
per-tenant. Jika dibiarkan tidak diselesaikan sebagai mekanisme bersama, setiap modul akhirnya
menyelesaikannya secara berbeda-beda:

- Setiap modul menciptakan cerita "field ekstra"-nya sendiri — kolom blob JSON di sini,
  sekumpulan kolom nullable di sana — tidak ada validasi konsisten, tidak ada UX admin
  konsisten, tidak ada API baca/tulis konsisten.
- Sesuai tabel Do/Don't `ARCHITECTURE.md` §3.4 sendiri, anti-pola yang berusaha dicegah modul
  ini dijabarkan secara eksplisit: *"Tambahkan kolom nullable ke `LEGAL.cases` untuk satu
  firma."* Tanpa mekanisme EAV bersama, itulah persis yang akan diraih solo dev yang sedang
  tertekan — satu kolom nullable menjadi lima, menjadi lima belas, menjadi tabel yang hanya
  field-nya satu firma saja yang benar-benar diisi.
- Hampir setiap `*_SPECS.md` yang sudah ditulis untuk platform ini menjanjikan kemampuan
  konfigurasi-tenant untuk setidaknya satu entitasnya — partners/leads/tickets milik CRM,
  documents milik DMS, deeds/matters/land objects milik Legal, products milik Inventory,
  components/runs milik Payroll, dan lebih banyak lagi — dan setiap janji itu mengasumsikan
  `CUSTOMFIELDS.field_defs`/`field_values` sudah ada dan berperilaku dengan cara tertentu. Tanpa
  spesifikasi formal, janji itu tidak punya bentuk yang ditegakkan di seluruh platform — risiko
  yang sama yang ditulis `SYSCONFIG_SPECS.md` untuk ditutup bagi consts, serials, dan
  permissions.
- `ARCHITECTURE.md` sudah mendokumentasikan **contoh kerja** (custom field `court_register`/
  `hearing_date`/`priority` milik Legal, dihubungkan melalui `CustomFieldService` dan
  `CustomLogicEngine`) tapi berhenti sebelum spesifikasi modul formal — Claude Code punya pola
  untuk ditiru, bukan kontrak terdokumentasi untuk dibangun ketika modul berikutnya
  membutuhkan hal yang sama.
- CRUD admin untuk definisi field secara eksplisit ditandai **belum dibangun** di
  `ARCHITECTURE.md` §3.1 ("Admin CRUD untuk defs: belum — seed/SQL") — setiap field
  spesifik-tenant hari ini membutuhkan Simon untuk men-seed baris database secara manual. Itu
  tidak berskala melampaui satu tenant berbayar, dan itu meninggalkan poin jual nyata di atas
  meja ("tambahkan field intake Anda sendiri, tanpa tiket support dibutuhkan").

**Kebutuhan klien:**
- Satu mekanisme yang bisa digunakan ulang untuk "entitas ini punya field ekstra spesifik-
  tenant," dapat dipakai modul Core atau Vertical mana pun tanpa migration khusus per field.
- Harus mendukung tipe field yang sudah ditetapkan di `ARCHITECTURE.md` (`text` / `number` /
  `date` / `select`), dengan ruang untuk menambah lebih banyak (`boolean`, `textarea`,
  `multi-select`) sebagai pekerjaan Future Version yang aditif dan tidak mengganggu.
- Validasi field-wajib, validasi opsi-select, dan type casting harus disentralisasi — tidak
  diimplementasikan ulang per modul. "Wire pattern" `ARCHITECTURE.md` §2.3 sendiri sudah
  menunjukkan alur yang dimaksud (`formPayload` → `validateAndNormalize` → persist → `sync`);
  spesifikasi ini memformalkannya sebagai kontrak yang dibangun setiap modul.
- Harus mendukung hook custom-logic "beforeSave" yang sama yang sudah didemonstrasikan untuk
  Legal (`CustomLogicEngine::beforeSave` membaca `SYSCONFIG.config_consts` plus nilai custom
  field untuk memutasi payload inti) sebagai titik ekstensi generik per-entitas — bukan kode
  spesifik-Legal yang diam-diam hidup di dalam modul Core.
- Layar CRUD **menghadap-admin** untuk definisi field, digerbang oleh trustee menu/rights
  `SYSCONFIG` (`menu.perm:MENU_CODE`, `CLAUDE.md` §4), sehingga menambahkan field
  spesifik-tenant menjadi tugas entri-data, bukan tugas SQL/seed — ini menutup item terbuka
  yang secara eksplisit ditandai `ARCHITECTURE.md` §3.1.
- Sadar multi-tenant, isolasi DB-per-tenant yang sama seperti setiap modul lain (tanpa kolom
  `tenant_id`, sesuai `CLAUDE.md` §4/§7) — `CUSTOMFIELDS` berada di dalam setiap DB tenant,
  jadi sebuah field yang didefinisikan satu tenant tidak terlihat oleh tenant lain berdasarkan
  konstruksi, bukan berdasarkan filter query.
- Harus berada **di bawah** setiap modul Core dan Vertical dalam graf dependensi, berdampingan
  dengan `SYSCONFIG` — spesifikasi setiap modul lain sudah mengasumsikan
  `CUSTOMFIELDS.field_defs`/`field_values` ada dan berperilaku sesuai `ARCHITECTURE.md`
  §1.2/§2.3.
- Ponytail-first: satu kolom `value` tunggal (di-cast di lapisan service), tidak pernah kolom
  typed per tipe field — persis postur "ponytail: satu kolom `value`. Ceiling: kolom typed /
  JSONB jika pelaporan butuh filter berat" yang sudah dinyatakan `ARCHITECTURE.md` §1.2 untuk
  tabel ini.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — modul ini memblokir cerita kustomisasi-tenant setiap modul
> lain, jadi inti yang benar dan minimal (plus layar admin yang menutup celah "belum") lebih
> penting daripada lapisan pelaporan/aturan yang kaya sejak hari pertama.

**MVP**
- **Manajemen Definisi Field (Admin Entry).** CRUD atas `field_defs` — tipe entitas, tipe
  field, label, opsi, wajib/urutan/status — digerbang oleh trustee menu/rights `SYSCONFIG`
  yang sudah ada, menyelesaikan item terbuka "Admin CRUD untuk defs: belum" milik
  `ARCHITECTURE.md` (§3A).
- **Penangkapan Nilai Custom Field (komponen Vue bersama).** `CustomFieldInputs.vue`, satu
  komponen yang bisa digunakan ulang yang tertanam di halaman Create/Edit modul mana pun,
  me-render input yang tepat per tipe field dari satu panggilan `formPayload()` (§3B).
- **Engine Validasi & Normalisasi.** `CustomFieldService::validateAndNormalize()` — pemeriksaan
  field-wajib, pemeriksaan opsi-select, type casting, disentralisasi sehingga tidak ada modul
  yang mengimplementasikannya ulang (§3C).
- **Engine Persistensi / Sync.** `CustomFieldService::sync()` — upsert `field_values` di dalam
  transaksi yang sama seperti penyimpanan baris-inti modul pemilik (§3D).
- **Engine Read / Form Payload.** `CustomFieldService::formPayload()` — satu panggilan yang
  dilakukan Controller sebelum me-render sebuah form, mengembalikan defs yang digabung dengan
  nilai saat ini (§3E).
- **Engine Custom Logic.** `CustomLogicEngine::beforeSave()` — versi generik dari contoh
  `URGENT_SETS_PENDING` Legal yang sudah ada, membaca `SYSCONFIG.config_consts` + nilai custom
  untuk memutasi payload inti sebelum persistensi, terdaftar per tipe entitas (§3F).
- **Konvensi Registrasi Entitas.** Pola (yang secara sengaja ringan) yang diikuti modul baru
  untuk "menyalakan" custom field untuk salah satu entitasnya — tidak dibutuhkan perubahan kode
  di sisi CustomFields, murni berbasis data (§3G).
- **Log Audit Definisi Field.** Log append-only dari setiap penulisan `field_defs` — postur
  audit immutable yang sama yang sudah dipakai setiap tabel sensitif/struktural lain di
  platform ini (§3H).

**Future Version (secara eksplisit ditunda — jangan dibangun sekarang)**
- **Ceiling kolom typed / JSONB** untuk entitas yang custom field-nya butuh filter pelaporan
  berat — ceiling yang sudah disebut eksplisit `ARCHITECTURE.md` §1.2; tidak dibutuhkan sampai
  kebutuhan pelaporan tenant tertentu benar-benar menuntutnya.
- **Visibilitas/aturan dependensi field kondisional** (tampilkan Field B hanya jika Field A =
  X) — kenikmatan UX nyata, tidak menghambat untuk daftar field datar MVP.
- **Field multi-select / multi-value** — akan butuh tabel nilai anak alih-alih kolom `value`
  tunggal; ditunda sampai kebutuhan konkret muncul, karena ini mengubah bentuk `field_values`,
  bukan hanya menambah tipe field.
- **Definisi field ber-versi** (pola efektif-tanggal seperti tabel tarif statutori milik
  Payroll/Accounting) — definisi custom field berubah jauh lebih jarang dan dengan taruhan
  kepatuhan jauh lebih rendah daripada bracket pajak; belum sepadan dengan kompleksitasnya
  sekarang.
- **Izin tingkat-field** (terlihat/dapat-diedit per role, bukan hanya per entitas via gerbang
  kasar menu.perm) — penyempurnaan RBAC nyata, ditunda berdampingan dengan bias MVP
  "flag akses tingkat-folder/dokumen sekarang, ACL granular nanti" milik setiap modul lain
  (postur yang sama yang diterapkan DMS pada flag akses foldernya sendiri).
- **Pelaporan/query builder custom-field lintas-entitas** — cocok alami untuk pola "ask your
  data" **AIInsights Core** begitu modul itu rilis, alih-alih report builder bespoke yang
  dibangun di sini lebih dulu.
- **Impor/ekspor definisi field (JSON)** — mengklon konfigurasi custom-field seorang tenant ke
  tenant baru (misalnya "beri Firm B field intake yang sama yang sudah dimiliki Firm A") —
  berguna begitu ada tenant vertikal-Legal kedua yang menjadikannya layak dibangun.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> database.

## 3A. Manajemen Definisi Field (Entry)

**Tujuan:** layar admin yang menutup celah "belum" `ARCHITECTURE.md` — memungkinkan admin
tenant (atau Simon) menambahkan custom field tanpa perubahan SQL/seed.

- Field: `entity_type` (kunci registrasi yang sudah dipakai modul pemilik secara internal,
  misalnya `legal_case`, `inventory_item` — teks bebas di DB, tapi UI menawarkan dropdown yang
  dibangun dari nilai-nilai berbeda yang sudah dipakai, untuk mencegah typo alih-alih
  memblokirnya), `module_code` (lookup nullable, ruang kode yang sama dengan
  `SYSCONFIG.tenant_modules.module_code` — dipakai murni untuk filtering/scoping layar admin,
  misalnya "tampilkan semua field Legal," dan untuk menggerbang siapa yang bisa mengedit field
  mana via trustee menu/rights), `code` (key stabil, misalnya `court_register`), `label`
  (label UI), `field_type` (`text` / `number` / `date` / `select` — `VARCHAR` + `CHECK`, bukan
  enum Postgres native, sesuai konvensi "status/type sebagai VARCHAR + CHECK" yang sudah
  ditetapkan platform ini sendiri), `options` (JSON, khusus select — `[{label, value}]`),
  `is_required`, `seq` (urutan form), `status` (`active`/`inactive`).
- Tampilan list: dapat difilter/dikelompokkan berdasarkan `module_code` lalu `entity_type` —
  UX filter-berdasarkan-namespace yang sama yang sudah dipakai list admin
  `SYSCONFIG.config_consts` sendiri (`SYSCONFIG_SPECS.md` §3B), sehingga kedua layar
  "konfigurasi yang dapat diedit tenant" di platform ini terasa sebagai satu keluarga UI, bukan
  dua.
- Entri baris: form sederhana, editor opsi (tambah/hapus pasangan label-value) ditampilkan
  hanya saat `field_type = select`.

**Aturan / logika**
- Uniqueness ditegakkan pada `(entity_type, code)`, sesuai `ARCHITECTURE.md` §1.2.
- Menonaktifkan (`status = inactive`) adalah jalur penghapusan default, tidak pernah hard
  delete — definisi field yang dinonaktifkan berhenti muncul di form baru tapi `field_values`
  historisnya tetap terbaca, postur non-destruktif yang sama seperti setiap modul lain di
  platform ini.
- Setiap penulisan dicatat ke `field_def_audit_logs` (§3H) — perubahan definisi field bisa
  memengaruhi setiap record `entity_type` itu di seluruh platform, jadi butuh
  rekonstruktabilitas yang sama seperti perubahan const `SYSCONFIG`.

## 3B. Penangkapan Nilai Custom Field (Komponen)

**Tujuan:** satu komponen Vue yang bisa digunakan ulang, tertanam di halaman Create/Edit modul
mana pun — tidak pernah diimplementasikan ulang per modul, sesuai peta modul `ARCHITECTURE.md`
§2.1 (`resources/js/Components/forms/CustomFieldInputs.vue`, digabungkan ke misalnya
`resources/js/Pages/Legal/Cases/{Create,Edit}.vue`).

- Menerima output `formPayload()` (§3E) sebagai props — defs field aktif untuk tipe entitas,
  diurutkan berdasarkan `seq`, digabung dengan nilai saat ini jika sedang mengedit — dan
  me-render satu input per def.
- Tipe input dipetakan ke `field_type` melalui set primitive `DESIGN.md` yang sudah ada:
  `text` → Input, `number` → Input (numerik), `date` → Date/time picker, `select` →
  Select/Combobox.
- Feedback field-wajib sisi-klien mencerminkan aturan sisi-server untuk responsivitas, tapi
  validasi server (§3C) selalu otoritatif — validasi hanya-klien tidak pernah dipercaya,
  disiplin yang sama yang sudah diikuti setiap Form Request di codebase ini (`CLAUDE.md` §6).

## 3C. Engine Validasi & Normalisasi

`CustomFieldService::validateAndNormalize(entityType, customFieldsInput): array`

- Memuat `field_defs` aktif untuk `entityType`, memeriksa setiap field `is_required` hadir,
  memeriksa nilai `select` terhadap `options` def, meng-cast setiap nilai sesuai `field_type`
  (number → numeric, date → date, text/select → string), dan mengembalikan array ternormalisasi
  yang berkunci `field_def_id`.
- Dipanggil oleh Service modul pemilik sendiri (misalnya `LegalCaseService::create`/`update`) —
  CustomFields dipanggil *ke dalam*, tidak pernah menjangkau ke logika domain modul pemanggil
  sendiri, aturan dependensi satu-arah yang sama seperti di tempat lain di platform ini
  (`CLAUDE.md` §2/§9).
- Field wajib yang hilang, atau nilai `select` di luar `options` def, gagal validasi dengan
  pesan spesifik-field yang ditampilkan kembali melalui Form Request modul pemanggil sendiri —
  suara "error menyatakan apa yang terjadi dan apa yang harus dilakukan selanjutnya" yang sama
  seperti `DESIGN.md` §5.

## 3D. Engine Persistensi / Sync

`CustomFieldService::sync(entityType, entityId, normalizedValues): void`

- Upsert baris `field_values` (`field_def_id`, `entity_type`, `entity_id`, `value`) sesuai
  constraint unik di `ARCHITECTURE.md` §1.2 (`field_def_id, entity_type, entity_id`), dan
  menghapus baris untuk def mana pun yang tidak hadir di payload yang dikirim (field yang secara
  eksplisit dikosongkan kembali) — tidak pernah meninggalkan nilai basi yang diam-diam masih
  melekat pada sebuah record.
- Dieksekusi di dalam **transaksi database yang sama** sebagai INSERT/UPDATE baris-inti modul
  pemilik, sesuai urutan yang sudah didiagramkan `ARCHITECTURE.md` §2.4
  (`Svc→DB: INSERT/UPDATE LEGAL.cases` langsung diikuti `Svc→CF: sync`) — penulisan custom
  field tidak pernah bisa berhasil sebagian terhadap record-nya sendiri.

**Aturan / logika**
- Menghapus baris entitas inti tidak melakukan cascade-delete `field_values`-nya di MVP —
  lihat §5 untuk alasannya dan catatan Future Version. Sebagian besar modul di platform ini
  lebih memilih soft-delete/deactivate dibanding hard delete (sesuai spesifikasi masing-masing
  modul), yang membatasi seberapa sering ini benar-benar menjadi masalah hari ini.

## 3E. Engine Read / Form Payload

`CustomFieldService::formPayload(entityType, entityId = null): array`

- Mengembalikan setiap baris `field_defs` aktif untuk `entityType` (diurutkan berdasarkan
  `seq`), digabung dengan `field_values` saat ini untuk `entityId` jika sedang mengedit
  (kosong/default jika membuat baru) — satu panggilan yang dilakukan Controller sebelum
  me-render sebuah form, sesuai wire pattern di `ARCHITECTURE.md` §2.3
  (`Controller → formPayload(entity_type, id?)`).
- Read-only, aman dipanggil di setiap page load; tanpa mutasi state.

## 3F. Engine Custom Logic (hook beforeSave)

`CustomLogicEngine::beforeSave(entityType, data, customValues): array`

- Versi umum dari contoh Legal yang sudah ada (`ARCHITECTURE.md` §3.2.B —
  const `LEGAL.URGENT_SETS_PENDING` + custom field `priority = urgent` + field inti
  `status = open` → paksa `status = pending`): membaca `SYSCONFIG.config_consts` via
  `ConfigService::get()` (`SYSCONFIG_SPECS.md` §3E, selalu sebuah **baca**, tidak pernah
  tulis, ke `SYSCONFIG`) plus nilai custom field yang baru divalidasi, dan bisa memutasi
  payload inti sebelum dipersistensikan.
- Aturan terdaftar per `entityType`. Scope MVP adalah logika kondisional datar — rantai
  match/if kecil per tipe entitas, sesuai postur `ARCHITECTURE.md` sendiri yang dinyatakan
  untuk engine tepat ini: *"ponytail: if datar di engine. Ceiling: kelas Rule yang bisa
  dipasang-cabut saat aturan berkembang biak."* Refactor Strategy/Rule-class adalah Future
  Version, hanya begitu jumlah aturan tipe entitas tertentu benar-benar tumbuh tidak terkendali
  — bukan abstraksi hari-pertama.

**Aturan / logika**
- `CustomLogicEngine` tidak pernah meng-query atau menjangkau ke tabel modul pemanggil
  sendiri — inputnya hanya string `entityType`, payload inti, dan nilai custom field. Ini
  adalah yang menjaga postur zero-knowledge-of-Vertical (dan zero-knowledge-of-modul-Core-lain
  mana pun) milik CustomFields tetap utuh — `ARCHITECTURE.md` §2.2 menyatakan ini secara
  eksplisit: *"CustomFields (Core) ... Tidak boleh mengimpor model Legal."*

## 3G. Konvensi Registrasi Entitas

**Tujuan:** bagaimana modul baru "menyalakan" custom field untuk salah satu entitasnya —
secara sengaja bukan langkah registrasi formal, untuk menjaga onboarding tipe entitas baru
tetap murah.

- **Tidak ada** tabel master/lookup `entity_types`. `entity_type` adalah konstanta string
  biasa yang sudah dipakai lapisan Service modul pemilik secara internal (misalnya
  `legal_case`, `inventory_item`, `hcm_employee`), persis cocok dengan kolom
  `field_defs.entity_type` milik `ARCHITECTURE.md` §1.2. Menyalakan custom field untuk entitas
  baru adalah dua langkah: (1) seed baris `field_defs` untuk `entity_type` itu (via layar
  Admin, §3A, atau seeder untuk default awal), dan (2) panggil `formPayload()` /
  `validateAndNormalize()` / `sync()` dari Service modul pemilik sendiri, sesuai wire pattern
  di §2.3/§2.4 `ARCHITECTURE.md`. Tidak dibutuhkan perubahan kode CustomFields sendiri untuk
  onboarding `entity_type` baru — ini secara sengaja berbasis data, bukan berbasis registry,
  sesuai bias low-ceremony yang sudah dimiliki anak tangga kustomisasi ini.
- Trait `HasCustomFields` (atau helper Service tipis yang setara) adalah shortcut implementasi
  yang disarankan untuk model/service modul sendiri — bukan requirement keras, hanya cara yang
  biasa dan konsisten untuk menghindari menulis-ulang tiga panggilan yang sama per modul. Layak
  diformalkan begitu setidaknya dua modul benar-benar sudah memakai pola ini, sehingga trait
  itu mencerminkan penggunaan nyata alih-alih bentuk spekulatif (lihat urutan pembangunan yang
  disarankan §5).

## 3H. Log Audit Definisi Field

- `CUSTOMFIELDS.field_def_audit_logs` — append-only, satu baris per penulisan ke `field_defs`
  (`action`: `created` / `updated` / `deactivated`), aktor, timestamp, snapshot nilai
  sebelum/sesudah — postur audit immutable yang sama yang sudah diterapkan setiap modul lain
  di platform ini pada perubahan struktural/sensitifnya sendiri (`dms.access_logs`,
  `wne.wrkflow_audit_logs`, `acct.audit_logs`, `sysconfig.config_audit_logs`). Tidak ada
  update/delete yang diizinkan pada tabel ini di lapisan aplikasi, aturan yang sama seperti di
  tempat lain.

---

# 4. Penyimpanan

> Tabel dan file objek yang dipakai modul ini. Schema: `CUSTOMFIELDS` (per DB tenant, sesuai
> `CLAUDE.md` §7A). Tanpa kolom `tenant_id` — DB-per-tenant adalah batas isolasi, sama seperti
> setiap modul lain.

**Tabel inti**
- `CUSTOMFIELDS.field_defs` — field apa saja yang ada per entitas: `id`, `uuid`
  (menghadap-eksternal), `entity_type`, `module_code` (nullable, hanya filter/scope admin),
  `code`, `label`, `field_type` (`text`/`number`/`date`/`select`), `options` (JSON, khusus
  select), `is_required`, `seq`, `status`, unik pada `(entity_type, code)`.
- `CUSTOMFIELDS.field_values` — nilai per baris entitas: `id`, `field_def_id` (link
  ditegakkan-aplikasi ke def, sesuai `ARCHITECTURE.md` §1.2), `entity_type`, `entity_id` (PK
  baris pemilik — bukan FK yang ditegakkan, karena tabel target bervariasi per `entity_type`,
  disiplin referensi-ditegakkan-aplikasi yang sama yang dipakai platform-wide untuk pointer
  `subject_type`/`subject_id`), `value` (text, di-cast sesuai `field_type` di lapisan service),
  unik pada `(field_def_id, entity_type, entity_id)`.

**Tabel log**
- `CUSTOMFIELDS.field_def_audit_logs` — append-only (§3H).

**Penyimpanan file objek:** tidak ada — modul ini hanya menyimpan data terstruktur, tanpa
dokumen. Modul mana pun yang ingin melampirkan sebuah *file* sebagai nilai custom field
seharusnya mengalihkannya melalui **DMS** (`DocumentService::attach()`, `subject_type` =
record pemilik) alih-alih menyimpan referensi file sebagai string `field_values.value` —
CustomFields tidak mengimplementasikan penanganan file, disiplin reuse yang sama seperti di
tempat lain di platform ini.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, di **dasar graf dependensi** berdampingan dengan `SYSCONFIG` —
`app/Modules/CustomFields/` (`Models/FieldDef.php`, `Models/FieldValue.php`,
`Services/CustomFieldService.php`, `Services/CustomLogicEngine.php`), sesuai peta modul
`ARCHITECTURE.md` §2.1. CustomFields punya **zero dependency** pada WNE, DMS, CRM, atau modul
lain mana pun, dengan satu pengecualian sempit: `CustomLogicEngine` **membaca** (tidak pernah
menulis) `SYSCONFIG.config_consts` via `ConfigService::get()` — postur read-only yang sama
yang sudah dimiliki setiap modul lain terhadap `SYSCONFIG` (catatan "zero dependency ...
termasuk WNE" `SYSCONFIG_SPECS.md` §5 berlaku di sini secara identik, satu tingkat lebih tinggi
di tumpukan).

**Batas modul (langsung dari `ARCHITECTURE.md` §2.2, dinyatakan ulang sebagai kontrak modul
ini sendiri):**

| Layer | Memiliki | Tidak boleh |
|-------|------|----------|
| CustomFields (Core) | Tabel EAV + engine validasi/persistensi/logika generik | Mengimpor model modul Vertical atau modul Core lain |
| Modul pemilik mana pun (Legal, CRM, Inventory, ...) | Service domain, kolom tabel-intinya sendiri, memanggil wire pattern di bawah | Menaruh data EAV-nya sendiri di skemanya sendiri (itu persis anti-pola yang berusaha dicegah modul ini) |
| `SYSCONFIG` | Consts/menu/rights yang dibaca `CustomLogicEngine` | Mengetahui CustomFields ada |

**`entity_type` vs. `subject_type`/`subject_id` — perbedaan yang sengaja mudah tertukar.**
Hampir setiap spesifikasi di platform ini memakai pointer polimorfik `subject_type`/
`subject_id` (workflow instances WNE, attachment DMS, `svc_cases` CRM, `so_hdrs` Sales, dan
lebih banyak lagi) untuk merujuk *record berbeda di modul berbeda*, untuk keterkaitan lintas-
modul yang longgar. `entity_type` modul ini terlihat mirip (referensi string longgar, tanpa FK
yang ditegakkan) tapi menyelesaikan masalah yang berbeda: ini adalah **kunci registrasi** yang
dipilih modul pemilik untuk tipe record-*nya sendiri*, diresolusi sepenuhnya di dalam lapisan
Service modul itu sendiri, untuk berarti "jenis record ini menerima custom field." Kedua
konvensi itu tidak pernah menunjuk satu sama lain dan tidak boleh tertukar saat membaca
spesifikasi modul lain.

**Wire pattern (otoritatif — setiap modul mengimplementasikan urutan persis ini, sesuai
`ARCHITECTURE.md` §2.3/§2.4):**
```text
Controller
  → formPayload(entity_type, id?)
Service create/update
  → validateAndNormalize(custom_fields)
  → (optional) domain helpers (e.g. a code generator)
  → CustomLogicEngine::beforeSave
  → persist core row
  → sync(entity_type, id, values)
Service delete
  → deleteFor → delete core row
```

**Ceiling, dinyatakan ulang dari `ARCHITECTURE.md` §1.2:** `field_values.value` adalah satu
kolom text nullable tunggal, di-cast sesuai `field_type` di lapisan service — secara sengaja
bukan kolom typed. Jika kebutuhan pelaporan tenant akhirnya membutuhkan filtering/sorting berat
pada satu custom field tertentu (misalnya "daftar setiap kasus Legal di mana `court_register` >
X"), ceiling-nya adalah baik mempromosikan field itu menjadi kolom sungguhan di tabel-inti
modul pemilik sendiri (migration aditif biasa, disiplin yang sama yang sudah diterapkan setiap
modul lain pada skemanya sendiri) atau proyeksi `JSONB` — bukan mendesain-ulang modul ini.

**Catatan hard-delete:** MVP tidak melakukan cascade-delete `field_values` saat baris inti
di-hard-delete. Ini secara sengaja prioritas rendah: hampir setiap modul di platform ini sudah
lebih memilih soft-delete/deactivate dibanding hard delete (sesuai spesifikasi masing-masing
modul — CRM tidak pernah hard-delete Partner, Legal tidak pernah hard-delete Deed, HCM tidak
pernah hard-delete Employee), yang membatasi seberapa sering kasus orphan ini benar-benar
terjadi dalam praktik. Job cleanup, atau on-delete-cascade aditif begitu ada modul konkret
yang mampu hard-delete, adalah tambahan Future Version yang murah — belum sepadan dengan
kompleksitasnya sekarang.

**Opsi select sebagai JSON, bukan tabel anak:** bias "ponytail" yang sama seperti sisa modul
ini — tabel kosakata-terkendali hanya sepadan biayanya jika opsi butuh terjemahan, urutan
frekuensi-penggunaan, atau berbagi lintas-tenant, tidak satu pun dari itu adalah requirement
saat ini.

**Caching:** `field_defs` read-heavy, write-rare — bentuk yang sama yang sudah di-cache
`SYSCONFIG.config_consts` (`SYSCONFIG_SPECS.md` §3E). `formPayload()` bisa menggunakan ulang
konvensi Redis yang identik, dengan key `tenant:{db}:customfields:defs:{entity_type}`,
diinvalidasi pada penulisan `field_defs` mana pun untuk tipe entitas itu — optimasi performa,
bukan requirement untuk kebenaran MVP pada volume data yang diharapkan.

**Koreksi urutan-pembangunan yang disarankan (rekomendasi mengamandemen `CLAUDE.md` §5, gaya
koreksi yang sama yang sudah dibuat `SYSCONFIG_SPECS.md` §5 untuk dirinya sendiri):**
`CLAUDE.md` §5 saat ini mencantumkan CustomFields *setelah* WNE, DMS, CRM, dan Schedule dalam
urutan pembangunan modul Core — tapi Metadata Management milik DMS (`DMS_SPECS.md` §2 MVP:
"custom field yang didefinisikan tenant, menggunakan-ulang pola skema `CUSTOMFIELDS` yang
sudah ada") dan Custom Fields milik CRM (`CRM_SPECS.md` §2/§4) keduanya sudah mengasumsikan
`CUSTOMFIELDS.field_defs`/`field_values` ada sebagai bagian dari ship MVP *mereka sendiri*.
Direkomendasikan CustomFields dibangun segera setelah `SYSCONFIG` — dua modul fondasional,
zero-downstream-dependency, dibangun bersama, sebelum WNE — sehingga spesifikasi setiap modul
Core selanjutnya menemukan infrastruktur nyata alih-alih infrastruktur yang diasumsikan.

**Setiap rujukan "menggunakan-ulang pola skema CUSTOMFIELDS yang sudah ada" di seluruh
`*_SPECS.md` lain di platform ini** (DMS, CRM, Legal, Inventory, Payroll, dan lain-lain)
menjelaskan persis mekanisme yang dispesifikasikan di sini — tidak ada implementasi EAV
terpisah per-modul di mana pun di codebase ini.

**Catatan kelayakan jual (marketability)**
- "Tambahkan field intake Anda sendiri, tanpa deploy kode, tanpa tiket support" adalah
  diferensiator nyata dan bisa didemokan untuk audiens pembeli legal konservatif yang sama
  yang ditargetkan `DESIGN.md` — begitu layar Admin CRUD (§3A) rilis, ini menutup celah "belum"
  `ARCHITECTURE.md` dan menjadi cerita konfigurabilitas yang bisa dijual, mencerminkan pitch
  kepercayaan/konfigurabilitas yang sama yang sudah diceritakan const yang dapat diedit tenant
  milik `SYSCONFIG`.
- Menjadi infrastruktur fondasional yang tak terlihat (seperti `SYSCONFIG`) berarti modul ini
  tidak pernah didemokan sendiri — kelayakan jualnya adalah apa yang *dimungkinkannya* dalam
  demo setiap modul lain sendiri ("ya, Anda bisa menambahkan field Anda sendiri untuk itu"),
  bukan layar miliknya sendiri.

**Urutan pembangunan yang disarankan untuk Claude Code:** skema dulu (`field_defs` /
`field_values` / `field_def_audit_logs` — belum butuh UI, cukup untuk membuka pembangunan
setiap modul lain sendiri) → 3C/3D/3E (`validateAndNormalize` / `sync` / `formPayload` — trio
yang benar-benar dibutuhkan setiap modul pemanggil) → 3B (`CustomFieldInputs.vue`, komponen
bersama) → 3F (`CustomLogicEngine`, tipis di awal — aturan senilai satu tipe entitas, misalnya
contoh `URGENT_SETS_PENDING` Legal yang sudah ada, sudah cukup untuk membuktikan pola) → 3G
(memformalkan trait/konvensi registrasi `HasCustomFields` begitu setidaknya dua modul
benar-benar sudah memakainya, sehingga mencerminkan penggunaan nyata alih-alih spekulasi) →
3A (layar Admin CRUD — tidak menghambat untuk satu-dua modul pertama, karena seeding manual
berfungsi baik pada awalnya, tapi deliverable konkret yang menghapus workaround SQL/seed
secara permanen) — kirim di sini — lalu tinjau ulang item Future Version (ceiling JSONB,
visibilitas kondisional, multi-select, defs ber-versi) hanya begitu kebutuhan tenant nyata
muncul.
