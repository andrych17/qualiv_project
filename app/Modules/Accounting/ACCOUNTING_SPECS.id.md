# Modul Accounting
## Inti Finansial — GL, AR, AP, Kas & Bank, Aset, Costing Persediaan, Cost Accounting, Budgeting, Multi Company/Currency, Pajak & Kepatuhan Indonesia — Modul Inti Bersama (dapat mandiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal (Legal hari ini; Property dan lainnya belakangan) dan setiap modul Core lain
(CRM, DMS, Schedule, WNE) pada akhirnya menghasilkan sesuatu yang melibatkan uang: invoice,
tagihan (bill), pembayaran, deposit trust, pembelian aset, payroll run. Jika dibiarkan tidak
diselesaikan secara terpusat, ini mengulangi anti-pola yang sama yang berusaha dihindari
WNE/DMS/CRM:

- Setiap vertikal menciptakan field "uang" miliknya sendiri (kolom balance di sini, flag
  paid-flag di sana) — tidak ada integritas double-entry, tidak ada single source of truth
  finansial, tidak ada cara untuk menghasilkan Balance Sheet atau P&L yang nyata di seluruh
  tenant.
- Tidak ada subledger terpadu — AR/AP berakhir sebagai tabel ad hoc di dalam modul mana pun
  yang lebih dulu sampai di sana, alih-alih dapat digunakan ulang oleh setiap vertikal
  (billing Legal, rent roll Property, modul Sales masa depan) terhadap Chart of Accounts yang
  **sama**.
- **Kepatuhan statutori Indonesia tidak dapat ditawar dan mudah salah secara mahal**: PPN,
  keluarga withholding PPh (21/23/26/4(2)/22/15), filing SPT bulanan/tahunan, dan — sejak 2026
  — **routing wajib melalui sistem Coretax milik DJP**, yang menggantikan aplikasi lama
  e-Faktur/e-Bupot/DJP Online dan mengharapkan dokumen pajak direkonsiliasi dengan pembukuan
  wajib pajak sendiri secara mendekati real time. Seorang tenant (terutama firma hukum yang
  mengelola uang trust klien) tidak dapat dijual produk ini tanpa perilaku pajak dan audit yang
  benar tertanam.
- Tidak ada penawaran mandiri — Accounting adalah salah satu kategori software bisnis yang
  paling umum *dibeli sendiri*; modul ini harus bekerja tanpa apa pun yang lain terpasang,
  kebutuhan mandiri yang sama yang sudah diterapkan pada DMS dan Schedule.
- Tidak ada otomasi dari sisa ERP — billing kasus Legal, invoice sewa Property, kelebihan
  penyimpanan DMS, biaya layanan berulang yang digerakkan Schedule, sales order yang berasal
  dari CRM semuanya perlu menjadi entri GL dan invoice AR **tanpa modul-modul itu tahu apa pun
  tentang pembukuan double-entry** — seam terdekopel yang sama seperti di tempat lain di
  codebase ini.

**Kebutuhan klien:**
- General Ledger double-entry penuh, benar-secara-statutori untuk penyajian PSAK (Pernyataan
  Standar Akuntansi Keuangan) Indonesia, sebagai single source of truth finansial.
- Subledger AR dan AP yang rekonsiliasi ke akun kontrol GL secara otomatis — tidak pernah
  disesuaikan secara manual.
- Manajemen Kas & Bank dengan rekonsiliasi terhadap rekening koran bank.
- Register Fixed Asset dengan aturan depresiasi pajak Indonesia (depresiasi fiskal vs.
  komersial, per klasifikasi kelompok aset PMK/UU HPP) berdampingan dengan depresiasi komersial
  (PSAK).
- **Posting GL Persediaan** — modul **Inventory** (`INVENTORY_SPECS.md`, sudah dibangun)
  memiliki baik stok fisik (kuantitas, lokasi, pergerakan) maupun costing/valuasinya (FIFO atau
  Weighted-Average, sesuai `CostingStrategyInterface` miliknya sendiri) — Accounting tidak
  mengimplementasikan ulang keduanya. Satu-satunya pekerjaan Accounting adalah menerjemahkan
  event pergerakan Inventory yang sudah divaluasi menjadi jurnal GL yang benar (Aset-Persediaan
  ↔ COGS/GRNI/Adjustment), dan memverifikasi bahwa saldo akun kontrol Aset-Persediaan miliknya
  sendiri rekonsiliasi dengan total valuasi Inventory.
- Cost accounting dasar (cost center, alokasi sederhana) dan budgeting (budget vs. aktual) —
  cukup untuk dapat dijual, bukan suite budgeting penuh.
- Multi-company (beberapa entitas legal di bawah satu tenant, misalnya perusahaan operasional
  firma hukum + entitas trust klien) dan multi-currency (transaksi mata uang asing dengan
  laba-rugi terealisasi/belum terealisasi), karena keduanya umum bahkan untuk SME satu-tenant
  tunggal.
- Engine pajak Indonesia: PPN (output/input, faktur pajak), withholding PPh
  (21/23/4(2)/22/15, bukti potong), **ekspor kompatibel-Coretax** (XML/API) karena software
  pembukuan pihak ketiga diharapkan terintegrasi dengan Coretax alih-alih memutarinya.
- Audit & kepatuhan: jejak jurnal posted yang immutable, period locking, approval-gated posting
  untuk entri material, log audit user-action penuh — mencerminkan postur audit DMS, karena
  record finansial membawa persyaratan discoverability/integritas yang sama.
- Transaksi berulang (template jurnal berulang, AR/AP berulang) dan rekonsiliasi bank/akun
  sebagai engine kelas satu, bukan laporan tempelan.
- **Otomasi dari ERP** — modul lain (billing Legal, sales CRM, kelebihan penyimpanan DMS, biaya
  yang dipicu Schedule) dapat memicu posting GL dan dokumen AR/AP via event/facade, tidak
  pernah dengan menulis langsung ke tabel Accounting.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan menyelesaikan Latar Belakang di atas. **MVP-first** — ini modul besar
> dari segi jumlah fitur, jadi cakupan sengaja dipadatkan per engine; apa pun yang tidak
> dibutuhkan untuk melakukan invoice, membayar, mencatat, rekonsiliasi, dan melaporkan dengan
> benar (dengan penanganan pajak Indonesia yang benar) didorong ke Future Version.

## Dalam cakupan untuk v1 (MVP — implementasi cepat)

> **Batasan MVP yang tidak dapat ditawar:** Perilaku pajak dan kepatuhan-statutori Indonesia
> (PPN, withholding PPh, penomoran Faktur Pajak / Bukti Potong, ekspor kompatibel-Coretax,
> integritas periode/audit) bersifat **fondasional, bukan add-on**. Ini diluncurkan dalam pass
> build pertama yang sama seperti COA/GL dan AR/AP — AR/AP tidak dianggap "selesai" sampai
> mereka memposting perlakuan pajak yang benar, karena invoice atau tagihan yang diterbitkan
> tanpa penanganan PPN/PPh yang benar adalah liabilitas kepatuhan sejak transaksi pertama,
> bukan celah yang aman untuk ditambal belakangan. Segala sesuatu yang lain di modul ini
> (Assets, Costing Persediaan, Cost Accounting, Budgeting, kedalaman multi-company/currency,
> otomasi Recurring, Reconcile) dapat secara sah dibertahap — kebenaran pajak tidak bisa.

- **Chart of Accounts (COA) & GL** — COA yang dapat dikonfigurasi (pengelompokan standar
  Indonesia: Aset, Liabilitas, Ekuitas, Pendapatan, HPP, Beban), entri jurnal manual, posting
  GL, trial balance. Ini fondasi yang menjadi tempat posting setiap engine lain — tidak ada
  yang melewatinya.
- **Engine Pajak Indonesia (dibangun bersamaan dengan COA/GL, sebelum AR/AP dianggap dapat
  digunakan)** — kode/tarif pajak, tipe withholding, penomoran/pembuatan Faktur Pajak dan Bukti
  Potong, dan driver ekspor Coretax (detail penuh di §3M) ada dan tersambung *sebelum* AR/AP
  diluncurkan untuk tenant nyata — logika posting AR/AP ditulis terhadap engine ini sejak awal,
  bukan diretrofit ke tabel invoice/tagihan yang tidak sadar pajak.
- **AR (Accounts Receivable)** — invoice pelanggan (dari Partners via CRM), aplikasi
  pembayaran, aging AR, auto-rekonsiliasi akun kontrol ke GL. Setiap invoice diposting dengan
  perlakuan PPN output yang benar dan (jika berlaku) pembuatan Faktur Pajak sejak invoice
  pertama diterbitkan — ini bukan toggle opsional yang ditambahkan belakangan.
- **AP (Accounts Payable)** — tagihan vendor, penjadwalan/approval pembayaran, aging AP,
  auto-rekonsiliasi akun kontrol yang sama. Setiap tagihan diposting dengan perhitungan
  withholding PPh dan pembuatan Bukti Potong yang benar sejak tagihan pertama dientri, untuk
  alasan yang sama.
