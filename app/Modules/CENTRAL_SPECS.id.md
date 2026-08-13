# Modul CENTRAL
## Registry Tenant Platform, Billing Langganan & Tata Kelola Akses — Modul Fondasional Tingkat Platform (berada di DB Pusat, di luar batas tenant mana pun)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

`ARCHITECTURE.md` dan `CLAUDE.md` §4 sudah menetapkan bahwa DB Pusat (`nusaevo`) ada, menyimpan
`tenants`, `tenant_user_lookups`, dan `tenants.plan` — tetapi sebagai tiga tabel yang
dirujuk-tapi-belum-dispesifikasikan. Itu adalah celah "infrastruktur yang sudah diasumsikan ada
oleh semua orang" yang sama seperti yang ditutup `SYSCONFIG_SPECS.md` untuk menu/consts/serial
dan `CUSTOMFIELDS_SPECS.md` untuk EAV — hanya saja satu tingkat lebih bawah lagi dari tumpukan.
`SYSCONFIG` memformalkan "apa yang diasumsikan ada di dalam setiap DB tenant"; **`CENTRAL`
memformalkan apa yang harus ada *sebelum* sebuah DB tenant ada sama sekali.** `CLAUDE.md` §11
sudah menandai celah ini secara eksplisit: *"Billing langganan SaaS tenant (bagaimana platform
sendiri menagih tiap tenant untuk plan mereka) — string `tenants.plan` sudah ada di DB pusat;
belum ada integrasi payment provider."*

Jika dibiarkan tidak diformalkan, ini mengulangi anti-pola yang sama yang berusaha dicegah oleh
setiap `*_SPECS.md` lain di platform ini — kecuali di sini biaya kesalahannya bersifat
eksistensial, bukan sekadar arsitektural, karena inilah mekanisme yang mengubah platform dari
sekadar software menjadi bisnis berbayar:

- **Tidak ada record tenant yang formal.** `tenants` dirujuk di mana-mana (resolusi tenant
  berbasis session per `CLAUDE.md` §4, `TenantFeatureService`, `stancl/tenancy`) tetapi bentuk
  sebenarnya — status provisioning, referensi DB, status billing — belum pernah
  dispesifikasikan.
- **Tidak ada entitlement modul berbasis data.** `config/tenant_modules.php` adalah file
  config PHP statis per tier plan (`CLAUDE.md` §4). Itu cukup baik untuk "apa saja yang
  termasuk dalam plan Starter," tapi tidak ada record modul yang benar-benar *disubscribe*
  oleh tenant *tertentu*, add-on yang dibeli secara à la carte (beberapa modul di platform ini —
  DMS, Schedule, Inventory, Accounting, Performance, Purchase — secara eksplisit
  dispesifikasikan sebagai item lini yang dapat dijual mandiri, sesuai catatan Marketability
  masing-masing spesifikasi), atau perubahan plan seiring waktu.
- **Tidak ada billing SaaS sama sekali.** Simon (pemilik platform) tidak punya cara untuk
  menagih tenant atas langganan mereka, melacak apa yang harus dibayar, atau merekonsiliasi
  pembayaran — celah terbesar tunggal dalam "ini adalah produk SaaS multi-tenant yang
  disewakan" (`CLAUDE.md` §1).
- **Transfer bank manual adalah metode pembayaran SMB dominan di pasar ini.** Sedikit tenant
  SMB Indonesia di tahap awal yang punya kartu korporat atau payment gateway terintegrasi
  sejak hari pertama — alur MVP yang realistis adalah "tenant transfer ke rekening bank Simon,
  unggah bukti pembayaran, Simon mengonfirmasi," bukan payment gateway langsung (bias
  "manual-dulu, gateway-belakangan" yang sama seperti yang sudah diterapkan pada item Future
  Version Payment Gateway milik `SALES_SPECS.md` sendiri dan MVP ekspor-file-bank milik
  `PAYROLL_SPECS.md`).
- **Tidak ada konsekuensi untuk keterlambatan pembayaran.** Seorang tenant bisa berjalan tanpa
  batas waktu tanpa membayar hari ini, tanpa pengingat, tanpa soft cutoff, dan tanpa sinyal
  operasional yang jelas kepada Simon tentang siapa yang menunggak — risiko kebocoran
  pendapatan nyata bagi bisnis yang dijalankan solo dev tanpa tim billing untuk mengejar ini
  secara manual.
- **Modul ini tidak boleh pernah bergantung pada apa pun di dalam DB tenant.** WNE, DMS, CRM,
  SYSCONFIG, dan setiap modul yang sudah dispesifikasikan sejauh ini semuanya mengasumsikan DB
  tenant sudah ada dan berada *di dalamnya*. `CENTRAL` adalah lapisan yang membuat dan mengatur
  DB-DB itu — modul inilah satu-satunya di platform ini yang harus sepenuhnya mandiri, dengan
  **tanpa dependensi sama sekali pada modul mana pun yang terlingkup-tenant**, postur "zero
  dependency" yang sama yang diklaim `SYSCONFIG_SPECS.md` §5 untuk dirinya sendiri, dibawa
  satu tingkat lebih jauh: `SYSCONFIG` tidak punya dependensi ke modul *tenant* lain, tapi ia
  tetap berada *di dalam* sebuah DB tenant dan butuh DB itu ada lebih dulu. `CENTRAL` bahkan
  tidak punya hal itu.

**Kebutuhan klien:**
- Registry pusat setiap tenant: info perusahaan/kontak, status provisioning, referensi DB
  tenant, plan saat ini.
- Konfigurasi modul/plan per-tenant, berbasis data — bukan hanya file config plan-tier
  statis — sehingga modul yang benar-benar disubscribe tenant tertentu (plan dasar + add-on
  à la carte) adalah data nyata yang bisa di-query, bukan sekadar inferensi dari string plan.
- Billing langganan berulang: satu invoice per tenant per siklus billing, dirinci per biaya
  plan + biaya modul add-on mana pun.
- Alur konfirmasi pembayaran manual: tenant (atau Simon, atas nama mereka) mengunggah bukti
  pembayaran; Simon meninjau dan mengonfirmasi atau menolak.
- Pengingat otomatis sebelum/pada/setelah tanggal jatuh tempo, dan **soft cutoff dengan hari
  yang dapat dikonfigurasi** ke mode read-only jika tenant tidak membayar melewati jendela itu
  — protektif bagi bisnis tanpa bersifat hukuman: data tenant tidak pernah disentuh, akses
  hanya menurun menjadi read-only sampai pembayaran dilanjutkan, lalu kembali otomatis.
- **Tidak boleh** menjadi sistem Accounting kedua — tidak ada GL double-entry, tidak ada
  engine PPN/PPh, tidak ada Faktur Pajak — hanya disiplin pelacakan invoice/pembayaran yang
  cukup untuk operasional platform *sendiri*. Lihat §5 untuk disambiguasi eksplisit dari modul
  Accounting yang menghadap tenant.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — ini adalah infrastruktur prasyarat agar bisnis dapat
> berfungsi sebagai produk berbayar sama sekali, jadi inti yang benar dan minimal lebih
> penting daripada lapisan revenue-ops yang kaya sejak hari pertama.

**MVP (bangun pertama — inilah yang membuat platform menjadi bisnis, bukan sekadar software)**
- **Registry & Provisioning Tenant** (§3B) — record `tenants` kanonik, status provisioning,
  dan titik pemicu masuk ke alur pembuatan DB aktual milik `stancl/tenancy`.
- **Konfigurasi Plan & Entitlement Modul** (§3C/§3D) — katalog plan ditambah modul add-on
  per-tenant, diresolusi menjadi entitlement yang hanya bisa *dipersempit*, tidak pernah
  diperlebar, oleh `SYSCONFIG.tenant_modules` (toggle visibilitas per-tenant,
  `SYSCONFIG_SPECS.md` §3A).
- **Engine Billing / Invoice** (§3E) — menghasilkan satu baris `central_invoices` per tenant
  per siklus billing, dirinci dari plan + add-on yang sedang berlaku.
- **Penangkapan & Konfirmasi Pembayaran, termasuk unggah bukti** (§3F) — alur bukti
  pembayaran manual yang ringan: tenant mengunggah bukti, Simon mengonfirmasi atau menolak.
- **Engine Dunning — Pengingat & Soft Cutoff** (§3G) — job terjadwal yang memicu pemberitahuan
  pengingat pada jadwal hari yang dapat dikonfigurasi relatif terhadap tanggal jatuh tempo,
  dan membalik tenant ke akses `read_only` setelah sejumlah hari yang dapat dikonfigurasi
  melewati jatuh tempo jika masih belum dibayar; kembali ke `active` otomatis begitu
  pembayaran dikonfirmasi.
- **Layar Billing Menghadap Tenant** (§3H) — satu permukaan kecil yang sengaja dibatasi
  ruang lingkupnya yang dilihat admin tenant yang login *di dalam aplikasi tenant mereka
  sendiri* untuk melihat invoice dan mengirim bukti pembayaran, bahkan saat sedang
  `read_only`.
- **Dashboard Admin Central** (§3A) — gambaran operasional Simon sendiri: tenant berdasarkan
  status, pendapatan periode ini, tenant yang menunggak, perpanjangan yang akan datang,
  tinjauan pembayaran yang tertunda.
- **Log Audit Central** (§3I) — catatan append-only dari setiap perubahan registrasi,
  entitlement, invoice, tinjauan pembayaran, dan status akses, dengan postur audit immutable
  yang sama seperti yang sudah diterapkan setiap modul lain di platform ini untuk data
  sensitif mereka sendiri.

**Future Version (secara eksplisit ditunda — jangan dibangun sekarang)**
- **Integrasi payment gateway** (Xendit/Stripe/rel lokal) untuk pembayaran online self-serve
  tenant — MVP hanya transfer bank manual + unggah bukti, mencerminkan postur
  gateway-yang-ditunda milik `SALES_SPECS.md` dan `PAYROLL_SPECS.md` sendiri.
- **Signup/upgrade/downgrade self-service** — MVP digerakkan oleh admin (Simon membuat dan
  menyesuaikan tenant); alur self-serve adalah fast-follow alami begitu ada permintaan
  inbound nyata.
- **Billing berbasis penggunaan/metered** (per-seat, per-volume-transaksi) — MVP hanya
  harga flat per tier plan.
- **`central_tenant_module_grants`** — override entitlement per-tenant yang dilapisi di atas
  plan + add-on (misalnya comping fitur beta, membatasi waktu trial AIInsight untuk satu
  tenant tanpa deploy kode). Ini adalah tabel yang sama yang sudah dirujuk-maju sendiri oleh
  bagian Future Version `SYSCONFIG_SPECS.md` (`central.tenant_module_grants`) — tetap ditunda
  di sini juga, untuk alasan yang sama: bangun hanya begitu ada kebutuhan komersial nyata
  untuk override per-tenant di luar plan+add-on yang benar-benar muncul. Skema di §3C dibuat
  sedemikian rupa sehingga ini menjadi tabel tambahan murni nanti, bukan desain ulang.
- **Provisioning end-to-end otomatis** — MVP: pembayaran yang dikonfirmasi (atau override
  admin untuk tenant comped/trial) memicu langkah provisioning semi-otomatis yang ditinjau
  Simon sekali per tenant baru, karena volume cukup rendah sehingga ini belum menjadi
  bottleneck. *Hook* provisioning (§3B) dirancang agar ini bisa menjadi sepenuhnya otomatis
  nanti tanpa perubahan skema.
- **Periode uji coba gratis** — MVP tidak punya konsep trial; tenant baik diprovisioning
  terhadap plan berbayar/comped atau tidak diprovisioning sama sekali. Trial bersifat aditif
  (kolom `trial_ends_at` + varian kebijakan dunning) begitu ada alasan go-to-market konkret
  untuk menawarkannya.
- **Hard cutoff / suspensi / ekspor & penghapusan data** setelah non-pembayaran
  berkepanjangan — MVP hanya pernah mencapai `read_only`. Memutuskan apa yang terjadi setelah
  berminggu-minggu atau berbulan-bulan non-pembayaran berkelanjutan (kewajiban retensi data,
  ekspor-sebelum-hapus, persyaratan pemberitahuan hukum) adalah keputusan kebijakan yang
  terpisah dan berisiko lebih tinggi yang secara sengaja tidak dimasukkan ke MVP ini.