- **Kas & Bank** — akun bank/kas, kas-masuk/kas-keluar, transfer antar-akun, impor rekening
  koran (gaya CSV/MT940), rekonsiliasi manual + berbantuan-aturan.
- **Fixed Assets** — register aset, depresiasi komersial garis-lurus, depresiasi fiskal
  Indonesia (berbasis kelompok-aset, declining-balance atau garis-lurus per kelompok sesuai UU
  HPP), disposal, buku depresiasi ganda (komersial vs. fiskal).
- **Posting GL Persediaan (interface saja — tanpa logika costing, tanpa stok fisik)** —
  mengonsumsi event `inventory.goods_received` / `inventory.goods_issued` /
  `inventory.stock_adjusted` dari modul **Inventory** (`INVENTORY_SPECS.md`), yang merupakan
  satu-satunya sumber kebenaran platform baik untuk kuantitas stok maupun valuasi stok
  (`INVENTORY.stock_ledger` / `stock_valuation_layers`, FIFO/Weighted-Average via
  `CostingStrategyInterface`). Setiap event sudah membawa unit cost dan total value yang
  terhitung untuk pergerakan itu — satu-satunya pekerjaan Accounting adalah memposting entri
  jurnal Aset-Persediaan ↔ COGS/GRNI/Adjustment yang sesuai. Tidak ada apa pun di Accounting
  yang menurunkan-ulang, menghitung-ulang, atau menyimpan lapisan costing miliknya sendiri.
- **Cost Accounting (ringan)** — cost center/dimensi pada baris jurnal, run alokasi berbasis
  persentase sederhana (misalnya overhead bersama dibagi lintas cost center).
- **Budgeting (ringan)** — budget tahunan per akun × cost center × periode, laporan varians
  budget vs. aktual. Tanpa forecast rolling, tanpa skenario what-if di v1. Ini adalah budget
  kelas-finance, presisi-akun-GL; modul **Performance** (`PERFORMANCE_SPECS.md` §3B)
  menawarkan budget terpisah, berbasis subject/category, untuk pelaporan management/board di
  samping KPI dan OKR — keduanya saling melengkapi, bukan duplikatif (lihat
  `PERFORMANCE_SPECS.md` §1 untuk pemisahannya), dan Performance dapat secara opsional membaca
  data GL modul ini untuk angka "aktual" miliknya sendiri via
  `AccountingService::getAccountBalance(...)` alih-alih mengentri ulang.
- **Multi Company** — beberapa entitas legal di dalam satu DB tenant, masing-masing dengan
  instance COA, ledger, dan kalender fiskal miliknya sendiri; trial balance konsolidasi lintas
  company sebagai laporan (bukan eliminasi terotomasi — lihat Future Version).
- **Multi Currency** — transaksi mata uang asing pada AR/AP/jurnal, tabel exchange rate harian,
  laba/rugi terealisasi saat settlement, revaluasi belum-terealisasi period-end sederhana.
- **Engine Pajak Indonesia** — pelacakan PPN output/input dengan penomoran Faktur Pajak,
  perhitungan withholding PPh (21/23/4(2) minimal untuk MVP; 22/15 sebagai tipe siap-config),
  pembuatan Bukti Potong, **driver ekspor Coretax** (XML terstruktur yang cocok dengan format
  impor DJP, karena Coretax sekarang menjadi channel wajib — lihat §5).
- **Financial Analysis / Reporting** — Trial Balance, Balance Sheet, P&L (Laba Rugi), Cash Flow
  (metode tidak langsung), aging AR/AP, GL detail/drill-down. Format penyajian yang
  patuh-PSAK untuk laporan primer.
- **Audit & Compliance** — ledger jurnal posted yang immutable (koreksi hanya via entri
  reversing, tidak pernah diedit-di-tempat), period locking (soft-close/hard-close), workflow
  approval untuk posting di atas ambang yang dapat dikonfigurasi (via WNE), log audit
  user-action penuh (mencerminkan pola `access_logs` DMS).
- **Recurring Transactions** — template jurnal berulang dan template dokumen AR/AP berulang
  (misalnya invoice retainer bulanan, sewa bulanan), dibuat sesuai jadwal (via modul Schedule)
  dengan antrean review-sebelum-posting.