- **Analitik pendapatan tingkat Central** (MRR/ARR, churn, retensi cohort) — layak dibangun
  begitu ada cukup banyak tenant agar analisisnya bermakna; kandidat alami untuk menggunakan
  ulang pola "ask your data" yang sama yang sudah ditetapkan **AIInsight Core**, diterapkan ke
  DB Central alih-alih DB tenant, begitu itu menjadi kebutuhan nyata.
- **Peran multi-admin/approval pada tinjauan pembayaran** — MVP hanya punya satu admin
  platform (Simon); langkah approver kedua hanya layak dibangun begitu benar-benar ada admin
  kedua, saat itu baru akan menggunakan ulang gerbang bergaya workflow secara konseptual,
  bukan engine bespoke.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis,
> desain database.

## 3A. Dashboard Admin Central

**Fungsi / fitur**
- Gambaran kesehatan platform: total tenant berdasarkan `access_status` (active / past_due /
  read_only), setara-MRR (jumlah biaya plan + add-on aktif), invoice yang diterbitkan periode
  ini, pembayaran menunggu tinjauan, tenant yang memasuki jendela cutoff dalam N hari ke depan.
- Antrean "perlu perhatian": bukti pembayaran yang belum ditinjau, invoice yang jatuh tempo,
  tenant yang mendekati soft cutoff, permintaan provisioning yang menunggu langkah manual
  Simon.
- Aksi cepat: mendaftarkan tenant baru, meninjau pembayaran yang tertunda, menyesuaikan
  plan/add-on tenant, mengaktifkan kembali tenant secara manual (dengan alasan tercatat).

**Layout**
- Atas: kartu ringkasan — Tenant Aktif, Menunggak, Read-Only, Pembayaran Menunggu Tinjauan.
- Utama: tabel bertab — "Tinjauan Pembayaran Tertunda" | "Invoice Menunggak" | "Mendekati
  Cutoff" | "Tenant Terbaru" — menggunakan ulang konvensi komponen Data Table + **Status Rail**
  yang sama (`DESIGN.md`) seperti setiap modul menghadap-tenant, agar permukaan admin ini
  tetap terasa sebagai bagian dari satu platform, bukan alat operasional terpisah yang
  ditempel di samping.
- Klik baris membuka drawer: detail tenant, riwayat invoice/pembayaran, riwayat entitlement,
  jejak audit (§3I).

**Aturan / logika**
- Dashboard ini **hanya untuk admin platform** (`central_admin_users`, §4) — tidak dapat
  dijangkau dari dalam session tenant mana pun, kebalikan cermin dari layar menghadap-tenant
  di §3H.
- Item yang menunggak dan menunggu tinjauan muncul lebih dulu terlepas dari urutan, konvensi
  "pelanggaran muncul lebih dulu" yang sama yang sudah diikuti dashboard setiap modul lain
  (WNE §3A, CRM §3A, Accounting §3A, Purchase §3A, Legal §3A).

## 3B. Registry & Provisioning Tenant

**Tujuan:** record kanonik tunggal "siapa yang menjadi pelanggan platform ini," dan titik
pemicu untuk benar-benar menegakkan DB tenant mereka.

- Field: nama perusahaan/legal, kontak utama (nama, email, telepon), alamat billing,
  `plan_code` (FK `central_plans`, §3D), `provisioning_status`
  (`pending` → `provisioning` → `provisioned` → `active`) dan, secara terpisah,
  `access_status` (`active` / `read_only` — gerbang akses yang digerakkan billing, §3G),
  `tenant_db_name` (database Postgres aktual yang dibuat `stancl/tenancy`), `provisioned_at`,
  `notes`.
- **Aksi registrasi:** Simon (atau, di Future Version, signup self-service) membuat baris
  `tenants` dalam status `pending`. Belum ada apa pun tentang infrastruktur tenant yang ada
  pada titik ini — ini secara sengaja hanya *niat* untuk onboarding pelanggan.
- **Aksi provisioning:** begitu Simon mengonfirmasi tenant siap go-live (invoice pertama
  diterbitkan dan, di MVP, dibayar — atau secara eksplisit comped), aksi "Provision" memanggil
  alur pembuatan tenant milik `stancl/tenancy` (membuat database Postgres, menjalankan
  migration tenant, men-seed default `SYSCONFIG` sesuai pola `TenantFlavorSeeder` milik
  `ARCHITECTURE.md` §1.4, membuat user admin tenant pertama), lalu membalik
  `provisioning_status` menjadi `provisioned` dan `access_status` menjadi `active`.
- Detail view: header tenant + tab — Plan & Entitlement (§3C), Invoice (§3E), Pembayaran
  (§3F), Riwayat Dunning (§3G), Log Audit (§3I).

**Aturan / logika**
- `tenant_db_name` sebuah tenant bersifat immutable setelah diprovisioning — mengganti
  nama/memigrasikan database milik tenant adalah operasi infrastruktur, bukan edit data modul
  Central.
- `tenants` adalah **satu-satunya** tabel di seluruh platform ini yang secara sah butuh
  identitas per-baris yang mencakup semua tenant — ujung yang berlawanan dari spektrum
  dibandingkan aturan "tidak ada kolom `tenant_id`, DB-per-tenant adalah batas isolasi" milik
  setiap modul lain (`CLAUDE.md` §4/§7). Aturan itu menjelaskan tabel *DB-tenant*; `CENTRAL`
  adalah satu-satunya tempat yang benar-benar tepat memiliki tabel yang mendaftar semua
  tenant, karena mendaftar tenant adalah seluruh pekerjaan modul ini.

## 3C. Konfigurasi Plan & Entitlement Modul

**Tujuan:** mengubah "modul mana yang bisa dilihat tenant tertentu" menjadi data nyata dan
dapat di-query alih-alih hanya string plan — mekanisme konkret yang sudah diasumsikan ada oleh
`SYSCONFIG_SPECS.md` §3A ketika menjelaskan "mekanisme entitlement DB-pusat yang sudah ada
(`tenants.plan` + `config/tenant_modules.php` + `TenantFeatureService`)."

- **Entitlement efektif** seorang tenant untuk `module_code` tertentu = gabungan dari:
  1. Setiap modul yang termasuk dalam `plan_code` mereka saat ini di `central_plan_modules`
     (§3D).
  2. Setiap modul yang ditambahkan secara eksplisit via `central_tenant_addons` (pembelian
     à la carte di atas plan dasar — misalnya tenant pada plan vertikal Legal yang secara
     terpisah membeli Performance secara mandiri, sesuai positioning "dapat dijual mandiri"
     yang sudah ditetapkan untuk DMS/Schedule/Inventory/Accounting/Purchase/Performance di
     catatan Marketability spesifikasi masing-masing).
- `central_tenant_addons`: `tenant_id`, `module_code`, `added_at`, `price_override`
  (nullable — untuk tarif yang dinegosiasikan), `status` (`active`/`removed`). Menghapus
  add-on adalah pembalikan status, bukan penghapusan (delete) — postur non-destruktif yang
  sama seperti di tempat lain di platform ini.
- `TenantFeatureService::isEntitled(tenantId, moduleCode)` membaca gabungan yang sudah
  diresolusi ini — fungsi **tunggal** yang pada akhirnya dipanggil kembali oleh setiap
  middleware DB-tenant (`module:CODE`, `CLAUDE.md` §4) dan setiap pemeriksaan visibilitas
  `SYSCONFIG.tenant_modules` (`SYSCONFIG_SPECS.md` §3A) milik tenant sendiri.

**Aturan / logika**
- **Entitlement di sini adalah batas atas (ceiling) yang keras; `SYSCONFIG.tenant_modules`
  hanya bisa mempersempitnya, tidak pernah memperlebarnya** — menyatakan ulang aturan
  `SYSCONFIG_SPECS.md` §3A sendiri dari sisi lain: *"Visibilitas efektif = entitled (central)
  DAN `is_active` (SYSCONFIG)."* `CENTRAL` adalah bagian `entitled` dari persamaan itu;
  `SYSCONFIG` adalah bagian `is_active`. Tidak ada modul yang bisa melakukan pekerjaan modul
  lainnya.
- Mengubah plan atau add-on tenant dicatat ke `central_audit_logs` (§3I) — perubahan
  entitlement adalah peristiwa yang relevan bagi billing, bukan toggle diam-diam.
- Mekanisme ini secara sengaja berbasis data mengikuti bias "utamakan data dibanding deploy
  kode" yang sama yang dipakai di seluruh tangga kustomisasi `ARCHITECTURE.md` — hanya saja
  di sini berada satu anak tangga *di bawah* tangga yang dijelaskan di sana, karena tangga
  itu mengasumsikan DB tenant sudah ada.

## 3D. Katalog Plan Langganan (Master)

**Tujuan:** konfigurasi harga/pengemasan yang diedit Simon saat plan berubah — tidak pernah
di-hardcode dalam kode aplikasi.

- `central_plans`: `code` (misalnya `LEGAL_STARTER`, `LEGAL_PRO`), name, description,
  `price_monthly`, `price_annual`, currency (default IDR), `is_active`.
- `central_plan_modules`: pivot, `plan_code` × `module_code` (ruang kode yang sama dengan
  `SYSCONFIG.tenant_modules.module_code`, §3A milik `SYSCONFIG_SPECS.md`) — set modul default
  yang berhak dimiliki tenant pada plan itu sebelum add-on à la carte apa pun (§3C).
- Layar admin list/detail sederhana, konvensi komponen Data Table/Form bersama yang sama
  (`DESIGN.md`) seperti setiap layar master-data lain di platform ini.

**Aturan / logika**
- Menonaktifkan plan (`is_active = false`) memblokir tenant baru untuk ditugaskan ke plan itu
  tapi tidak pernah memengaruhi tenant yang sudah menggunakannya — pola nonaktivasi
  non-destruktif yang sama yang dipakai untuk setiap tabel lookup lain di platform ini (tipe
  role CRM, tipe akta Legal, tipe cuti HCM, ...).
- Perubahan harga plan berlaku untuk invoice yang dibuat *setelah* perubahan; invoice yang
  sudah diterbitkan tidak pernah dihitung ulang secara retroaktif — sesuai disiplin "jangan
  pernah mengubah record finansial yang sudah diposting" yang diterapkan `ACCOUNTING_SPECS.md`
  §3O dan `PAYROLL_SPECS.md` §3-Admin pada record transaksi mereka masing-masing.

## 3E. Engine Billing / Invoice

**Tujuan:** menghasilkan apa yang harus dibayar tenant, sesuai jadwal, terinci dan bisa
diaudit — secara sengaja ringan, bukan modul Accounting kedua (lihat disambiguasi eksplisit
di §5).

- `central_invoices`: `tenant_id`, `billing_period_start`/`billing_period_end`, `plan_code`
  (diambil snapshot-nya saat diterbitkan — perubahan harga plan berikutnya tidak pernah
  menulis ulang invoice masa lalu, alasan yang sama seperti §3D), status (`draft` → `issued`
  → `payment_submitted` → `paid` / `overdue` / `void`), `amount_total`, currency, `due_date`,
  `issued_at`.
- `central_invoice_lines`: `invoice_id`, description, amount — satu baris untuk biaya plan
  dasar, satu baris tambahan per modul add-on aktif (§3C) pada saat pembuatan.
- **Pembuatan**: job terjadwal berjalan sesuai interval billing tenant (bulanan/tahunan, dari
  `central_plans`), mengambil snapshot plan saat ini + add-on aktif ke invoice `draft` baru,
  lalu membaliknya menjadi `issued` — titik di mana tenant bisa melihat dan bertindak atasnya
  (§3H).
- **Berulang, bukan sekali-jalan**: mencerminkan kehati-hatian "buat draft sesuai jadwal,
  jangan pernah auto-post/auto-charge secara diam-diam" yang sama yang diterapkan
  `ACCOUNTING_SPECS.md` §3P pada engine Recurring Transactions miliknya sendiri — kecuali di
  sini `issued` setara dengan "diposting," karena tidak ada langkah approval lebih lanjut
  yang dibutuhkan untuk invoice langganan pada skala ini.

**Aturan / logika**
- Invoice **dibatalkan (void), tidak pernah dihapus**, jika perlu dibatalkan (misalnya tenant
  di-comp di tengah siklus) — disiplin record finansial non-destruktif yang sama yang dipakai
  di seluruh `ACCOUNTING_SPECS.md` dan `SALES_SPECS.md`.
- `status = overdue` adalah state **turunan** (diterbitkan + melewati `due_date` + belum
  `paid`), dihitung ulang oleh job dunning (§3G), bukan nilai yang diatur langsung oleh apa
  pun.

## 3F. Penangkapan & Konfirmasi Pembayaran (termasuk Bukti)