- **Reconcile** — rekonsiliasi bank (pencocokan baris rekening koran ↔ transaksi kas GI,
  manual + auto-match berbantuan-aturan) dan rekonsiliasi akun kontrol AR/AP (otomatis, karena
  subledger dan GL diposting bersama secara transaksional — tidak pernah menjadi "masalah
  pencocokan" seperti bank recon).
- **Otomasi dari ERP** — facade `AccountingService` + event `JournalPostingRequested` /
  `InvoiceRequested` / `BillRequested` sehingga modul mana pun (billing Legal, sales CRM,
  kelebihan penyimpanan DMS, biaya yang dipicu Schedule) dapat memposting transaksi finansial
  tanpa menyentuh logika double-entry itu sendiri — pola seam yang sama seperti WNE/DMS/CRM.

## Future Version (secara eksplisit ditunda — jangan dibangun sekarang)

- **Konsolidasi dengan eliminasi intercompany** — v1 memberikan trial balance gabungan lintas
  company; konsolidasi sejati (persentase kepemilikan, minority interest, entri eliminasi,
  pencocokan intercompany) adalah masalah akuntansi yang jauh lebih berat dan berbeda — bangun
  hanya jika klien dengan struktur grup nyata membutuhkannya.
- **Modul Inventory/Warehouse fisik penuh** (stok, lokasi, transfer, stock-take) — Accounting
  hanya melakukan lapisan costing/valuasi di v1; inventory fisik adalah modul Core masa depan
  terpisah yang akan menerbitkan event yang sudah diketahui cara mengonsumsinya oleh modul ini.
- **Standard costing / analisis varians** (varians material/labor/overhead) — v1 hanya costing
  aktual weighted-average.
- **Workflow approval budget multi-level, forecast rolling, budgeting berbasis driver** — v1
  adalah budget tahunan flat dengan pelaporan varians.
- **Integrasi bank feed otomatis** (API open banking per-bank) — v1 mengimpor file rekening
  koran secara manual; integrasi live feed adalah integrasi per-bank yang mahal-perawatan,
  paling baik dijustifikasi begitu ada kemitraan bank tertentu.
- **Push langsung API Coretax (real-time, per-dokumen)** — v1 mengekspor XML kompatibel-Coretax
  untuk impor bulk (fallback yang secara resmi didukung sesuai panduan FAQ DJP sendiri);
  integrasi API live adalah kandidat untuk ekstraksi microservice yang terjustifikasi
  belakangan (lifecycle auth/runtime berbeda, isolasi dependensi eksternal) begitu volume
  transaksi menjustifikasinya.
- **Engine payroll penuh PPh 21** (perhitungan PTKP, tarif TER, integrasi BPJS, THR) — v1
  mendukung PPh 21 sebagai withholding-type pada pembayaran/baris jurnal mana pun (cukup untuk
  menghasilkan Bukti Potong yang benar untuk biaya profesional, biaya direktur, dll.); modul
  Payroll khusus dengan engine pajak karyawan penuh adalah pembangunan masa depan yang
  terpisah dan lebih besar.
- **Buku ganda multi-book/multi-GAAP paralel** (misalnya buku IFRS + PSAK simultan) — v1
  memiliki satu buku komersial + satu buku depresiasi fiskal (pajak), yang merupakan
  persyaratan statutori Indonesia yang sebenarnya; buku paralel ketiga ditunda sampai klien
  membutuhkannya.
- **E-invoicing di luar Faktur Pajak** (misalnya gaya PEPPOL untuk klien ekspor) — bukan
  persyaratan Indonesia hari ini; tinjau ulang jika/ketika relevan.
- **Analisis finansial lanjutan** (dashboard rasio, forecasting, deteksi anomali via AI) —
  perluasan alami dari `AIInsights Core` begitu modul itu ada; v1 hanya meluncurkan laporan
  statutori.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Snapshot finansial: posisi kas (semua akun bank/kas), total AR + ringkasan aging, total AP +
  ringkasan aging, pendapatan/beban periode-ini sekilas, varians headline budget-vs-aktual,
  jumlah jurnal belum-posted/menunggu-approval, dokumen berulang mendatang yang jatuh tempo.
- Antrean "pekerjaan saya": jurnal menunggu approval saya, tagihan menunggu approval pembayaran
  saya, baris rekening koran yang belum cocok yang ditugaskan ke saya.
- Company switcher (multi-company) selalu terlihat di top bar sesuai konvensi shell
  `DESIGN.md` — setiap widget menghormati company yang dipilih (atau tampilan gabungan "All
  Companies" ketika itu bermakna, misalnya posisi kas).

**Layout**
- Atas: kartu ringkasan (Posisi Kas, AR Outstanding, AP Outstanding, Net Income MTD).
- Utama: bertab — "Approval Saya" | "Jurnal Terbaru" | "Ringkasan Aging" | "Recurring
  Mendatang".
- Setiap baris menggunakan **Status Rail** bersama (sesuai `DESIGN.md`): `danger` = AR/AP
  menunggak atau error posting, `warning` = jatuh tempo segera / menunggu approval, `success` =
  settled/rekonsiliasi, `info` = digenerasi-sistem (auto-recurring, auto-allocation), neutral =
  item terbuka normal.

**Aturan / logika**
- Terlingkup-tenant sesuai global scope default (DB-per-tenant, sesuai `CLAUDE.md` §4);
  ditambah **terlingkup-company** di dalam DB tenant (lihat §5 untuk alasan `company_id`
  dibutuhkan di sini meskipun `CLAUDE.md` §7 mengatakan tanpa kolom `tenant_id` — ini adalah
  sumbu yang berbeda dan diperlukan).
- Hanya transaksi posted (bukan draft) yang memengaruhi balance yang ditampilkan pada
  dashboard.

## 3B. Chart of Accounts & Setup GL

- **COA**: `account_code`, `account_name`, `account_type` (Asset/Liability/Equity/Revenue/
  COGS/Expense — pengelompokan Indonesia: Aset, Liabilitas, Ekuitas, Pendapatan, Harga Pokok
  Penjualan, Beban), `normal_balance` (debit/credit), `parent_account_id` (hierarki),
  `is_control_account` (menandai akun kontrol AR/AP/Persediaan yang menjadi tempat posting
  subledger dan yang tidak dapat menerima baris jurnal manual langsung), `is_active`,
  per-company (sebuah company bisa mulai dari template COA bersama lalu menyimpang).
- **Template COA**: COA standar-Indonesia awal diluncurkan bersama modul (konvensi penomoran
  1xxxx Aset, 2xxxx Liabilitas, 3xxxx Ekuitas, 4xxxx Pendapatan, 5xxxx HPP, 6xxxx Beban)
  sehingga sebuah company baru tidak mulai dari lembar kosong — mendukung langsung tujuan
  "implementasi cepat".
- **Kalender/periode fiskal**: per company, `fiscal_years` + `fiscal_periods` (biasanya
  kalender-bulan), masing-masing dengan `status` (`open` / `soft_closed` / `hard_closed`).
- **Dimensi**: cost center, dan secara opsional project/subject — daftar yang dapat
  dikonfigurasi, dapat dilampirkan pada baris jurnal mana pun untuk pengirisan cost-accounting
  (§3I) tanpa mengubah COA itu sendiri. `ACCOUNTING.cost_centers` adalah master cost-center
  finansial kanonik platform — Purchase (`PURCHASE.cost_centers`) dan HCM (`HCM.org_units`)
  masing-masing secara opsional merujuknya (nullable, informasional — lihat
  `PURCHASE_SPECS.md` §5 dan `HCM_SPECS.md` §5) alih-alih mempertahankan daftar yang
  bernomor-independen, sehingga cost center berarti hal yang sama di mana pun ia digunakan pada
  tenant dengan Accounting terpasang.

**Aturan / logika**
- Akun kontrol (`is_control_account = true`) menolak posting jurnal manual langsung — mereka
  hanya dapat disentuh oleh engine subledger AR/AP/Persediaan, ditegakkan di lapisan service,
  bukan hanya disembunyikan di UI — inilah yang menjamin rekonsiliasi subledger-ke-GL bersifat
  otomatis alih-alih kejar-kejaran manual (sesuai Latar Belakang).

## 3C. General Ledger / Entri Jurnal

- **Entri jurnal manual**: header (tanggal, company, currency, memo, source = `manual`) + N
  baris seimbang (akun, debit/credit, cost center, deskripsi, tautan polimorfik opsional
  `subject_type`/`subject_id` kembali ke apa pun yang memicunya — pola seam yang sama seperti
  WNE/DMS/CRM).
- **Jurnal auto-generated**: setiap aksi subledger (invoice AR, tagihan AP, pembayaran, run
  depresiasi aset, pergerakan persediaan, pemicuan template berulang, event otomasi ERP)
  diposting melalui `JournalService::post()` yang sama — hanya ada **satu** jalur posting di
  seluruh modul, manual atau otomatis, sehingga aturan integritas GL tidak pernah punya dua
  implementasi.
- **Status**: `draft → pending_approval → posted → reversed`. Tidak pernah
  `edited-after-posted` — mengoreksi entri yang sudah posted selalu membuat entri reversing baru
  yang merujuk ke entri asli (integritas audit, sesuai Latar Belakang/§3M).
- List view: dapat difilter berdasarkan company, periode, akun, cost center, status, modul
  sumber. Status Rail per state.
- Detail/drawer: detail baris penuh, jejak audit (siapa membuat/menyetujui/memposting),
  dokumen sumber tertaut (invoice/tagihan/aset/dll.) jika ada.

**Aturan / logika**
- Sebuah jurnal harus seimbang (Σdebit = Σcredit) per currency sebelum dapat keluar dari
  `draft`.
- Posting ke periode `hard_closed` diblokir sepenuhnya; `soft_closed` mengizinkan posting hanya
  dengan approval yang ditingkatkan (via WNE) dan mencatat pengecualian.
- Posting di atas ambang tenant yang dapat dikonfigurasi (jumlah, atau menyentuh akun sensitif
  tertentu) melalui WNE (`WorkflowRequested`, `workflow_code = accounting.journal_approval`)
  sebelum berpindah ke `posted` — Accounting tidak mengimplementasikan logika approval sendiri,
  pola yang sama yang sudah dipakai CRM/DMS untuk WNE.

## 3D. Accounts Receivable (AR)

- **Invoice pelanggan**: header (partner — diresolusi via `partners` milik CRM, company,
  currency, tanggal terbit/jatuh tempo, perlakuan PPN, `invoice_type` — `standard` /
  `deposit`, field aditif — `subject_type`/`subject_id` kembali ke record sumber — dalam
  praktiknya selalu `sales.so_lines`, karena **Sales** adalah satu-satunya caller sisi-AR
  platform (§3R) dan setiap invoice, termasuk retainer Legal, tiba via Billing Engine-nya
  (`SALES_SPECS.md` §3I). Provenance penuh untuk invoice yang berasal dari vertikal (misalnya
  matter Legal mana yang menjadi milik retainer) masih satu langkah jauhnya: Sales Order itu
  sendiri membawa `subject_type = 'legal.matters'`/`'legal.deeds'` sesuai
  `SALES_SPECS.md` §3F, sehingga Accounting tidak pernah butuh pointer berbentuk-Legal langsung
  miliknya sendiri) + item baris (deskripsi, qty, harga, diskon, kode pajak). Saat posting:
  membuat jurnal akun kontrol AR + baris pajak PPN output jika berlaku + (jika dikonfigurasi)
  record Faktur Pajak (§3M). Memicu `InvoicePosted` kembali ke modul pemohon (membawa
  `subject_type`/`subject_id` yang diberikan padanya) sehingga modul itu dapat memperbarui
  status lokalnya sendiri (misalnya `so_lines.qty_invoiced` milik Sales) — Accounting tidak
  pernah perlu tahu apa itu "baris Sales Order" di luar pointer itu.
- **Aplikasi pembayaran**: menerima pembayaran (penuh/parsial), menerapkan terhadap satu atau
  lebih invoice terbuka (default paling-lama-dulu, dapat dioverride manual), diposting ke
  Kas/Bank + kontrol AR. Pembayaran invoice deposit diterapkan dengan cara yang sama — sebagai
  kredit terhadap invoice belakangan — alih-alih membutuhkan konsep "deposit ledger" terpisah.
  Memicu `PaymentRecorded` (dengan `subject_type`/`subject_id` milik invoice) sehingga modul
  pemohon (misalnya Commission Engine milik Sales) dapat bereaksi tanpa polling tabel
  Accounting secara langsung.
- **Credit note**: mengurangi invoice atau berdiri sendiri terhadap balance partner.
- **Laporan Aging AR**: bucket current / 1-30 / 31-60 / 61-90 / 90+, per partner, drill ke
  invoice terbuka.
- List/detail view menggunakan ulang Data Table + Status Rail + komponen Comment/Activity
  Thread bersama sesuai `DESIGN.md`, konsisten dengan tampilan Service Cases dan Helpdesk milik
  CRM.

**Aturan / logika**
- Partner invoice berasal dari tabel `partners` terpadu milik **CRM** — Accounting tidak
  pernah mempertahankan master pelanggan miliknya sendiri, aturan "Core menggunakan ulang Core"
  yang sama yang sudah ditetapkan antara DMS/CRM/WNE. Seorang partner tidak butuh peran
  "customer" khusus untuk diinvoice; partner mana pun dapat menerima invoice AR.
- **Accounting adalah satu-satunya ledger AR di platform ini.** Billing Engine milik modul
  Sales (`SALES_SPECS.md` §3I) tidak mempertahankan tabel invoice/pembayaran miliknya sendiri;
  ia meminta invoice dan pencatatan pembayaran di sini via
  `InvoiceRequested`/`PaymentRequested`, sama seperti modul vertikal mana pun akan
  melakukannya. Ini adalah aturan platform-wide, bukan detail integrasi khusus-Sales: tidak
  ada modul yang diizinkan mempertahankan ledger AR paralel — melakukan itu akan merusak
  jaminan akun-kontrol di bawah dan berisiko menerbitkan invoice tanpa perlakuan PPN yang
  benar.
- Saldo akun kontrol AR selalu tepat merupakan jumlah balance invoice terbuka — ditegakkan
  dengan memposting invoice/pembayaran melalui panggilan service transaksional yang sama, bukan
  direkonsiliasi belakangan.
- Penyesuaian kredit/debit pada invoice ditangani secara eksklusif via **Credit note** /
  `ar_credit_notes` (di atas) — `invoice_type` secara sengaja tidak membawa nilai
  `credit_memo`, sehingga hanya ada satu representasi penyesuaian kredit di skema, bukan dua
  yang bersaing.

## 3E. Accounts Payable (AP)

- **Tagihan vendor**: mencerminkan invoice AR secara struktural — header (partner via CRM,
  company, currency, tanggal jatuh tempo, `subject_type`/`subject_id` kembali ke record sumber
  — paling umum `purchase.pur_invoice_hdrs`, invoice vendor yang sudah dicocokkan milik modul
  **Purchase**, `PURCHASE_SPECS.md` §3F; tagihan biaya langsung kasus Legal adalah kasus umum
  lainnya) + baris, dengan withholding PPh dihitung jika berlaku (tenant adalah agen
  withholding — misalnya PPh 23 pada tagihan layanan profesional) dan record Bukti Potong
  dibuat saat posting (§3M). Dibuat dari event/panggilan facade `BillRequested` — Accounting
  tidak pernah menangkap atau mencocokkan tagihan vendor sendiri; langkah intake/three-way-match
  itu adalah pekerjaan Purchase (atau, untuk tenant tanpa Purchase, entri manual langsung di
  sini).
- **Penjadwalan & approval pembayaran**: tagihan mengantre untuk pembayaran sesuai tanggal
  jatuh tempo; payment run (tunggal atau batch) melalui approval WNE di atas ambang yang dapat
  dikonfigurasi sebelum disbursement diposting — seam approval yang sama seperti jurnal (§3C),
  digunakan ulang alih-alih diciptakan ulang. Ini adalah jawaban konkret untuk "siapa yang
  mengeksekusi pembayaran," sebelumnya item terbuka di `PURCHASE_SPECS.md` §5: Accounting yang
  melakukannya, di sini — `purchase.invoice_approval` milik Purchase sendiri (§3F di
  `PURCHASE_SPECS.md`) hanya memvalidasi bahwa sebuah tagihan sah dan cocok dengan apa yang
  dipesan/diterima; ini adalah gerbang terpisah dan belakangan yang benar-benar mengotorisasi
  disbursement.
- Memicu `BillPosted` saat pencatatan AP dan `PaymentRecorded` saat disbursement, keduanya
  membawa `subject_type`/`subject_id` asal, sehingga modul pemohon (Purchase, atau Legal untuk
  biaya langsung) dapat memperbarui statusnya sendiri tanpa Accounting mengetahui skema modul
  itu.
- **Laporan Aging AP**: mencerminkan aging AR.
- **Debit note**: mencerminkan credit note AR.

**Aturan / logika**
- Saldo akun kontrol AP selalu tepat merupakan jumlah balance tagihan terbuka, aturan
  integritas transaksional yang sama seperti AR.
- Baris pajak withholding mengurangi jumlah yang benar-benar dibayarkan ke vendor tetapi tidak
  mengurangi beban gross yang diakui — kedua jumlah terlihat pada tagihan dan Bukti Potong yang
  dihasilkan.

## 3F. Manajemen Kas & Bank

- **Akun kas/bank**: daftar master (nama bank, nomor rekening — disamarkan di UI kecuali 4
  digit terakhir, currency, akun kas GL tertaut, company).
- **Kas masuk / kas keluar**: entri penerimaan/disbursement sederhana yang tidak terikat pada
  dokumen AR/AP (misalnya petty cash, biaya bank, pendapatan bunga).
- **Transfer antar-akun**: memindahkan dana antara dua akun kas/bank (termasuk lintas-currency,
  menggunakan rate hari itu).
- **Impor rekening koran**: upload CSV (dan ekspor gaya MT940 umum); diparsing menjadi baris
  rekening koran staged untuk rekonsiliasi (§3Q).

**Aturan / logika**
- Setiap akun GL kas/bank sendiri ditandai dapat-direkonsiliasi; item yang belum direkonsiliasi
  selama N-hari dapat secara opsional memicu notifikasi WNE ke pemilik akun (config, off
  secara default di MVP).

## 3G. Fixed Assets

- **Register aset**: akuisisi (tanggal, biaya, vendor via CRM, company, kelompok aset sesuai
  klasifikasi pajak Indonesia — Kelompok 1-4 untuk berwujud non-bangunan, ditambah Bangunan
  Permanen/Non-Permanen untuk gedung), masa manfaat, metode depresiasi komersial (garis-lurus
  default; declining-balance opsional), metode/tarif depresiasi fiskal (per kelompok aset,
  sesuai aturan PMK yang berlaku — tabel yang dapat dikonfigurasi, bukan hardcoded, karena
  tarif diatur oleh regulasi dan dapat berubah).
- **Run depresiasi**: job batch bulanan memposting depresiasi komersial ke GL; depresiasi
  fiskal dilacak dalam jadwal paralel (dipakai untuk pelaporan pajak / rekonsiliasi SPT
  Tahunan, tidak diposting secara terpisah ke GL komersial — ini pendekatan standar
  dual-book komersial-vs-fiskal, bukan dua ledger).
- **Disposal**: penjualan/write-off, menghitung dan memposting laba/rugi atas disposal terhadap
  baik NBV komersial maupun (untuk jadwal fiskal) NBV fiskal.

**Aturan / logika**
- Kelompok aset dan tarif fiskalnya adalah tabel lookup yang dapat diedit tenant, di-seed
  dengan default Indonesia terkini saat setup — tidak pernah hardcoded dalam logika aplikasi,
  karena regulasi pajak berubah dan solo dev seharusnya tidak perlu deploy kode untuk
  memperbarui tabel tarif depresiasi.
- Sebuah aset secara opsional dapat menautkan (`subject_type`/`subject_id`) ke tagihan AP yang
  menciptakannya, untuk traceability, tanpa FK keras (seam polimorfik yang sama seperti di
  tempat lain).

## 3H. Posting GL Persediaan (interface engine — tanpa costing, tanpa stok fisik)

- **Tujuan**: memposting sisi *finansial* pergerakan persediaan saja. Baik persediaan fisik
  (kuantitas, gudang, stock-take) maupun cost/valuasi (lapisan FIFO atau weighted-average, unit
  cost saat ini) sepenuhnya dimiliki modul **Inventory** (`INVENTORY_SPECS.md`) — Accounting
  tidak mempertahankan ledger paralel untuk salah satunya.
- Mengonsumsi `inventory.goods_received`, `inventory.goods_issued`, `inventory.stock_adjusted`
  (event yang diterbitkan, sesuai `INVENTORY_SPECS.md` §5). Payload setiap event mencakup item,
  kuantitas, tipe pergerakan, unit cost, dan total value yang sudah dihitung oleh
  `CostingStrategyInterface` milik Inventory — ditambah pointer `subject_type =
  'inventory.stock_ledger'` / `subject_id` kembali ke baris ledger asal, untuk traceability.
- **Pada setiap event**: memposting jurnal melalui jalur `JournalService::post()` tunggal yang
  sama seperti setiap transaksi Accounting lain (§3C) — akun Aset-Persediaan
  debit/credit terhadap COGS (issue), GRNI/akrual-AP (receipt), atau akun
  Adjustment/write-off (adjustment) — menggunakan nilai yang sudah dihitung Inventory, tidak
  pernah angka yang dihitung ulang secara lokal.
- **Pelaporan valuasi persediaan** (nilai on-hand berdasarkan item/gudang/kategori) adalah
  laporan Inventory sendiri (`INVENTORY_SPECS.md` §3I) — Accounting tidak menduplikasinya.
  Kepedulian Accounting sendiri lebih sempit: bahwa saldo **akun kontrol** Aset-Persediaan
  miliknya rekonsiliasi dengan total valuasi Inventory — disiplin akun-kontrol yang sama yang
  sudah diterapkan pada AR/AP (§3D/§3E), sebuah laporan verifikasi, bukan sumber kedua untuk
  nilai.
- Jika Inventory tidak terpasang/aktif untuk sebuah tenant, engine ini sekadar inert (tanpa
  event untuk dikonsumsi) — postur "tidak boleh throw jika X tidak ada" yang sama yang dipakai
  setiap dependensi lintas-modul lunak lain di platform ini.

**Aturan / logika**
- Engine ini menyimpan **nol** logika costing — tanpa perhitungan weighted-average, tanpa
  konsumsi lapisan FIFO — secara sengaja. Angka cost apa pun yang melewati Accounting selalu
  nilai yang diterima dari Inventory, tidak pernah dihitung di sini.
- Event pergerakan untuk sebuah item tanpa pemetaan akun GL (item baru, kategori belum
  dipetakan) gagal secara nyaring dan masuk antrean untuk ditinjau alih-alih diposting ke akun
  suspense secara diam-diam — disiplin "tanpa auto-apply diam-diam" yang sama yang dipakai
  DMS untuk aksi retensi.

## 3I. Cost Accounting

- **Cost center**: daftar flat sederhana (atau one-level-hierarchy), dapat diedit tenant, dapat
  dilampirkan sebagai dimensi pada baris jurnal/AR/AP/aset mana pun (§3B). Ini adalah daftar
  cost-center kanonik untuk tenant (lihat §3B) — pemeriksaan budget Purchase sendiri
  (`PURCHASE_SPECS.md` §3B/§3J) dan struktur organisasi HCM (`HCM_SPECS.md` §3C) dapat secara
  opsional memetakan ke dalamnya, sehingga "cost center" berarti satu dimensi konsisten lintas
  pelaporan finansial, procurement, dan HR alih-alih tiga daftar yang dikelola secara
  independen.
- **Run alokasi**: mendefinisikan aturan (akun/cost center sumber → cost center target +
  pembagian persentase), dijalankan bulanan untuk mendistribusikan-ulang biaya bersama
  (misalnya sewa kantor dibagi lintas tim kasus) — memposting jurnal, tidak pernah mengubah
  entri asli.

**Aturan / logika**
- Alokasi hanya berbasis persentase di v1 (tanpa activity-based/driver costing) — sesuai bias
  "implementasi cepat"; model dimensi pada baris jurnal cukup fleksibel sehingga engine alokasi
  yang lebih cerdas dapat ditambahkan belakangan tanpa perubahan skema.

## 3J. Budgeting

- **Budget**: per company, tahun fiskal, akun × cost center × periode (bulanan), jumlah.
  Dientri via grid mirip-spreadsheet (mendukung paste-bulk) atau impor CSV.
- **Laporan Budget vs. Aktual**: varians (jumlah + %) berdasarkan akun/cost center/periode,
  drill ke detail GL aktual dari baris varians mana pun.

**Aturan / logika**
- Satu versi budget tahunan flat per tahun fiskal di v1 — tanpa versioning
  revisi/skenario (itu Future Version, sesuai §2), menjaga skema dan UI tetap sederhana.
- Budget ini secara sengaja presisi-akun dan terlingkup-company — ini bukan record yang sama
  dengan budget berbasis subject/category milik **Performance** (`PERFORMANCE_SPECS.md` §3B).
  Sebuah tenant yang menjalankan kedua modul dapat secara opsional memetakan kategori budget
  Performance ke satu atau lebih akun di sini (`PERF.budget_category_accounts`) sehingga
  Variance Engine Performance membaca aktivitas GL nyata alih-alih angka kedua yang diketik
  manual — Accounting tidak mengekspos apa pun yang baru untuk ini
  (`AccountingService::getAccountBalance(...)` sudah mencakupnya), dan sebaliknya tidak
  memiliki pengetahuan apa pun tentang Performance, arah baca satu-arah yang sama yang dipakai
  di tempat lain di platform ini.

## 3K. Multi Company

- **Companies**: daftar master di dalam DB tenant — nama legal, NPWP (ID pajak Indonesia),
  alamat, base currency, bulan mulai tahun fiskal, referensi template COA aktif.
- **Company switcher**: selector konteks global (top bar, sesuai shell `DESIGN.md`) melingkupi
  setiap layar di modul ini; seorang user dapat memiliki akses ke satu atau beberapa company.
- **Pelaporan gabungan**: trial balance / P&L / balance sheet dapat dijalankan "gabungan" lintas
  company yang dipilih (penjumlahan sederhana berdasarkan kecocokan kode akun) — secara
  eksplisit *bukan* konsolidasi dengan eliminasi (Future Version, §2).

**Aturan / logika**
- Setiap tabel Accounting membawa `company_id` (lihat §5 untuk rasionalisasi arsitekturalnya).
  Setiap modul lain yang memposting ke Accounting (Legal, CRM, DMS, Schedule) harus
  menentukan company mana yang menjadi milik sebuah transaksi — biasanya diresolusi dari
  konfigurasi company default level-tenant, dapat dioverride per transaksi.

## 3L. Multi Currency

- **Currency & rate**: daftar currency ISO (tenant mengaktifkan yang dipakai), tabel
  `exchange_rates` harian (rate terhadap base currency, tanggal efektif, source = entri manual
  atau driver rate-feed masa depan).
- **Transaksi mata uang asing**: baris AR/AP/jurnal dapat dientri dalam mata uang asing;
  disimpan baik pada jumlah currency-transaksi maupun ekuivalen-base-currency (rate pada
  tanggal transaksi).
- **Laba/rugi terealisasi**: dihitung dan diposting secara otomatis ketika invoice/tagihan
  mata uang asing di-settle pada rate yang berbeda dari saat dibukukan.
- **Revaluasi belum-terealisasi**: job batch period-end merevaluasi balance AR/AP/kas mata
  uang asing terbuka ke rate period-end, memposting jurnal laba/rugi belum-terealisasi
  (auto-reversed di awal periode berikutnya, praktik standar).

**Aturan / logika**
- Base currency tetap per company saat setup (cocok dengan currency pelaporan statutorinya —
  IDR untuk entitas Indonesia); semua laporan keuangan melaporkan dalam base currency, dengan
  currency-transaksi ditampilkan sebagai detail suplementer pada dokumen sumber.

## 3M. Engine Pajak Indonesia

**PPN (Pajak Pertambahan Nilai)**
- Kode pajak (`tax_codes`): tarif, tipe (output/input), pemetaan akun — dapat dikonfigurasi
  sehingga perubahan tarif adalah edit data, bukan deploy.
- **Faktur Pajak** (invoice VAT) dibuat saat posting invoice AR yang kena pajak: penomoran
  sekuensial per company (blok Nomor Seri Faktur Pajak, dientri-tenant dari alokasi DJP
  mereka), NPWP/NIK pembeli, dasar pengenaan pajak, jumlah PPN.
- **PPN Input** ditangkap pada tagihan AP (dapat dikreditkan terhadap PPN output pada SPT Masa
  PPN bulanan).

**PPh (Pajak Withholding)**
- Tipe withholding dapat dikonfigurasi, MVP mencakup PPh 23 (jasa), PPh 4(2) (final, misalnya
  sewa), PPh 21 (non-payroll: biaya profesional/direktur) — dimodelkan secara generik sebagai
  `withholding_types` (kode, tarif, is_final) sehingga menambahkan PPh 22/15 belakangan adalah
  config, bukan kode.
- **Bukti Potong** dibuat saat posting tagihan/pembayaran yang terkena withholding — satu
  record per event withholding, cocok dengan struktur yang diharapkan modul e-Bupot DJP
  (BP21/BP26/BP23/BP4(2)/BPU sesuai klasifikasi e-Bupot Coretax saat ini).

**Integrasi Coretax**
- Sejak 2026, DJP mewajibkan administrasi pajak (Faktur Pajak, Bukti Potong, filing SPT)
  dilakukan **melalui Coretax** — aplikasi lama e-Faktur/e-Bupot/DJP Online sudah dipensiunkan.
  Coretax secara resmi mendukung software pembukuan pihak ketiga via **impor XML terstruktur**
  (jalur fallback yang sama yang ditunjuk DJP sendiri kepada wajib pajak saat entri langsung
  gagal), sehingga v1 menargetkan permukaan integrasi itu alih-alih API live.
- **`CoretaxExportDriver`**: menghasilkan batch XML kompatibel-DJP untuk Faktur Pajak Keluaran
  (output), Faktur Pajak Masukan (input, untuk rekonsiliasi), dan Bukti Potong (PPh
  Unifikasi/21), on demand atau per periode pajak — diunduh dan diimpor ke Coretax oleh
  penyusun pajak tenant. Dibangun di balik interface driver (mencerminkan
  `ChannelDriverInterface` / `ConferenceDriverInterface`) sehingga driver API langsung masa
  depan bersifat aditif, bukan penulisan ulang.
- **Register periode pajak**: kewajiban PPN dan PPh dilacak per company per periode (masa
  pajak), dengan pengingat jatuh-tempo via WNE (SPT Masa PPN: akhir bulan berikutnya;
  penyetoran withholding PPh: tanggal 10 bulan berikutnya; keduanya dapat dikonfigurasi karena
  aturan tanggal-jatuh-tempo dapat berubah).

**Aturan / logika**
- Tarif pajak, tarif withholding, dan aturan tanggal-jatuh-tempo berada dalam tabel lookup
  yang dapat diedit tenant, tidak pernah hardcoded — perubahan regulasi (seperti yang pernah
  terjadi sebelumnya, misalnya perubahan tarif PPN) tidak boleh pernah membutuhkan deploy
  kode.
- Setiap Faktur Pajak dan Bukti Potong bersifat immutable setelah diterbitkan — koreksi
  terjadi via record penggantian/pembatalan yang merujuk ke yang asli, mencerminkan model
  replace/cancel milik Coretax sendiri (sesuai FAQ DJP) alih-alih edit-di-tempat.

## 3N. Financial Analysis / Reporting

- **Trial Balance**: per company, per periode, dengan drill-down ke detail GL.
- **Balance Sheet** (Neraca) dan **P&L** (Laporan Laba Rugi): pengelompokan penyajian
  standar-PSAK, periode berjalan + periode pembanding sebelumnya, single company atau
  gabungan.
- **Cash Flow Statement**: metode tidak langsung, diturunkan dari pergerakan Balance Sheet +
  P&L (tidak butuh entri data cash-flow terpisah).
- **Aging AR/AP**, **Budget vs. Aktual**, **Valuasi Persediaan**: menggunakan ulang laporan
  engine masing-masing (§3D/E/H/J) ditampilkan di sini sebagai hub pelaporan terpadu.
- Semua laporan dapat diekspor (PDF via pola skill `pdf` yang sudah ada / Excel via `xlsx`),
  karena laporan statutori rutin perlu keluar dari aplikasi (auditor, penyusun pajak, bank).

**Aturan / logika**
- Setiap laporan statutori (Balance Sheet, P&L) dihasilkan **hanya** dari data GL yang
  posted — tidak ada laporan yang pernah mencerminkan jurnal draft/belum-disetujui, yang
  membuat angka-angka tersebut cukup dapat dipercaya untuk brief "trust, precision" di
  `DESIGN.md`.

## 3O. Audit & Compliance

- `acct.audit_logs`: append-only, satu baris per aksi (`journal_created`, `journal_posted`,
  `journal_reversed`, `period_closed`, `period_reopened`, `invoice_posted`, `bill_posted`,
  `payment_posted`, `tax_document_issued`, `tax_document_cancelled`, `master_data_changed`),
  aktor, timestamp, snapshot sebelum/sesudah untuk perubahan master-data — pola immutable yang
  sama seperti `dms.access_logs`.
- **Period locking**: soft-close (memblokir posting biasa, mengizinkan pengecualian
  approval-tinggi) dan hard-close (memblokir semua posting ke periode itu, termasuk
  pengecualian — hanya dapat dibalik dengan secara eksplisit membuka-ulang, itu sendiri aksi
  yang diaudit dan gated-approval).
- **Workflow approval** untuk posting/pembayaran di atas ambang, digunakan ulang dari WNE
  (§3C/E) — log audit menangkap referensi rantai approval WNE, bukan record approval
  duplikat.

**Aturan / logika**
- Tidak ada update/delete yang diizinkan pada `acct.audit_logs` atau pada jurnal/dokumen
  pajak posted mana pun di lapisan aplikasi — cocok dengan aturan integritas-audit DMS; ini
  persyaratan kepatuhan, bukan preferensi gaya, untuk baik ekspektasi jejak audit PSAK maupun
  model replace-not-edit milik Coretax sendiri.

## 3P. Recurring Transactions

- **Template jurnal berulang**: pola header + baris, `recurrence_rule` (RRULE, menggunakan
  ulang pendekatan dan library recurrence modul Schedule — `simshaun/recurr` — alih-alih
  implementasi kedua), tanggal-run-berikutnya.
- **Template AR/AP berulang**: pola yang sama untuk invoice/tagihan (misalnya retainer
  bulanan, tagihan sewa kantor bulanan).
- **Antrean generasi**: job terjadwal (dipicu via pola `schedule.item_due_soon` modul
  Schedule, atau cron internal sederhana jika Schedule tidak terpasang untuk tenant yang hanya
  mandiri) membuat dokumen/jurnal **draft** dari template — tidak pernah auto-posting —
  sehingga seorang manusia meninjau sebelum masuk GL, sesuai disiplin "tanpa auto-apply
  diam-diam" yang sama yang dipakai DMS untuk auto-tagging.

**Aturan / logika**
- Jika modul Schedule aktif untuk tenant, template berulang secara opsional muncul pada
  kalender bersama (via `subject_type`/`subject_id`) sehingga "kapan invoice retainer
  berikutnya" terlihat di satu tempat — tetapi Accounting harus berfungsi mandiri jika
  Schedule tidak ada, aturan kebebasan-feature-flag yang sama yang diikuti Schedule sendiri
  terhadap WNE.

## 3Q. Reconcile

- **Rekonsiliasi bank**: mencocokkan baris rekening koran yang diimpor (§3F) terhadap
  transaksi akun-kas GL — auto-match berdasarkan jumlah + kedekatan-tanggal +
  kemiripan-string-referensi dulu, pencocokan manual untuk sisanya, dengan tampilan balance
  reconciled-vs-book yang berjalan (worksheet bank-rec klasik).
- **Rekonsiliasi kontrol AR/AP**: bukan masalah pencocokan dalam desain ini (lihat §3D/E) —
  layar ini adalah laporan verifikasi saja (saldo akun kontrol = jumlah item terbuka), berguna
  sebagai pemeriksaan trust/audit, bukan tugas rekonsiliasi manual.

**Aturan / logika**
- Baris rekening koran yang belum cocok lebih lama dari ambang yang dapat dikonfigurasi dapat
  memicu notifikasi WNE ke pemilik akun — aturan gunakan-ulang-WNE-jangan-bangun-jalur-paralel
  yang sama seperti retensi DMS dan pelanggaran SLA CRM.

## 3R. Otomasi dari ERP

- **Facade `AccountingService`** (disukai, same-process): `postJournal(...)`,
  `createInvoice(...)`, `createBill(...)`, `recordPayment(...)`, `getAccountBalance(...)` —
  untuk modul Core atau Vertical mana pun yang perlu menyentuh finansial tanpa mengetahui
  aturan double-entry.
- **Event bus** (terdekopel, disukai untuk pemicu lintas-modul): `JournalPostingRequested`,
  `InvoiceRequested`, `BillRequested`, `PaymentRequested` — **Sales adalah satu-satunya caller
  sisi-AR**: Billing Engine-nya (`SALES_SPECS.md` §3I) memicu `InvoiceRequested` untuk setiap
  invoice order/pengiriman/kontrak-berulang, dan untuk setiap permintaan billable modul
  vertikal yang diterimanya (misalnya milik Legal — lihat `LEGAL_SPECS.md` §2 dan
  `SALES_SPECS.md` §3I/§5), dan `PaymentRequested` untuk setiap pembayaran yang dicatat, alih-
  alih mempertahankan tabel AR-nya sendiri. Accounting tidak pernah menerima
  `InvoiceRequested` dari modul vertikal secara langsung — hanya dari Sales — sehingga hanya
  ada satu caller sisi-AR di platform ini, bukan satu per vertikal. Event bergaya
  `ServiceCaseSLABreached` milik CRM dapat memicu workflow nota kredit dengan cara yang sama,
  juga via Sales.
**Purchase adalah konsumen utama `BillRequested`**: three-way-match approval-nya
(`PURCHASE_SPECS.md` §3F) memicunya untuk setiap tagihan vendor, alih-alih Purchase
mempertahankan ledger AP-nya sendiri.

- **Event yang dikonsumsi**: `inventory.goods_received` / `inventory.goods_issued` /
  `inventory.stock_adjusted` (§3H, dari modul **Inventory** — lihat `INVENTORY_SPECS.md` §5 —
  sumber kebenaran kuantitas dan valuasi; Accounting hanya memposting jurnal yang dihasilkan),
  `LeadConverted`/`PartnerCreated` (dari CRM, untuk meresolusi referensi partner AR/AP).
  Accounting tidak berlangganan event billable modul vertikal mana pun secara langsung — itu
  akan mengharuskan Accounting (Core) memiliki pengetahuan spesifik tentang nama event modul
  Vertical, yang tidak diperbolehkan `CLAUDE.md` §2. Permintaan billing vertikal melalui
  **Sales** dulu (`SALES_SPECS.md` §3I/§5), yang mengekspos titik masuk generik
  `SalesOrderRequested` miliknya sendiri persis untuk tujuan ini dan, begitu sebuah Sales
  Order siap, memicu event `InvoiceRequested` biasa yang sudah dikonsumsi Accounting.

**Aturan / logika**
- Accounting tidak pernah menjangkau ke dalam skema modul pemicu untuk menghitung apa yang
  harus ditagih — modul pemicu meresolusi jumlah/deskripsi billable-nya sendiri dan
  menyerahkan Accounting permintaan yang sudah terbentuk sepenuhnya; satu-satunya pekerjaan
  Accounting adalah pencatatan finansial yang benar, aturan dependensi-satu-arah yang sama
  (Vertical/Core → Accounting, tidak pernah sebaliknya) seperti di tempat lain di codebase
  ini.
- Accounting melapor balik via `InvoicePosted`/`PaymentRecorded` (`JournalPosted`/`BillPosted`
  untuk AP), masing-masing membawa `subject_type`/`subject_id` yang sama yang diberikan
  padanya — sebuah echo status read-only, bukan Accounting menjangkau balik ke dalam modul
  pemicu. Modul pemicu (Sales, Purchase, Legal, ...) memutuskan apa yang dilakukan dengannya
  (Sales memperbarui `so_lines.qty_invoiced` dan memicu settlement Commission, sesuai
  `SALES_SPECS.md` §3I/§3M; Purchase memperbarui `pur_invoice_hdrs.status` dan menutup PO asal,
  sesuai `PURCHASE_SPECS.md` §3F).

## 3S. Posting GL Payroll (interface engine — tanpa perhitungan statutori, tanpa pemrosesan run)

- **Tujuan**: memposting sisi *finansial* dari sebuah payroll run yang sudah selesai dan
  dibayar saja. Semua perhitungan statutori (PPh 21, BPJS, THR, pesangon), pemrosesan run, dan
  disbursement sepenuhnya dimiliki modul **Payroll** (`PAYROLL_SPECS.md`) — Accounting tidak
  menghitung-ulang atau menduplikasi apa pun darinya, pembagian tanggung jawab yang sama yang
  sudah ditetapkan untuk costing Inventory (§3H di atas).
- Mengonsumsi `payroll.run_paid` (diterbitkan saat sebuah payroll run terkunci, sesuai
  `PAYROLL_SPECS.md` §3-Admin). Payload event mencakup total GL-relevan per-komponen milik
  run — beban gaji/upah gross, net pay payable, PPh 21 yang di-withhold, BPJS Kesehatan
  (karyawan + pemberi kerja), BPJS Ketenagakerjaan (karyawan + pemberi kerja, per sub-program),
  potongan statutori/non-statutori lainnya dan biaya pemberi kerja — sebagaimana sudah
  dihitung engine kalkulasi Payroll (`PAYROLL_SPECS.md` §3J), plus pointer
  `subject_type = 'payroll.payroll_runs'` / `subject_id` kembali ke run asal, untuk
  traceability.
- **Pada setiap event**: memposting jurnal melalui jalur `JournalService::post()` tunggal yang
  sama seperti setiap transaksi Accounting lain (§3C) — Beban Gaji/Upah (dan Beban BPJS
  Pemberi-Kerja) di-debit, Net Pay Payable (atau Bank, jika disbursement dalam
  transaksi-yang-sama) di-credit, PPh 21 Payable di-credit, BPJS Payable (karyawan + pemberi
  kerja) di-credit — menggunakan angka yang sudah dihitung Payroll, tidak pernah angka yang
  dihitung ulang secara lokal.
- Jika Payroll tidak terpasang/aktif untuk sebuah tenant, engine ini sekadar inert (tanpa
  event untuk dikonsumsi) — postur "tidak boleh throw jika X tidak ada" yang sama yang sudah
  dipakai setiap dependensi lintas-modul lunak lain di platform ini (§3H, §3P).

**Aturan / logika**
- Engine ini menyimpan **nol** logika perhitungan payroll — tanpa matematika PPh 21/TER,
  tanpa matematika kontribusi BPJS, tanpa formula pesangon — secara sengaja. Angka apa pun
  yang melewati Accounting di sini selalu nilai yang diterima dari Payroll, tidak pernah
  dihitung di sini, disiplin yang sama yang sudah diterapkan §3H pada costing Inventory.
- Sebuah payroll run yang salah satu komponennya tidak memiliki pemetaan akun GL (Komponen
  Payroll baru/belum dipetakan) gagal secara nyaring dan masuk antrean untuk ditinjau
  alih-alih diposting ke akun suspense secara diam-diam — disiplin "tanpa auto-apply
  diam-diam" yang sama yang sudah diterapkan §3H pada item/kategori Inventory yang belum
  dipetakan.
- Ekspor ringkasan biaya CSV flat milik Payroll sendiri (`PAYROLL_SPECS.md` §3-Reports) tetap
  menjadi laporan pendamping yang dapat dibaca-manusia untuk staf HR/finance — ia bukan
  sumber kebenaran GL; jurnal posted engine ini yang menjadi sumber kebenaran itu.

---

# 4. Penyimpanan

> Tabel dan file objek yang dipakai modul ini. Schema: `ACCOUNTING` (per DB tenant, sesuai
> `CLAUDE.md` §7). Penamaan: tabel master satu kata; tabel transaksi diprefiks domain
> (`gl_`, `ar_`, `ap_`, `fa_`, `tax_`, ...), cocok dengan konvensi yang dipakai spec modul Core
> lain.

**Tabel setup / master**
- `ACCOUNTING.companies` — master entitas legal (name, NPWP, base_currency,
  fiscal_year_start).
- `ACCOUNTING.accounts` — Chart of Accounts (per company), flag `is_control_account`.
- `ACCOUNTING.fiscal_years`, `ACCOUNTING.fiscal_periods` — per company, dengan `status`.
- `ACCOUNTING.cost_centers` — lookup dimensi.
- `ACCOUNTING.currencies`, `ACCOUNTING.exchange_rates`.
- `ACCOUNTING.tax_codes` (PPN), `ACCOUNTING.withholding_types` (keluarga PPh).
- `ACCOUNTING.asset_groups` — klasifikasi + tarif depresiasi fiskal Indonesia.
- `ACCOUNTING.bank_accounts` — master kas/bank, akun GL tertaut.

**Tabel transaksi GL**
- `ACCOUNTING.gl_journals` — header (company, periode, currency, status, modul sumber,
  `subject_type`/`subject_id`).
- `ACCOUNTING.gl_journal_lines` — akun, debit/credit, cost center, jumlah currency + jumlah
  base-currency.

**AR**
- `ACCOUNTING.ar_invoices`, `ACCOUNTING.ar_invoice_lines`, `ACCOUNTING.ar_payments`,
  `ACCOUNTING.ar_payment_applications`, `ACCOUNTING.ar_credit_notes`.

**AP**
- `ACCOUNTING.ap_bills`, `ACCOUNTING.ap_bill_lines`, `ACCOUNTING.ap_payments`,
  `ACCOUNTING.ap_payment_applications`, `ACCOUNTING.ap_debit_notes`.

**Kas & Bank**
- `ACCOUNTING.cash_transactions`, `ACCOUNTING.bank_statement_imports`,
  `ACCOUNTING.bank_statement_lines`, `ACCOUNTING.bank_reconciliations`,
  `ACCOUNTING.bank_reconciliation_matches`.

**Fixed Assets**
- `ACCOUNTING.fa_assets`, `ACCOUNTING.fa_depreciation_schedule_commercial`,
  `ACCOUNTING.fa_depreciation_schedule_fiscal`, `ACCOUNTING.fa_disposals`.

**Posting GL Persediaan**
- `ACCOUNTING.inv_gl_account_map` — memetakan referensi `INVENTORY.products` /
  `product_categories` ke akun GL Aset-Persediaan / COGS / GRNI / Adjustment miliknya (per
  company) — satu-satunya master data khusus-inventory yang dimiliki Accounting.
- `ACCOUNTING.inv_posting_log` — satu baris per event Inventory yang dikonsumsi
  (`inventory.goods_received` / `goods_issued` / `stock_adjusted`), `gl_journals.id` yang
  dihasilkan, dan `subject_type`/`subject_id` asal (baris `INVENTORY.stock_ledger`) — log
  idempotensi/traceability, bukan ledger valuasi. Tidak ada kolom kuantitas, unit-cost, atau
  running-balance yang berada di sini; itu tetap eksklusif di `INVENTORY.stock_ledger` /
  `stock_valuation_layers` / `stock_balances`.

**Posting GL Payroll**
- `ACCOUNTING.payroll_gl_account_map` — memetakan referensi `PAYROLL.payroll_components`
  (dan sekumpulan kategori statutori tetap yang kecil — net pay payable, PPh 21 payable, BPJS
  payable) ke akun expense/payable GL miliknya (per company) — satu-satunya master data
  khusus-payroll yang dimiliki Accounting.
- `ACCOUNTING.payroll_posting_log` — satu baris per event `payroll.run_paid` yang dikonsumsi,
  `gl_journals.id` yang dihasilkan, dan `subject_type`/`subject_id` asal (baris
  `PAYROLL.payroll_runs`) — log idempotensi/traceability, mencerminkan peran
  `inv_posting_log` secara persis. Tidak ada angka gaji, pajak, atau kontribusi yang berada
  di sini; itu tetap eksklusif di `PAYROLL.payroll_run_lines` / `payroll_run_line_components`.

**Cost Accounting / Budgeting**
- `ACCOUNTING.cost_allocation_rules`, `ACCOUNTING.cost_allocation_runs`.
- `ACCOUNTING.budgets`, `ACCOUNTING.budget_lines`.

**Pajak Indonesia**
- `ACCOUNTING.tax_faktur_pajak` (output + input), `ACCOUNTING.tax_bukti_potong`,
  `ACCOUNTING.tax_periods` (per company, per tipe kewajiban, status filing),
  `ACCOUNTING.tax_coretax_export_batches` (log batch XML yang dihasilkan, untuk
  traceability).

**Recurring**
- `ACCOUNTING.recurring_journal_templates`, `ACCOUNTING.recurring_ar_templates`,
  `ACCOUNTING.recurring_ap_templates`, `ACCOUNTING.recurring_generation_log`.

**Audit**
- `ACCOUNTING.audit_logs` — append-only, tanpa update/delete di lapisan aplikasi.

**File objek** (sesuai `CLAUDE.md` §7B):
```text
tenant_001/ACCOUNTING/
├── {company_id}/bank_statements/{yyyy}/{mm}/
├── {company_id}/tax_documents/{yyyy}/{mm}/       # PDF Faktur Pajak / Bukti Potong yang dihasilkan, batch XML Coretax
└── {company_id}/reports/{yyyy}/{mm}/             # laporan statutori PDF/XLSX yang diekspor
```
- Bucket Cloudflare R2 bersama yang sama, key berprefiks-tenant, seperti setiap modul lain.
  Dokumen yang dilampirkan pada record AR/AP/jurnal tertentu dapat sebaliknya melalui **DMS**
  (gunakan ulang, jangan bangun ulang) via `subject_type = 'accounting.ar_invoices'` —
  Accounting hanya butuh object storage miliknya sendiri untuk artefak yang dihasilkan-sistem
  (rekening koran, ekspor pajak, laporan), bukan untuk lampiran dokumen umum.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu AI Coding.

**Pola arsitektur:** Modul Core, postur monolitik-modular yang sama seperti WNE/DMS/CRM/
Schedule. Mengekspos:
- **Facade/service internal** (disukai) — `AccountingService`, `JournalService`, `ARService`,
  `APService`, `TaxService`, `AssetService` — titik integrasi untuk modul Core/Vertical lain.
- **Event bus internal** — menerbitkan `JournalPosted`, `InvoicePosted`, `BillPosted`,
  `PaymentRecorded`, `PeriodClosed`, `TaxDocumentIssued`; mengonsumsi
  `JournalPostingRequested`, `InvoiceRequested`, `BillRequested`, `inventory.goods_received`,
  `inventory.goods_issued`, `inventory.stock_adjusted`, `payroll.run_paid`, `PartnerCreated`
  (dari CRM). Aturan satu-arah Vertical/Core → Accounting yang sama seperti di tempat lain —
  Accounting tidak pernah menjangkau ke dalam skema modul pemanggil.
- **FK lintas-schema, Core-ke-Core**: `ar_invoices`/`ap_bills` milik Accounting FK langsung ke
  `CRM.partners.id`, arah yang diizinkan sama yang sudah didokumentasikan CRM untuk modul Core
  lain (Core bergantung pada Core tidak masalah; arah yang terlarang adalah Core bergantung
  pada Vertical).
- **Gunakan ulang, jangan bangun ulang**: approval menggunakan ulang WNE (tidak pernah engine
  approval paralel), recurrence menggunakan ulang pendekatan `simshaun/recurr` milik Schedule,
  lampiran dokumen menggunakan ulang DMS, notifikasi (tanggal jatuh tempo, item tidak cocok,
  tenggat pajak) menggunakan ulang routing WNE — Accounting adalah modul Core kelima dan harus
  terasa struktural identik dengan empat yang pertama, bukan kasus khusus. Ini berlaku dalam
  arah *sebaliknya* juga: **Sales tidak boleh membangun-ulang ledger AR** — Billing Engine-nya
  adalah pemohon, Accounting adalah satu-satunya sistem pencatatan AR/AP platform-wide (lihat
  §3D/§3R di atas dan `SALES_SPECS.md` §3I/§5).

**Tentang `company_id` vs. aturan "tanpa kolom `tenant_id`" milik `CLAUDE.md` §4/§7:** ini
adalah sumbu yang berbeda dan keduanya benar secara simultan. Isolasi tenant adalah batas
*database* (satu DB per tenant, sesuai §4) — aturan itu tentang isolasi **lintas-tenant** dan
tetap utuh; tidak ada tabel Accounting yang butuh kolom `tenant_id` untuk alasan itu.
**Multi-company** adalah konsep *intra-tenant* — satu DB tenant firma hukum secara sah berisi
dua entitas legal (misalnya perusahaan operasional dan entitas trust-klien) yang tidak boleh
pernah tercampur ledgernya. Itu membutuhkan kolom `company_id` pada setiap tabel transaksi
Accounting, sama seperti yang dibutuhkan di sistem accounting single-tenant mana pun dengan
dukungan multi-entitas.

**Batas cakupan MVP (bersikap eksplisit tentang apa yang ditunda):**
- Tabel tarif depresiasi fiskal di-seed dengan default terkini tapi merupakan data yang dapat
  diedit tenant, bukan logika hardcoded — perubahan regulasi adalah edit data.
- Integrasi Coretax menargetkan ekspor XML untuk diimpor ke Coretax (jalur fallback yang
  didukung sesuai panduan DJP sendiri untuk wajib pajak/software pihak ketiga), bukan API
  real-time live — cocok dengan bias "implementasi cepat" dan menghindari pembangunan
  terhadap permukaan API eksternal yang (sesuai pelaporan saat ini) masih menstabilkan diri;
  driver API live bersifat aditif belakangan di balik interface `CoretaxExportDriver` yang
  sama.
- Tanpa konsolidasi/eliminasi, tanpa persediaan fisik, tanpa engine PPh 21 payroll penuh —
  semuanya secara eksplisit Future Version (§2) — membangun versi yang lebih penuh sekarang
  akan menghabiskan kompleksitas skema nyata (entri eliminasi intercompany, pemodelan
  gudang/lokasi, tabel payroll PTKP/TER) yang tidak dibutuhkan untuk meluncurkan v1 yang dapat
  dijual.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B (setup COA/GL) → 3C (engine
jurnal — jalur posting tunggal yang menjadi tempat bergantung segala sesuatu yang lain) →
**3M (engine pajak Indonesia — kode pajak, tipe withholding, penomoran Faktur Pajak/Bukti
Potong, driver ekspor Coretax) dibangun berikutnya, sebelum layar AR/AP mana pun dianggap
feature-complete** → 3D/3E (AR/AP, ditulis terhadap engine pajak sejak awal — layar
invoice/tagihan yang belum memanggil logika pajak diperlakukan sebagai belum selesai, bukan
sebagai state interim yang dapat diluncurkan) → 3K/3L (multi-company/multi-currency,
divalidasi lebih awal karena meretrofit `company_id` setelah data ada itu menyakitkan, dan
kedua aturan PPN/PPh sudah terlingkup-company) → 3F (Kas & Bank) → 3Q (rekonsiliasi bank) →
3G (Fixed Assets, termasuk depresiasi fiskal) → 3N (Financial Analysis/Reporting, karena
bergantung pada semua yang di atas sudah benar) → 3O (pengerasan Audit & Compliance) → 3P
(Recurring) → 3I/3J (Cost Accounting/Budgeting) → 3H (Posting GL Persediaan — konsumen tipis
event yang sudah diterbitkan dan cost yang sudah dihitung milik Inventory, bukan engine
costing miliknya sendiri) → 3R (facade/event Otomasi dari ERP, disambungkan secara bertahap
seiring setiap modul konsumen — Legal, lalu **Sales**, yang Billing Engine-nya adalah caller
penghasil-AR utama platform — siap memanggilnya) → 3S (Posting GL Payroll, begitu Payroll
live — bentuk konsumen-tipis yang sama seperti 3H, disambungkan terakhir karena bergantung
pada pembangunan Payroll sendiri, bukan pada apa pun yang lain di modul ini).

**Catatan kelayakan jual (marketability)**
- Penanganan pajak yang patuh-Indonesia (ekspor siap-Coretax, perlakuan PPN/PPh yang benar)
  adalah diferensiator genuine dibanding SaaS accounting internasional generik untuk pasar
  peluncuran vertikal Legal — layak ditonjolkan secara eksplisit dalam percakapan penjualan,
  bukan sekadar checklist internal.
- Dukungan multi-company di MVP (alih-alih ditunda) adalah permintaan nyata untuk klien
  professional-services (firma hukum umumnya menjalankan entitas trust/dana-klien secara
  terpisah dari perusahaan operasional) — cocok dengan struktur nyata vertikal Legal, bukan
  generalisasi spekulatif.
- Sellability mandiri (sesuai Latar Belakang) berarti Accounting bisa menjadi lini pendapatan
  sendiri, positioning yang sama yang sudah ditetapkan untuk DMS dan Schedule.
- Pencatatan dan pembayaran AP yang sekarang selalu melalui Accounting berarti setiap tagihan
  vendor yang ditangkap via three-way match Purchase mendapat withholding PPh yang benar dan
  Bukti Potong secara konstruksi — cerita "kepatuhan by construction, bukan by remembering to
  configure it" yang sama yang sudah diceritakan untuk sisi AR/Sales.
- Menjadi satu-satunya ledger AR/AP untuk setiap modul penghasil-pendapatan lain (termasuk
  Sales) itu sendiri adalah selling point begitu sebuah tenant memiliki lebih dari satu yang
  aktif — "satu angka benar untuk apa yang terhutang pada kami," bukan laporan Sales dan
  laporan Accounting yang secara diam-diam tidak sepakat.