**Tujuan:** alur pembayaran manual, transfer-bank-dulu yang benar-benar dibutuhkan pasar ini
pada MVP — tenant mengirim bukti, Simon mengonfirmasinya.

- `central_payments`: `invoice_id`, `tenant_id`, amount, method (`bank_transfer` di MVP; nilai
  cadangan untuk gateway Future Version), `receipt_object_key` (referensi objek R2, §4),
  status (`pending_review` → `confirmed` / `rejected`), `submitted_at`, `reviewed_by`
  (`central_admin_users`), `reviewed_at`, `rejection_reason` (nullable, wajib jika ditolak).
- **Pengiriman** (dari §3H, layar menghadap-tenant): tenant mengunggah gambar/PDF bukti dan
  menyatakan jumlah/tanggal yang ditransfer → membuat baris `central_payments` dalam status
  `pending_review`, invoice pindah ke `payment_submitted`.
- **Tinjauan** (dari §3A, dashboard admin): Simon memeriksa bukti, mengonfirmasi
  (`invoice.status → paid`, `tenant.access_status` kembali ke `active` segera jika sebelumnya
  `read_only`, §3G) atau menolak (dengan alasan wajib, invoice kembali ke `issued`/`overdue`,
  tenant diberi tahu melalui channel §3G dan bisa mengirim ulang).

**Aturan / logika**
- Mengonfirmasi pembayaran adalah **satu-satunya** aksi yang membalik invoice menjadi `paid`
  — tidak ada jalur "anggap sudah dibayar" otomatis di MVP, karena tidak ada gateway langsung
  untuk memverifikasi terhadapnya; ini adalah trade-off tinjauan manual yang disengaja dari
  alur transfer-bank-dulu, sesuai dengan Payment Reconciliation MVP milik `PAYROLL_SPECS.md`
  sendiri (§3-Payment) yang manual/berbasis-file alih-alih API bank langsung untuk alasan yang
  sama.
- File bukti pembayaran yang ditolak tetap disimpan (tidak pernah dihapus) untuk kepentingan
  pencatatan Simon sendiri — postur "jangan pernah menghancurkan bukti" yang sama seperti
  disiplin retensi/legal-hold milik DMS, diterapkan di sini pada bukti finansial alih-alih
  dokumen hukum.
- Unggah bukti (dan melihat riwayat invoice/pembayaran) harus tetap dapat dijangkau
  **bahkan saat tenant sedang `read_only`** — ini adalah satu pengecualian eksplisit dan
  sengaja terhadap penegakan soft-cutoff di §3G/§5, karena jika tidak, tenant yang dipotong
  aksesnya tidak akan punya cara untuk membayar kembali menuju `active`.

## 3G. Engine Dunning — Pengingat & Soft Cutoff

**Tujuan:** mekanisme otomatis, dapat dikonfigurasi yang melindungi bisnis tanpa bersifat
hukuman — pengingat dulu, lalu degradasi read-only yang lembut, tidak pernah kehilangan data.

- `central_dunning_policies`: `scope_type` (`platform_default` / `plan` / `tenant`,
  diresolusi paling-spesifik-menang — *ide* tangga override yang sama yang sudah diterapkan
  engine presisi dua-tingkat `SYSCONFIG.config_consts` di dalam DB tenant
  (`SYSCONFIG_SPECS.md` §3E), dicerminkan di sini satu lapisan lebih tinggi meskipun secara
  literal tidak bisa menjadi engine yang sama persis, karena ini adalah database yang
  sepenuhnya berbeda), `scope_id` (nullable — sebuah `plan_code` atau `tenant_id`, tergantung
  `scope_type`), `reminder_offsets_days` (array JSON offset hari relatif terhadap `due_date`,
  misalnya `[-7, -3, -1, 3, 7]` — negatif = sebelum jatuh tempo, positif = setelah jatuh
  tempo), `cutoff_days_after_due` (misalnya `14`), `cutoff_action` (VARCHAR + CHECK —
  `read_only` adalah satu-satunya nilai di MVP, sesuai konvensi "field status/type sebagai
  VARCHAR + CHECK, bukan enum native" yang sudah ditetapkan platform ini sendiri).
- **Job pengingat** (terjadwal harian): untuk setiap invoice `issued`/`overdue`, membandingkan
  hari ini terhadap `due_date + setiap offset yang dikonfigurasi`; saat cocok, mengirim satu
  pengingat (email, satu-satunya channel MVP — lihat §5 untuk alasan ini tidak menggunakan
  ulang WNE) dan mencatatnya ke `central_dunning_log` (`tenant_id`, `invoice_id`, offset yang
  dipicu, channel, `sent_at`) — diperiksa lebih dulu agar offset yang sama tidak pernah
  dikirim dua kali untuk invoice yang sama.
- **Job cutoff** (dijalankan bersama job harian yang sama): untuk setiap invoice `overdue`
  yang melewati `due_date + cutoff_days_after_due` tanpa status `paid`, membalik
  `tenants.access_status → read_only` dan mencatat transisi tersebut ke `central_audit_logs`
  (§3I).
- **Reaktivasi**: begitu pembayaran dikonfirmasi (§3F) untuk invoice yang menyebabkan cutoff,
  `access_status` kembali ke `active` **secara otomatis** — tidak perlu langkah "aktifkan
  kembali" manual terpisah, meskipun ada satu di dashboard admin (§3A) untuk kasus
  pengecualian (misalnya Simon meng-comp tenant yang menunggak).

**Aturan / logika**
- `reminder_offsets_days` dan `cutoff_days_after_due` adalah **konfigurasi yang dapat diedit
  Simon per-tenant**, sesuai kebutuhan — tidak pernah di-hardcode, diresolusi sesuai
  precedence scope di atas (persyaratan yang dinegosiasikan seorang tenant tertentu bisa
  meng-override default platform tanpa perubahan kode).
- Soft cutoff (`read_only`) **tidak pernah menyentuh data tenant** — ini murni state kontrol
  akses, ditegakkan oleh middleware yang dijelaskan di §5, bukan pembatasan di lapisan data.
  Ini mencerminkan filosofi non-destruktif yang sama yang sudah diterapkan setiap modul di
  platform ini pada domainnya sendiri (DMS tidak pernah menghapus saat retensi habis tanpa
  aksi eksplisit, Payroll Lock memblokir edit tapi tidak pernah menghapus run, merge CRM tidak
  pernah menghancurkan record yang kalah) — diterapkan di sini pada hubungan tenant itu
  sendiri, bukan hanya sebuah record di dalamnya.
- Suspensi/penghapusan keras secara eksplisit di luar cakupan untuk MVP (§2 Future Version) —
  satu-satunya tuas penegakan engine ini hari ini adalah `read_only`.

## 3H. Layar Billing Menghadap Tenant (lintas-batas)

**Tujuan:** satu permukaan kecil yang sengaja dibatasi ruang lingkupnya yang dilihat user
admin tenant sendiri *di dalam aplikasi tenant mereka sendiri* — meskipun data yang
dibaca/ditulisnya berada di database yang sama sekali berbeda (Central, bukan DB tenant
mereka).

- Layar: "Billing & Subscription" (dapat dijangkau dari area akun/pengaturan tenant sendiri,
  tidak digerbang oleh rights menu `SYSCONFIG` karena ini sama sekali bukan data DB tenant) —
  menampilkan plan + add-on saat ini, riwayat invoice dengan status, dan aksi "Submit
  Payment" (jumlah, tanggal, unggah bukti) untuk invoice mana pun yang
  `issued`/`overdue`/`payment_submitted`.
- **Pendekatan teknis**: ini *bukan* panggilan REST/API ke layanan terpisah — sesuai
  kebijakan batas Web `CLAUDE.md` §2 ("jangan membangun endpoint REST/GraphQL untuk halaman
  web... buat REST hanya ketika klien non-Inertia sudah nyata"), ini tetap berada di dalam
  monolith Laravel yang sama. Laravel mendukung banyak koneksi database dari satu aplikasi;
  sebuah `Controllers/BillingController` tipis di dalam aplikasi menghadap-tenant meng-query
  koneksi `central` secara langsung (model Eloquent yang terikat ke koneksi itu) dan tetap
  mengembalikan respons `Inertia::render(...)` biasa. Ini adalah **batas koneksi**, bukan
  batas layanan — tidak ada deployable baru, tidak ada permukaan API baru, sepenuhnya
  konsisten dengan "modular monolith dulu" (`CLAUDE.md` §2).

**Aturan / logika**
- Layar ini harus tetap dapat dijangkau terlepas dari `access_status` — ini adalah
  pengecualian eksplisit terhadap penegakan read-only yang dicatat di §3F/§3G/§5, karena ini
  satu-satunya jalur kembali ke `active`.
- Hanya user admin yang ditunjuk oleh tenant yang bisa melihat layar ini (flag pada record
  user milik tenant sendiri, diresolusi dengan cara yang sama seperti layar "admin-only"
  mana pun yang digerbang di tempat lain di platform ini) — user tenant umum tidak punya
  alasan untuk melihat billing langganan perusahaan tersebut.

## 3I. Log Audit Central

- `central_audit_logs`: append-only, satu baris per aksi (`tenant_registered`,
  `tenant_provisioned`, `plan_changed`, `addon_added`, `addon_removed`, `invoice_issued`,
  `invoice_voided`, `payment_submitted`, `payment_confirmed`, `payment_rejected`,
  `access_status_changed`, `dunning_policy_changed`), aktor (baris `central_admin_users`,
  atau `system` untuk aksi job terjadwal), timestamp, referensi entitas, snapshot
  sebelum/sesudah — postur audit immutable yang sama seperti `dms.access_logs`,
  `wne.wrkflow_audit_logs`, `acct.audit_logs`, `sysconfig.config_audit_logs`, dan
  `field_def_audit_logs`.
- Tidak ada update/delete yang diizinkan pada tabel ini di lapisan aplikasi, aturan yang sama
  seperti setiap log audit lain di platform ini.
- Satu log terpadu (alih-alih satu tabel per concern) adalah pilihan skala-MVP yang disengaja
  — volume tulis Central lebih rendah beberapa orde besaran dibanding modul transaksional
  tenant sendiri, jadi satu tabel append-only yang dibedakan oleh `entity_type` sudah cukup;
  `central_dunning_log` (§3G) tetap terpisah karena ia menjalankan fungsi ganda sebagai state
  *fungsional* (mencegah pengiriman pengingat duplikat), bukan sekadar record historis.

# 4. Penyimpanan

**Database: DB Central (`nusaevo`)** — tanpa pemisahan schema per-modul seperti yang dipakai
DB tenant untuk `SYSCONFIG.`/`WNE.`/`CRM.` dll. (`CLAUDE.md` §7A); database ini punya tepat
satu pekerjaan, jadi namespace tabel yang datar sudah cukup. `tenants` dan
`tenant_user_lookups` adalah dua tabel yang sudah dirujuk `ARCHITECTURE.md` dan diperluas di
sini alih-alih didefinisikan ulang; setiap tabel lain di bawah ini baru, diberi prefix
`central_` untuk kejelasan terhadap dua tabel yang sudah ada sebelumnya.

**Tabel registry / master**
- `tenants` *(sudah ada, diperluas)* — info perusahaan/kontak, `plan_code` (FK
  `central_plans`), `provisioning_status`, `access_status`, `tenant_db_name`,
  `provisioned_at`.
- `tenant_user_lookups` *(sudah ada, tidak berubah)* — lookup email → tenant_id untuk
  resolusi tenant terikat login, sesuai `CLAUDE.md` §4.
- `central_plans` — katalog plan (§3D).
- `central_plan_modules` — pivot plan × module_code (§3D).
- `central_admin_users` — akun admin platform, terpisah dari user milik tenant mana pun.

**Tabel transaksi entitlement / billing**
- `central_tenant_addons` — modul à la carte di atas plan dasar tenant (§3C).
- `central_tenant_module_grants` — **Future Version**, sesuai §2 dan sesuai rujukan-maju
  `SYSCONFIG_SPECS.md` sendiri; tidak dibangun di MVP.
- `central_invoices`, `central_invoice_lines` — billing langganan (§3E).
- `central_payments` — pengiriman pembayaran + hasil tinjauan, termasuk
  `receipt_object_key` (§3F).
- `central_dunning_policies` — jadwal pengingat/cutoff yang dapat dikonfigurasi, diresolusi
  per scope (§3G).

**Tabel log**
- `central_dunning_log` — append-only, fungsional (mencegah pengiriman duplikat) + historis
  (§3G).
- `central_audit_logs` — append-only, jejak audit umum platform (§3I).

**Penyimpanan file objek** — prefix tingkat-atas khusus di dalam bucket Cloudflare R2 bersama
yang sama, berbeda dari konvensi per-tenant `tenant_{id}/` (`CLAUDE.md` §7B), karena data ini
milik hubungan billing platform sendiri dengan sebuah tenant, bukan milik apa pun di dalam DB
tenant tersebut:
```text
central/
├── tenants/{tenant_id}/receipts/{payment_id}/{filename}   # bukti pembayaran yang diunggah
└── tenants/{tenant_id}/invoices/{invoice_id}/invoice.pdf  # PDF invoice yang dihasilkan (opsional)
```
- Central mengimplementasikan penyimpanan unggah-dan-simpan minimal, datar, non-versioned
  miliknya sendiri untuk bukti pembayaran — tidak ada riwayat versi, tidak ada engine
  retensi/legal-hold, tidak ada hook OCR. Ini secara sengaja **tidak** melalui **DMS** (lihat
  §5) — bukti pembayaran cukup disimpan tanpa batas waktu sebagai bukti finansial, kebijakan
  paling sederhana untuk jenis dokumen dengan volume terendah dan paling jarang disentuh di
  platform ini.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur — kategori baru, bukan Core atau Vertical.** `CLAUDE.md` §2/§10 sudah
membedakan Core (bersama, tanpa pengetahuan tentang Vertical) dari Vertical (bergantung pada
Core) dari Microservice (ekstraksi yang terjustifikasi saja). `CENTRAL` bukan salah satu dari
ini — ia adalah **tingkat-Platform**: satu-satunya modul yang berada sepenuhnya di luar DB
tenant mana pun, dan yang menjadi tempat bergantung keberadaan setiap DB tenant, bukan
sebaliknya. Direkomendasikan `CLAUDE.md` §2/§10 diamandemen untuk menamai kategori keempat ini
secara eksplisit, jenis koreksi dokumentasi-menyusul-realitas yang sama yang sudah dilakukan
`SYSCONFIG_SPECS.md` §5 dan `CUSTOMFIELDS_SPECS.md` §5 untuk lapisan mereka sendiri.

**Koreksi urutan pembangunan (rekomendasi mengamandemen `CLAUDE.md` §5):** `CLAUDE.md` §5 saat
ini dibuka dengan `SYSCONFIG` sebagai langkah 1, "fondasional, dibangun sebelum apa pun,
termasuk sistem desain dan setiap modul Core." Itu benar *untuk lapisan DB-tenant* — tetapi
`SYSCONFIG` berada di dalam sebuah DB tenant, dan DB tenant tidak ada sampai `CENTRAL`
memprovisioning satu (§3B). Direkomendasikan `CENTRAL` disisipkan sebagai **langkah 0**
eksplisit, sebelum `SYSCONFIG`: bangun cukup dari Registry Tenant (§3B) dan jalur provisioning
manual minimal untuk menegakkan DB tenant *pertama* yang akan dibangun Claude Code
`SYSCONFIG` di dalamnya — Billing/Dunning (§3E–§3G) bisa menyusul begitu ada tenant kedua atau
ketiga yang nyata dan membayar untuk ditagih, tetapi bagian registry/provisioning harus ada
lebih dulu, secara struktural.

**Batas modul (konvensi "Layer | Owns | Must not" yang sama yang sudah dipakai
`ARCHITECTURE.md` §2.2):**

| Layer | Memiliki | Tidak boleh |
|-------|------|----------|
| `CENTRAL` | Registry tenant, plan, entitlement, invoice, pembayaran, dunning | Mengimpor model modul mana pun yang terlingkup-tenant (WNE, DMS, SYSCONFIG, ...) — tidak satu pun dari mereka ada sampai Central memprovisioning DB tempat mereka berada |
| `SYSCONFIG` (per tenant) | Toggle visibilitas `tenant_modules`, mempersempit entitlement Central | Memberikan modul yang belum diberi hak oleh Central, atau mengasumsikan bisa memperlebar apa yang diberikan Central |
| `ACCOUNTING` milik tenant sendiri (per tenant) | AR/AP pelanggan tenant tersebut sendiri, kepatuhan PPN/PPh | Mencatat pendapatan langganan SaaS Simon sendiri — pembukuan perusahaan yang sama sekali berbeda |

**Kenapa ini bukan modul Accounting kedua — disambiguasi eksplisit (pola yang sama yang
sudah dipakai untuk `LEGAL.deed_taxes` vs. engine pajak `ACCOUNTING`, `LEGAL_SPECS.md` §3K):**
`central_invoices`/`central_payments` melacak apa yang **dihutang tenant kepada perusahaan
Simon** untuk akses platform — pelacak piutang yang ringan, bukan sistem pembukuan yang
sesuai standar statutori. Tidak ada GL double-entry, tidak ada penyajian PSAK, tidak ada
penanganan PPN/Faktur Pajak. Jika Simon akhirnya ingin pembukuan formal dan benar-pajak untuk
pendapatan SaaS perusahaannya sendiri, itu adalah concern yang benar-benar terpisah (misalnya
instance modul `ACCOUNTING` miliknya sendiri sebagai tenant dari platformnya sendiri, atau
pembukuan eksternal) — secara eksplisit di luar cakupan untuk `CENTRAL`, yang hanya perlu
menjawab "apakah tenant ini sudah bayar, dan apakah mereka lancar" dengan cukup benar untuk
menggerakkan kontrol akses dan pengingat.

**Kenapa ini tidak menggunakan ulang WNE.** Setiap modul lain di platform ini diarahkan untuk
menggunakan ulang WNE untuk approval/notifikasi alih-alih membangun jalur paralel — nasihat
yang berlawanan berlaku di sini. WNE adalah modul yang **terlingkup-tenant**; ia tidak ada
sampai sebuah DB tenant diprovisioning, dan `CENTRAL` beroperasi baik sebelum titik itu
(registrasi/provisioning) maupun secara independen dari instance WNE milik tenant mana pun
(billing mencakup semua tenant). Ini adalah satu-satunya tempat sah di platform ini di mana
"jangan bangun jalur notifikasi paralel" tidak berlaku, karena tidak ada engine bersama di
lapisan ini untuk digunakan ulang. Dunning (§3G) karena itu mengimplementasikan pengiriman
minimal, satu-channel (email) miliknya sendiri — tidak ada interface driver multi-channel,
tidak ada pusat preferensi user, tidak ada mesin retry/DLQ — secara sengaja jauh lebih ringan
dibanding Notification Module milik WNE sendiri, karena volume dan taruhan di lapisan ini
belum menjustifikasi mesin itu.

**Kenapa ini tidak menggunakan ulang DMS.** Alasan yang sama — DMS terlingkup-tenant dan,
dalam skenario yang paling dikhawatirkan modul ini (tenant menunggak yang mencoba mengirim
bukti untuk diaktifkan kembali), melalui instance DMS milik tenant sendiri akan bersifat
sirkular: engine retensi/versioning DMS sendiri tidak punya alasan untuk ada bagi dokumen yang
sebenarnya bukan "data tenant ini" sama sekali, ini adalah bukti dalam hubungan Simon *dengan*
tenant tersebut. `CENTRAL` mengimplementasikan penyimpanan datar trivial miliknya sendiri (§4)
sebagai gantinya.

**Mekanisme penegakan untuk akses `read_only`.** Sebuah middleware Laravel global
(`EnsureTenantStanding`, diterapkan sebelum setiap route yang mengubah state —
POST/PUT/PATCH/DELETE — di seluruh modul tenant) memeriksa `access_status` tenant saat ini
dari `CENTRAL` sebelum mengizinkan request lewat. Karena pemeriksaan ini jika tidak begitu
akan berjalan pada setiap request yang mengubah state di seluruh platform, nilainya di-cache
di Redis (`central:tenant:{id}:access_status`), diinvalidasi begitu §3F/§3G mengubahnya —
pola "cache nilai yang diresolusi, invalidasi saat menulis" yang sama yang sudah dipakai untuk
`SYSCONFIG.config_consts` (`SYSCONFIG_SPECS.md` §3E) dan `CUSTOMFIELDS.field_defs`
(`CUSTOMFIELDS_SPECS.md` §5). Request yang diblokir mengembalikan pesan yang jelas dan tenang
sesuai panduan suara §5 `DESIGN.md` — *"Langganan Anda sudah lewat jatuh tempo. Anda masih
bisa melihat data Anda, tetapi perubahan dijeda sampai pembayaran dikonfirmasi."* — dengan
tautan langsung ke layar Billing §3H, yang secara eksplisit di-allowlist melalui middleware
yang sama ini bersama route read-only (GET) di tempat lain.

**Catatan idempotensi/konkurensi:**
- Pembuatan invoice (§3E) adalah job terjadwal yang di-key oleh `(tenant_id,
  billing_period)` dengan constraint keunikan — menjalankan ulang job tidak pernah membuat
  invoice duplikat untuk periode yang sudah ditagih.
- Keberadaan `central_dunning_log` untuk kombinasi `(tenant_id, invoice_id, offset)` tertentu
  itulah yang mencegah job pengingat mengirim dua kali notice yang sama jika dijalankan ulang
  atau tumpang tindih dengan run sebelumnya yang lambat.
- Konfirmasi pembayaran (§3F) dan job cutoff (§3G) sama-sama menulis ke
  `tenants.access_status`; mengonfirmasi pembayaran selalu menang atas pemeriksaan cutoff
  yang sedang berjalan untuk tenant yang sama (transaksi di lapisan service, bukan trigger DB,
  menjaga ini tetap eksplisit dan mudah dipahami bagi solo dev yang membacanya ulang nanti —
  bias "kode yang eksplisit dan sederhana" yang sama yang dinyatakan `CLAUDE.md` §6 sebagai
  konvensi coding umum).

**Urutan pembangunan yang disarankan untuk Claude Code:** Registry Tenant minimal §3B +
pemicu provisioning manual (langkah 0 secara literal — ini harus ada sebelum `SYSCONFIG` atau
modul Core mana pun bisa dibangun terhadap DB tenant yang nyata) → Katalog Plan §3D →
Entitlement §3C (plan + add-on, diresolusi via `TenantFeatureService`) → Pembuatan Invoice §3E
→ Penangkapan Pembayaran + konfirmasi §3F (tinjauan manual dulu, tanpa gateway) → Layar
Billing menghadap-tenant §3H (pola koneksi-Eloquent lintas-batas) → Dunning §3G (pengingat,
lalu cutoff) → Dashboard Admin Central §3A (mengikat semua di atas untuk pemakaian harian
Simon sendiri) → Log Audit §3I (murah untuk diretrofit begitu jalur tulis di atas stabil,
tapi log sejak hari pertama pada masing-masing, bukan ditempel di akhir) — **selesai di
sini**, karena inilah yang membuat mengambil tenant kedua atau ketiga yang membayar menjadi
masuk akal secara operasional, bukan hanya mungkin.

**Catatan kelayakan jual (marketability)**
- Modul ini tidak terlihat oleh tenant mana pun kecuali melalui satu layar Billing sempit
  (§3H) — tetapi inilah yang membuat cerita "dapat dijual mandiri" setiap modul lain (DMS,
  Schedule, Inventory, Accounting, Purchase, Performance) benar-benar dapat dimonetisasi
  sebagai add-on à la carte alih-alih hanya kerapian arsitektural, karena model plan+add-on
  §3C adalah mekanisme billing literal di balik strategi pengemasan itu.
- Degradasi read-only yang bersih dan tenang (tidak pernah kehilangan data, tidak pernah
  penguncian yang bersifat hukuman) itu sendiri adalah sinyal kepercayaan yang layak
  dinyatakan secara terbuka kepada calon tenant — audiens pembeli legal yang konservatif
  (brief `DESIGN.md` sendiri) akan bertanya "apa yang terjadi jika kami terlambat pada
  invoice," dan "Anda masih bisa melihat semuanya, Anda hanya tidak bisa mengubahnya sampai
  kami setara" adalah jawaban yang jauh lebih baik daripada baik diam maupun suspensi tiba-tiba.
- Jendela dunning yang dapat dikonfigurasi per tenant (§3G) memungkinkan Simon menawarkan
  persyaratan yang lebih baik kepada klien awal yang strategis tanpa perubahan kode apa pun —
  cerita "data, bukan deploy" yang sama yang sudah diceritakan setiap modul lain di platform
  ini, diterapkan pada persyaratan komersial platform itu sendiri.
