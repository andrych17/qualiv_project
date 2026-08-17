# Modul Sales
## Manajemen Sales — Modul Inti Bersama (Core Shared Module) (dapat berdiri sendiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal (Legal hari ini; Property, dan lainnya nanti) pada akhirnya menjual sesuatu —
sebuah engagement layanan, retainer, unit, langganan — dan begitu sebuah deal bergerak melewati
"siapa orang ini" (pekerjaan CRM) menuju "apa yang kita jual ke mereka, seberapa banyak, dan
apakah mereka sudah bayar," ia membutuhkan engine order-to-cash khusus. Jika dibiarkan tidak
terselesaikan secara sentral, ini mengulangi anti-pola yang sama yang dibangun WNE, DMS, dan
CRM masing-masing untuk dihindari:

- Setiap vertikal menciptakan sendiri penomoran quote/invoice, logika harga, dan pelacakan
  pembayaran — tidak ada pelaporan bersama, tidak ada pengakuan pendapatan yang konsisten,
  tidak ada template PDF yang dapat digunakan ulang.
- Data customer akan terduplikasi lagi (tabel "siapa ini" kedua) kecuali Sales secara ketat
  menggunakan ulang registry `partners` milik CRM — CRM ada khusus agar ini tidak pernah perlu
  terjadi dua kali.
- Tidak ada tampilan terpadu "apa yang dihutang kepada kita" (aging AR) atau "apa yang kita jual
  bulan ini" lintas vertikal — kritis bagi solo dev yang juga pemilik bisnis.
- Pendapatan berulang (retainer, langganan, kontrak maintenance) tidak punya tempat — setiap
  vertikal akan membangun billing berulang setengah-jadi sendiri.
- Pelacakan insentif sales (komisi) dilakukan di spreadsheet atau tidak sama sekali — keduanya
  tidak layak dijual sebagai bagian dari "ERP profesional".

**Kebutuhan klien:**
- Sadar multi-tenant, sama seperti setiap modul Core lain — terlingkup-tenant via isolasi
  DB-per-tenant (tanpa kolom `tenant_id`, sesuai `CLAUDE.md` §4/§7).
- Harus bekerja **standalone** — sebuah tenant bisa menjalankan Sales hanya dengan CRM terpasang
  (quote → order → invoice → pembayaran), tanpa modul vertikal apa pun hadir, karena ini dapat
  dijual sebagai item lini sendiri persis seperti DMS dan Schedule.
- Harus juga terintegrasi dengan bersih terhadap vertikal mana pun: sebuah case Legal bisa
  memunculkan Sales Order (mis. menagih retainer), sebuah penjualan/sewa unit Property bisa
  mengalir lewat pipeline quote-to-cash yang sama — tanpa Sales mengetahui apa pun tentang
  internal Legal atau Property.
- Customer tidak pernah dimodelkan ulang di sini — **Sales mengonsumsi `CRM.partners`** (role =
  Customer), titik. Tidak ada tabel customer paralel.
- Lifecycle quote-to-cash penuh: Opportunity → Quotation → Sales Order → Delivery → Invoice →
  Payment, dengan Returns dan Credit control dilapiskan di atasnya. **Invoice, Payment, dan
  ledger AR dimiliki oleh modul Accounting, bukan Sales** — Sales mengorkestrasi *kapan* dan
  *apa* yang ditagih (dari sebuah order, sebuah delivery, atau jadwal berulang) dan
  menyerahkan ke Accounting via `InvoiceRequested`/`PaymentRequested`, seam yang sama yang sudah
  dicadangkan `ACCOUNTING_SPECS.md` §3R khusus untuk ini. Ini mencerminkan aturan "satu ledger,
  banyak requester" yang sudah diterapkan Accounting pada billing case Legal — Sales adalah
  konsumen kedua yang konkret dari seam itu, bukan implementasi paralelnya.
- **Delivery/fulfillment dan ledger stok fisik dimiliki oleh modul Inventory, bukan Sales**,
  ketika Inventory terpasang — seam "satu ledger, banyak requester" yang sama diterapkan satu
  lapisan lebih awal. Delivery Engine milik Sales mengorkestrasi *apa* yang sedang dikirim dan
  menggerakkan status/pelacakan yang menghadap customer, dan menyerahkan pengurangan stok
  aktual ke Inventory via `InventoryService::issue()` saat konfirmasi pengiriman. Tenant yang
  menjalankan Sales tanpa Inventory tetap mendapat pelacakan delivery penuh — lihat §3H/§5.
- Pendapatan berulang (Contracts & Subscriptions) harus menggerakkan billing berulang secara
  otomatis, tidak membutuhkan manusia untuk mengingat menagih setiap bulan.
- Perhitungan komisi harus dapat diaudit dan terikat pada pendapatan yang benar-benar
  ditagih/dibayar, bukan sekadar nilai order (order yang dibatalkan/diretur seharusnya tidak
  membayarkan komisi).
- Credit control harus mampu **memblokir** order (dengan jalur override approval), bukan
  sekadar melaporkan eksposur secara pasif.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — luncurkan loop order-to-cash yang bisa dipakai dengan
> cepat, tunda tooling revenue-ops yang dalam (harga dinamis, integrasi gateway, model
> forecasting) ke Future Version.

**MVP (siap-luncur, implementasi cepat)**

- **Sales Master** — Price List (per customer/territory), Sales Team, Territory. Tidak ada
  tabel Customer baru — Customer adalah `CRM.partners` yang difilter ke role = `Customer`.
- **Integrasi CRM** — pipeline Opportunity yang duduk di atas alur Lead → Convert milik CRM yang
  sudah ada; Quotation tertaut ke Opportunity atau langsung ke Customer; Customer Portal
  read-only minimal (melihat quote/order/invoice/pelacakan via tautan bertanda tangan).
- **Quotation Engine** — estimasi terversi, riwayat revisi (tidak pernah ditimpa, pola versi
  immutable yang sama seperti DMS), langkah approval WNE opsional, konversi satu-klik ke Sales
  Order.
- **Sales Order Engine** — header/baris order, lifecycle status, tertaut keluar ke Delivery dan
  Billing.
- **Pricing & Promotions** — resolusi price-list per customer/territory, diskon
  persentase/tetap sederhana, kode promo terikat-tanggal.
- **Delivery Engine** — lifecycle pick → pack → ship → delivered, entri carrier/tracking manual,
  delivery parsial terhadap sebuah order. Ketika **Inventory** terpasang, menandai delivery
  `shipped` memposting pengurangan stok aktual via `InventoryService::issue()` (lihat §3H) —
  Sales tidak memelihara definisi kedua "apa yang ada di stok".
- **Billing Engine (orkestrator permintaan, bukan ledger)** — memutuskan *kapan* sebuah
  order/delivery/jadwal berulang siap ditagih dan memicu `InvoiceRequested` ke **Accounting**,
  yang menjalankan pembuatan invoice aktual, treatment PPN/pajak, pembuatan Faktur Pajak, dan
  posting GL. Sales tidak menyimpan baris header/baris/pembayaran invoice miliknya sendiri —
  lihat §3I dan §5.
- **Returns Engine** — header/baris gaya RMA terhadap order/invoice asli, jalur refund atau
  penggantian.
- **Customer Credit Engine** — limit kredit + bucket aging, hard block pada order baru di atas
  limit dengan approval override yang dirutekan WNE.
- **Commission Engine** — plan flat/tiered per tim atau rep, dihitung dari pendapatan yang
  ditagih (bukan sekadar dipesan), settlement batch dengan approval.
- **Contracts & Subscriptions Engine** — header kontrak + baris berulang, menggerakkan generator
  billing berulang, lifecycle sederhana (draft → active → renewed → cancelled → expired).
- **Analytics** — kartu dashboard + funnel pipeline sederhana; BI mendalam ditunda ke modul
  Performance/BI khusus di masa depan alih-alih dibangun dua kali.

**Future Version (pasca-peluncuran, begitu ada volume penggunaan/revenue nyata yang
menjustifikasi pembangunannya)**

- **Integrasi payment gateway** (Stripe/Xendit/rel lokal) — MVP adalah pencatatan pembayaran
  manual; kandidat ekstraksi alami nantinya (dependensi API eksternal, manfaat isolasi
  adjacent-PCI, sesuai `CLAUDE.md` §2).
- **Tax engine** (aturan VAT/GST multi-yurisdiksi) — MVP menggunakan field tarif pajak flat per
  baris; tax engine sungguhan adalah microservice terjustifikasi sendiri jika platform meluas
  melewati aturan satu yurisdiksi.
- **Integrasi API carrier** (webhook pelacakan live, pencetakan label, optimasi rute) — MVP
  adalah entri nomor tracking manual.
- **Engine aturan harga dinamis/tiered/bundle** — MVP adalah price-list flat + diskon
  sederhana.
- **Billing berbasis penggunaan & proration mid-contract** — langganan MVP hanya jumlah
  berulang flat.
- **Customer Portal self-service penuh** (approval quote online, pembayaran online, retur
  self-serve) — portal MVP read-only.
- **Forecasting / nilai pipeline berbobot / model prediksi pendapatan** — dashboard MVP bersifat
  deskriptif (apa yang terjadi), bukan prediktif.
- **Engine konversi multi-currency** — MVP mengasumsikan currency tenant tunggal (price list
  bisa membawa tag currency untuk future-proofing, tapi tanpa konversi FX live).
- **Automated credit scoring** — limit kredit MVP adalah field yang diatur manual.
- **Komisi multi-level/split, SPIFF** — MVP hanya komisi single-rep, single-plan.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Kesehatan pendapatan sekilas: nilai quote terbuka, nilai order terbuka, revenue MTD, total
  invoice menunggak, customer di atas limit kredit, retur terbuka.
- Permukaan "pekerjaan saya": quote menunggu approval saya, order yang saya miliki, settlement
  komisi menunggu sign-off saya — antrean terpadu, pola yang sama seperti dashboard CRM.
- Funnel pipeline sederhana: tahap Opportunity → Quoted → Ordered → Invoiced → Paid, sebagai
  jumlah dan nilai.

**Layout**
- Atas: kartu ringkasan (sesuai di atas).
- Utama: tabel bertab — "Opportunity Saya" | "Quote Saya" | "Order Saya" | "Invoice Menunggak".
- Setiap baris menggunakan **Status Rail** bersama (sesuai `DESIGN.md`), diwarnai berdasarkan
  state — konsisten dengan tampilan list setiap modul lain.

**Aturan / logika**
- Terlingkup-tenant secara otomatis (DB-per-tenant — tidak perlu filter tingkat-aplikasi, sama
  seperti CRM/Schedule/DMS).
- Customer yang diblokir kredit dan invoice menunggak muncul lebih dulu terlepas dari urutan,
  mencerminkan aturan SLA-breach-first milik CRM.

## 3B. Sales Master

**Tujuan:** lapisan konfigurasi yang dibaca setiap engine lain.

- **Price List** — header (nama, currency, tanggal berlaku, cakupan territory/segmen-customer)
  + baris (referensi item/layanan, harga). Sebuah customer/order diresolusi ke tepat satu price
  list aktif dalam satu waktu (penugasan eksplisit, jatuh kembali ke default tenant).
- **Sales Team** — header tim + daftar anggota (user), setiap tim opsional terikat ke sebuah
  Territory untuk routing/pelaporan.
- **Territory** — lookup sederhana yang dapat diedit tenant (region/segmen), dapat ditugaskan ke
  Customer (via custom field `CRM.partners`, menggunakan ulang `CUSTOMFIELDS` alih-alih kolom
  baru pada tabel milik-Core) dan ke Sales Team.
- **Customer** — bukan tabel. Sebuah view/query atas `CRM.partners` yang difilter ke
  `role = Customer`, di-join ke data milik-Sales (profil kredit, price list yang ditugaskan,
  territory, rep/tim yang ditugaskan) via `SALES.customer_sales_profiles` (lihat §4) — pola
  "satu store, banyak view" yang sama yang dipakai DMS untuk dokumen embedded-modul.

**Aturan / logika**
- Menugaskan Territory/Sales Team/Price List ke customer menulis ke
  `customer_sales_profiles`, tidak pernah ke `CRM.partners` — Sales tidak pernah memigrasikan
  skema CRM.

## 3C. Manajemen Opportunity (Integrasi CRM)

**Tujuan:** pipeline khusus-sales yang dimulai di tempat pipeline Lead milik CRM berakhir —
peluang nyata dan berkualifikasi untuk menjual sesuatu, baik Lead terlibat atau tidak.

- Field: nama, `customer_id` (nullable — bisa dimulai terhadap prospek yang belum menjadi
  Customer), `lead_id` (nullable, diatur jika berasal dari Lead CRM yang dikonversi), tahap
  (New → Qualifying → Quoted → Won → Lost), pemilik (rep/tim), estimasi nilai, tanggal closing
  yang diharapkan, alasan loss (saat Lost, pola kode-alasan yang sama seperti Lead CRM).
- Tampilan Board (Kanban) dan list, komponen bersama yang sama seperti board Lead CRM.
- Quotation tertaut ke sebuah Opportunity (atau bisa dibuat langsung terhadap Customer tanpa
  Opportunity, untuk penjualan berulang/sederhana — Opportunity adalah lapisan kenyamanan,
  bukan persyaratan keras).

**Aturan / logika**
- Memenangkan sebuah Opportunity itu sendiri tidak menciptakan apa pun — konversi Quotation →
  Sales Order-lah yang menghasilkan transaksi nyata; "Won" adalah state
  pipeline/pelaporan.
- Nilai Opportunity bersifat informasional saja di MVP (tanpa forecasting berbobot — lihat
  Future Version).

## 3D. Customer Portal (Integrasi CRM, MVP read-only)

- Akses tautan bertanda tangan atau login ringan bagi Customer untuk melihat Quote, Order,
  Invoice (dengan status pembayaran), dan pelacakan Delivery miliknya sendiri — tanpa
  kemampuan bertindak (approve/bayar) di MVP.
- Menggunakan ulang pola token-UUID-bertanda-tangan yang sama yang dipakai Schedule untuk feed
  ICS (`sales_portal_tokens`), sehingga akses bisa dicabut tanpa menyentuh auth platform.

## 3E. Quotation Engine (Estimasi, Revisi, Approval)

- Header: `customer_id`, `opportunity_id` (nullable), price list, tanggal validitas, status
  (draft → sent → approved → accepted/declined → expired → converted), nomor revisi.
- Baris: item/layanan, kuantitas, harga satuan (pre-filled dari price list, dapat di-override),
  diskon, pajak, total baris.
- **Revisi**: mengedit quote `sent` membuat revisi immutable baru (menambah `revision_no`),
  tidak pernah menimpa — filosofi identik dengan versioning dokumen DMS. PDF yang menghadap
  customer selalu mencerminkan revisi saat ini; revisi sebelumnya tetap terlihat di riwayat.
- **Approval**: opsional — tenant dapat mengonfigurasi approval terpicu-ambang-diskon via WNE
  (`WorkflowRequested`, `workflow_code = sales.quote_approval`); quote di bawah ambang langsung
  ke `sent`.
- **PDF & lampiran**: PDF quote dihasilkan dan disimpan via `DocumentService::upload()` (DMS),
  `subject_type = 'sales.quot_hdrs'` — Sales tidak membangun penyimpanan file sendiri.
- **Convert to Order**: satu aksi, menyalin baris apa adanya ke Sales Order baru, menandai quote
  `converted`, tertaut kembali (`quot_hdrs.converted_so_id`).

**Aturan / logika**
- Quote yang kedaluwarsa dapat di-clone menjadi quote draft baru dalam satu aksi alih-alih
  membutuhkan entri ulang.

## 3F. Sales Order Engine (Pemrosesan & Fulfillment)

- Header: `customer_id`, sumber (`quote_id` nullable — order langsung diperbolehkan), price
  list (dikunci saat waktu order), status (draft → confirmed → partially fulfilled → fulfilled
  → cancelled), `subject_type`/`subject_id` (tautan polimorfik opsional kembali ke record
  vertikal, mis. `legal.case_hdrs` — seam yang sama seperti setiap modul lain).
- Baris: item/layanan, kuantitas dipesan, kuantitas dikirim (rollup dari Delivery), kuantitas
  ditagih (rollup dari Billing), harga satuan, diskon, pajak.
- Mengonfirmasi order menjalankan **Credit Check** (3J) secara sinkron — customer di atas limit
  kredit mereka tidak bisa mengonfirmasi tanpa approval override.
- Tampilan detail order: header + baris + tab Delivery tertaut + tab Invoice tertaut +
  Activity Timeline (komponen bersama, sama seperti case CRM).

**Aturan / logika**
- Membatalkan order setelah fulfillment/penagihan parsial diblokir — harus melalui Returns
  sebagai gantinya, untuk menjaga jejak audit yang bersih (tidak pernah diam-diam menghapus
  apa yang sudah dikirim/ditagih).

## 3G. Engine Pricing & Promotions

- Urutan resolusi harga: override baris eksplisit → Price List yang ditugaskan customer →
  Price List default tenant.
- **Diskon**: persentase atau tetap, pada level baris atau order, opsional terikat-tanggal.
- **Kode promo**: tabel `promo_codes` (kode, tipe/nilai diskon, berlaku dari/sampai, limit
  penggunaan, jumlah penggunaan), diterapkan saat entri Quotation atau Order, divalidasi saat
  penerapan (kode kedaluwarsa/habis ditolak dengan error yang jelas sesuai panduan suara
  `DESIGN.md`).

**Aturan / logika**
- Promosi bersifat aditif terhadap harga price-list, tidak pernah mengedit price list itu
  sendiri — menjaga price list sebagai sumber kebenaran yang stabil.

## 3H. Delivery Engine (Pick / Pack / Ship / Tracking)

- Header: `so_id`, status (pending → picked → packed → shipped → delivered → cancelled),
  carrier (free text/lookup), nomor tracking, tanggal dikirim, tanggal diterima, gudang/lokasi
  sumber (hanya ditampilkan ketika **Inventory** terpasang — default ke gudang default yang
  dikonfigurasi tenant, dapat di-override manual; disembunyikan sepenuhnya jika Inventory tidak
  terpasang).
- Baris: referensi baris order, kuantitas dikirim pada delivery ini (mendukung pengiriman
  parsial/split — satu order bisa punya N delivery).
- **Posting ke Inventory (ketika terpasang):** menandai delivery `shipped` memanggil
  `InventoryService::issue(...)` (`INVENTORY_SPECS.md` §3E/§5) dengan baris yang dikirim dan
  lokasi sumber — Inventory membuat entry `goods_issues`/`stock_ledger` miliknya sendiri (tipe
  pergerakan `issue`), mengonsumsi lapisan valuasi sesuai metode costing-nya, dan mengembalikan
  id-nya, disimpan pada `dlv_hdrs.inventory_goods_issue_id` (referensi informasional, bukan FK
  yang ditegakkan, karena Inventory adalah instalasi opsional) untuk traceability. Jika
  Inventory memblokir issue tersebut (kuantitas diminta melebihi yang tersedia di lokasi itu),
  transisi `shipped` ditolak dengan error jelas yang sama yang sudah didefinisikan
  `INVENTORY_SPECS.md` §3E, alih-alih Sales diam-diam mencatat pengiriman yang secara fisik
  tidak mungkin. Jika Inventory tidak terpasang, menandai `shipped` berlangsung persis seperti
  sebelumnya, tanpa efek stok fisik.
- Menandai `shipped` memicu event internal gaya `schedule.item_created`
  (`sales.delivery_shipped`) → WNE (jika aktif) menotifikasi customer/portal dengan info
  tracking — tidak berubah, terpicu terlepas dari apakah panggilan Inventory di atas juga
  berjalan.

**Aturan / logika**
- `so_lines.qty_delivered` adalah rollup turunan dari `dlv_lines`, dihitung ulang pada setiap
  perubahan status delivery — tidak pernah diedit manual pada order. Ini tetap menjadi angka
  milik Sales sendiri terlepas dari status instalasi Inventory — "berapa banyak yang sudah
  dikirim ke customer ini" adalah pertanyaan fulfillment-order yang dijawab Sales sendiri.
- Begitu Inventory memposting entry ledger-nya sendiri dari sebuah pengiriman, `stock_ledger`
  milik Inventory menjadi angka otoritatif untuk kuantitas on-hand dan valuasi sejak titik itu
  dan seterusnya — pembagian yang sama yang sudah ditetapkan untuk Goods Receipt milik Purchase
  (`PURCHASE_SPECS.md` §3E): dua pertanyaan berbeda, dijawab oleh modul yang benar-benar
  memiliki masing-masing.
- Mengirim penuh semua baris otomatis memperbarui status order induk menjadi `fulfilled`.

## 3I. Billing Engine (Orkestrasi Permintaan Invoice)

**Tujuan:** memutuskan *kapan* sesuatu siap ditagih dan menyerahkan ke **Accounting**, ledger AR
tunggal yang menjadi rujukan untuk platform (`ACCOUNTING_SPECS.md` §3D). Sales tidak memelihara
tabel invoice atau pembayaran miliknya sendiri — sebuah koreksi yang disengaja: ledger milik-Sales
yang independen akan merusak jaminan akun-kontrol milik Accounting ("saldo AR selalu tepat sama
dengan jumlah saldo invoice terbuka") dan berisiko menerbitkan invoice customer tanpa treatment
PPN/Faktur Pajak yang benar, yang menurut spesifikasi Accounting sendiri disebut liabilitas
kepatuhan sejak transaksi pertama, bukan celah yang aman ditambal belakangan.

- **Sumber pemicu**: sebuah Sales Order terkonfirmasi yang siap ditagih (penuh atau per baris
  yang dikirim), sebuah Delivery yang ditandai `shipped`/`delivered`, jadwal berulang milik
  Contract & Subscription (§3L) yang mencapai `next_bill_date`-nya, atau permintaan billable
  eksternal dari modul Core atau Vertical mana pun via
  `SalesOrderService::createFromExternalRequest(...)` (disukai, same-process) atau event
  `SalesOrderRequested` (decoupled). Sales mendefinisikan ini sebagai titik masuk generik
  (payload: `subject_type`/`subject_id`, referensi partner customer, item baris, deskripsi) yang
  bisa diisi caller mana pun — pola "Core mendefinisikan kontrak, caller mana pun mengisinya"
  yang sama yang sudah dipakai `InvoiceRequested`/`BillRequested` milik Accounting. Legal adalah
  pengguna konkret pertama jalur ini (sebuah matter yang siap ditagih memanggilnya langsung,
  sesuai `LEGAL_SPECS.md` §2), tetapi Sales tidak membutuhkan kode spesifik-Legal untuk
  mendukungnya. Sales Order yang dihasilkan (`subject_type`/`subject_id` menunjuk kembali ke
  record asal, sesuai §3F) kemudian mengalir lewat jalur siap-ditagih-dan-terkonfirmasi yang
  sama seperti order mana pun, sehingga Billing Engine punya persis satu bentuk input terlepas
  dari mana permintaan berasal.
- **Saat terpicu**: Sales memanggil `AccountingService::createInvoice(...)` / memicu
  `InvoiceRequested` (`subject_type = 'sales.so_lines'`, `subject_id` = baris order yang
  ditagih) dengan customer (referensi `CRM.partners`), item baris, jumlah, dan kode pajak.
  Accounting membuat baris `ar_invoices`, menghitung pajak keluaran PPN, membuat Faktur Pajak
  jika berlaku, dan memposting jurnal akun kontrol AR — semua sesuai `ACCOUNTING_SPECS.md`
  §3D/§3M, tidak diimplementasikan ulang di sini.
- **Deposit**: diminta dengan cara yang sama, ditandai `invoice_type = deposit` pada permintaan
  (field aditif pada `ACCOUNTING.ar_invoices`). Deposit diterapkan terhadap invoice final
  belakangan menggunakan mekanisme penerapan-pembayaran milik Accounting yang sudah ada (§3D) —
  Sales tidak mengimplementasikan logika saldo-kredit sendiri.
- **Pencatatan pembayaran**: ditangkap di mana pun tenant benar-benar menerima pembayaran (UI
  Sales, Customer Portal, atau layar Accounting sendiri) tetapi selalu ditulis ke
  `ACCOUNTING.ar_payments`/`ar_payment_applications` via `PaymentRequested` — tidak pernah ke
  tabel milik-Sales.
- **Rollup status order/baris**: Accounting memicu event `InvoicePosted` dan `PaymentRecorded`
  yang membawa referensi-balik `subject_type`/`subject_id`; Sales berlangganan untuk
  memperbarui `so_lines.qty_invoiced` dan status billing order — Accounting tidak pernah perlu
  tahu `SALES.so_lines` ada di luar pointer itu.
- **Billing berulang**: `SALES.recurring_billing_schedules` (dari Contracts, §3L) tetap
  menggerakkan job terjadwal harian — satu-satunya aksinya pada setiap tanggal jatuh tempo
  adalah memicu `InvoiceRequested` yang sama seperti konversi order-ke-invoice manual, tidak
  menulis baris secara langsung.
- **Pengingat invoice-menunggak**: Sales, bukan Accounting, memiliki komunikasi dunning yang
  menghadap customer (ia modul hubungan-customer) — sebuah job terjadwal membaca aging AR
  milik Accounting (§3D) untuk customer/order yang menjadi perhatian Sales dan memicu
  `sales.invoice_overdue` → WNE, sama seperti sebelumnya. Laporan aging Accounting tetap menjadi
  sumber kebenaran; Sales hanya membacanya, tidak pernah menghitungnya ulang.

**Aturan / logika**
- Jika Accounting tidak terpasang/aktif untuk tenant, Sales tidak bisa menghasilkan invoice
  nyata — lihat §5 untuk alasan mengapa aksi spesifik ini, bukan seluruh UI Billing Engine,
  menjadi dependensi keras.
- Permintaan credit-note (dari Returns, §3J) melalui facade `AccountingService` yang sama —
  tidak pernah edit langsung ke baris milik-Accounting.

## 3J. Returns Engine (Retur, Refund, Penggantian)

- Header: `so_id`/`invoice_id` asli, `customer_id`, kode alasan, status (requested → approved →
  received → refunded/replaced → closed).
- Baris: referensi baris order asli, kuantitas diretur, catatan kondisi.
- **Jalur refund**: meminta credit note dari **Accounting** (`ACCOUNTING.ar_credit_notes`, §3D)
  terhadap invoice asli, alih-alih Sales menulis penyesuaiannya sendiri — aturan "Accounting
  adalah ledger, Sales adalah requester" yang sama seperti Billing (§3I).
- **Jalur penggantian**: satu aksi menghasilkan Sales Order baru yang sudah pre-filled dengan
  baris yang diretur, `subject_type = 'sales.ret_hdrs'` tertaut kembali untuk traceability.

**Aturan / logika**
- Menyetujui retur di atas ambang nilai yang dapat dikonfigurasi dapat dirutekan lewat WNE
  (`workflow_code = sales.return_approval`), pola approval-opsional yang sama seperti Quotation.

## 3K. Customer Credit Engine (Limit, Aging, Approval)

- `SALES.customer_credit_profiles`: `partner_id` (FK → `CRM.partners`), credit_limit,
  payment_terms_days, flag `on_hold` (override manual, memblokir semua order baru terlepas dari
  limit).
- **Laporan aging**: Sales tidak menghitung ini sendiri — ia memanggil laporan aging AR milik
  Accounting (`ACCOUNTING_SPECS.md` §3D) difilter ke customer, karena `ar_invoices` adalah
  satu-satunya tempat saldo terbuka benar-benar hidup sekarang.
- **Pemeriksaan kredit**: dijalankan secara sinkron saat konfirmasi Order —
  `AccountingService::getOpenARBalance(partnerId) + nilai order ini > credit_limit` memblokir
  konfirmasi dengan error jelas (sesuai `DESIGN.md`: *"Order ini melebihi limit kredit
  [Customer] sebesar $X. Ajukan override atau kurangi order."*) dan menawarkan
  `WorkflowRequested` override (`workflow_code = sales.credit_override`) jika tenant mengaktifkan
  WNE; tanpa WNE, hanya aksi admin eksplisit yang menghapus `on_hold` yang bisa melewatinya.
  *Limit* kredit dan flag *on_hold* tetap konfigurasi milik-Sales
  (`customer_credit_profiles`) — hanya aritmatika saldo-vs-limit yang sekarang membaca dari
  Accounting alih-alih tabel invoice lokal.

**Aturan / logika**
- Profil kredit hidup sepenuhnya di schema `SALES`, tidak pernah di `CRM.partners` — CRM Core
  punya nol pengetahuan tentang data spesifik-Sales, aturan dependensi searah yang sama seperti
  di tempat lain.

## 3L. Contracts & Subscriptions Engine (Lifecycle & Pendapatan Berulang)

- Header: `customer_id`, nama kontrak, mulai/selesai term, flag auto-renew, status (draft →
  active → renewed → cancelled → expired), Price List tertaut.
- Baris (`contr_subscriptions`): item/layanan, jumlah berulang, interval billing (bulanan /
  kuartalan / tahunan — enum sederhana, bukan RRULE), `next_bill_date`.
- Mengaktifkan kontrak men-seed baris `recurring_billing_schedules` (satu per baris subscription)
  yang dikonsumsi job terjadwal engine Billing.
- Perpanjangan: mendekati `term_end` dengan `auto_renew = true`, sebuah job memperpanjang term
  dan memicu notifikasi `sales.contract_renewed` (WNE); tanpa auto-renew, memicu
  `sales.contract_expiring` untuk follow-up manual.

**Aturan / logika**
- Membatalkan kontrak menghentikan pembuatan invoice masa depan segera tetapi tidak pernah
  secara retroaktif membatalkan invoice yang sudah diterbitkan — pembatalan bersifat
  maju-saja (forward-only), postur non-destruktif yang sama seperti di tempat lain di platform
  ini.

## 3M. Commission Engine (Insentif & Settlement Sales)

- `SALES.commission_plans`: nama, basis (% flat atau tiered berdasarkan band revenue), berlaku
  untuk (Sales Team atau rep individu), tanggal berlaku.
- Komisi dihitung dari pendapatan yang **sudah ditagih-dan-dibayar** (bukan nilai order),
  dihitung saat Sales menerima event `PaymentRecorded` milik Accounting untuk sebuah invoice
  yang `subject_type`/`subject_id`-nya menelusuri kembali ke order rep itu — menghindari
  membayarkan komisi pada bisnis yang dibatalkan/belum dibayar/diretur, dan menghindari Sales
  membutuhkan tabel pelacakan pembayarannya sendiri untuk tahu hal itu terjadi.
- **Settlement**: sebuah batch (`comm_settlements`, berbasis periode — mis. bulanan)
  mengagregasi komisi yang sudah didapat-tapi-belum-diselesaikan per rep, status (draft →
  approved → paid), opsional dirutekan lewat WNE untuk approval manajer sebelum pembayaran.

**Aturan / logika**
- Sebuah Return yang me-refund invoice yang sudah dibayar otomatis membalikkan komisi terkait
  pada batch settlement terbuka berikutnya (tidak pernah mengedit settlement yang sudah dibayar —
  sebuah baris pembalikan sebagai gantinya, disiplin ledger-immutable yang sama seperti
  Billing).

## 3N. Analytics (KPI, Dashboard, Forecasting)

- MVP: kartu dashboard di §3A ditambah chart funnel sederhana (Opportunity → Quoted → Ordered →
  Invoiced → Paid, jumlah dan nilai per tahap).
- Secara sengaja **tidak** membangun BI/forecasting mendalam di dalam Sales — ditandai untuk
  tertaut ke modul Performance/BI khusus di masa depan (sesuai catatan "on the horizon" proyek
  ini yang sudah ada) yang bisa mengagregasi lintas Sales, CRM, dan modul vertikal di satu
  tempat, alih-alih setiap modul menumbuhkan lapisan analitik setengah-jadi sendiri.

---

# 4. Penyimpanan

**Database (schema `SALES`, DB tenant — konsisten dengan `CLAUDE.md` §7A):**

**Tabel master / lookup / konfigurasi**
- `SALES.price_lists`, `SALES.price_list_lines`
- `SALES.sales_teams`, `SALES.sales_team_members`
- `SALES.territories`
- `SALES.promo_codes`
- `SALES.commission_plans`
- `SALES.customer_sales_profiles` — `partner_id` (FK → `CRM.partners`), territory, sales team,
  price list yang ditugaskan, rep yang ditugaskan.
- `SALES.customer_credit_profiles` — `partner_id` (FK → `CRM.partners`), credit_limit,
  payment_terms_days, `on_hold`.
- `SALES.sales_portal_tokens` — token UUID bertanda tangan untuk akses Customer Portal.

**Tabel transaksi / log** (dua-bagian dengan prefix domain, sesuai konvensi WNE/CRM)
- `SALES.opp_hdrs` — Opportunity.
- `SALES.quot_hdrs`, `SALES.quot_lines` — Quotation (terversi; `revision_no`,
  `converted_so_id`).
- `SALES.so_hdrs`, `SALES.so_lines` — Sales Order.
- `SALES.dlv_hdrs` (mencakup `inventory_goods_issue_id` nullable — referensi informasional ke
  `INVENTORY.goods_issues.id` ketika Inventory terpasang dan delivery sudah diposting di sana;
  bukan FK yang ditegakkan, karena Inventory adalah instalasi opsional untuk Sales),
  `SALES.dlv_lines` — Delivery.
(dihapus — invoice, baris invoice, dan pembayaran dimiliki oleh `ACCOUNTING.ar_invoices` /
`ACCOUNTING.ar_invoice_lines` / `ACCOUNTING.ar_payments`, direferensikan dari Sales hanya via
`subject_type`/`subject_id`, tidak pernah tabel lokal. Lihat §5.)
- `SALES.recurring_billing_schedules` — menggerakkan generator invoice berulang.
- `SALES.ret_hdrs`, `SALES.ret_lines` — Returns.
- `SALES.comm_settlements`, `SALES.comm_settlement_lines` — Batch settlement Commission.
- `SALES.contr_hdrs`, `SALES.contr_subscriptions` — Contracts & Subscriptions.

**Penyimpanan file objek** (sesuai `CLAUDE.md` §7B) — Sales tidak menyimpan file sendiri; PDF
quote, kontrak yang ditandatangani, dan surat jalan disimpan via **DMS**
(`DocumentService::upload()`), di bawah `tenant_{id}/DMS/Sales/...`, dengan
`subject_type`/`subject_id` menunjuk kembali ke record Sales terkait — tidak ada jalur
penyimpanan paralel.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul modular monolith di `app/Modules/Sales/`, bentuk yang sama seperti
WNE/DMS/CRM/Schedule (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`,
`Routes/`). Tidak ada ekstraksi microservice di MVP — Sales adalah CRUD + service berat-kalkulasi
(pricing, credit check, kalkulasi komisi), tidak satu pun yang butuh runtime berbeda saat ini.
Integrasi payment gateway dan tax-engine adalah dua bagian yang ditandai sebagai **ekstraksi
masa depan yang terjustifikasi** sesuai `CLAUDE.md` §2 (permukaan API eksternal, manfaat isolasi
untuk data adjacent-payment) — belum dibangun sekarang.

**Postur dependensi (penting, berbeda per dependensi):**
- **CRM — dependensi keras.** Sales tidak bisa berfungsi tanpa konsep Customer, dan konsep itu
  adalah `CRM.partners`. Tabel skema Sales FK langsung ke `CRM.partners.id` (FK lintas-schema,
  Core-ke-Core, preseden yang sama seperti CRM/DMS/WNE yang sudah saling mereferensikan dalam
  satu DB tenant) alih-alih menduplikasi data kontak. Sebuah tenant tidak bisa mengaktifkan
  Sales tanpa CRM aktif.
- **Accounting — dependensi keras hanya untuk Billing.** Sales tidak bisa menghasilkan invoice
  nyata, mencatat pembayaran, atau memeriksa aging AR/eksposur kredit tanpa Accounting aktif —
  aksi-aksi itu memanggil langsung ke `AccountingService`/memicu
  `InvoiceRequested`/`PaymentRequested`, tanpa fallback lokal (alasan "tanpa ledger paralel"
  yang sama seperti fix Payroll↔HCM di `PAYROLL_SPECS.md` §5). Opportunity, Quotation, Sales
  Order, Pricing, dan Delivery **tidak** digerbang pada Accounting — sebuah tenant bisa
  menjalankan Sales+CRM sendiri lewat "order confirmed" dan hanya membutuhkan Accounting begitu
  sesuatu harus benar-benar menjadi invoice nyata dan benar-pajak.
- **WNE — dependensi lunak.** Semua langkah approval (approval quote, override kredit, approval
  retur, approval settlement komisi) dan semua notifikasi (order confirmed, delivery shipped,
  invoice overdue, contract expiring) dipublikasikan sebagai event internal (`sales.*`) dan
  dikonsumsi WNE **hanya jika aktif** untuk tenant — Sales punya nol dependensi compile-time
  pada kelas WNE, mencerminkan postur Schedule persis. Tanpa WNE, aksi yang digerbang-approval
  cukup membutuhkan aksi admin eksplisit alih-alih workflow yang dirutekan.
- **DMS — dependensi lunak.** PDF quote, kontrak, dan surat jalan dilampirkan via
  `DocumentService` jika DMS aktif; tanpa DMS, Sales jatuh kembali menghasilkan PDF yang bisa
  diunduh secara on-the-fly tanpa riwayat versi persisten (terdegradasi, tidak rusak).
- **Inventory — dependensi lunak, terbatas hanya pada fulfillment fisik.** Delivery Engine
  (§3H) memanggil `InventoryService::issue()` ketika Inventory aktif, untuk memposting
  pengurangan stok nyata dan valuasi saat konfirmasi pengiriman; setiap bagian lain Sales
  (Opportunity, Quotation, Sales Order, Pricing, Billing, Returns, Commission) tidak terpengaruh
  oleh apakah Inventory terpasang, karena `so_lines.qty_delivered` selalu diturunkan dari
  `dlv_lines` milik Sales sendiri, tidak pernah dari ledger milik Inventory. Tenant Sales yang
  menjual layanan non-fisik (mis. retainer Legal) tidak punya alasan untuk memasang Inventory
  sama sekali — Delivery hanya bermakna dipakai oleh tenant yang menjual barang fisik.
- **Schedule — dependensi lunak.** Tidak diperlukan untuk MVP (billing berulang menggunakan
  field interval sederhananya sendiri, secara sengaja bukan RRULE, untuk menghindari coupling
  keras); jika Schedule diaktifkan nanti, tanggal jatuh tempo delivery / tanggal perpanjangan
  kontrak bisa secara opsional muncul di kalender bersama sebagai peningkatan Future Version.

**Facade/service internal** — `SalesOrderService::confirm(...)`,
`SalesOrderService::createFromExternalRequest(...)` (titik masuk generik untuk permintaan
billable modul Core atau Vertical — lihat §3I), `QuotationService::convertToOrder(...)`,
`BillingService::generateInvoice(...)`, `CreditService::check(...)`,
`CommissionService::calculate(...)` — titik integrasi yang disukai untuk modul Core/vertikal
lain (mis. sebuah case Legal yang memicu Sales Order retainer via
`createFromExternalRequest(...)`).

**Bus event internal** — mempublikasikan `OpportunityWon`, `QuotationSent`,
`QuotationConverted`, `SalesOrderConfirmed`, `sales.delivery_shipped`, `sales.invoice_overdue`
(diturunkan dari aging AR milik Accounting, bukan tabel invoice lokal), `sales.contract_renewed`,
`sales.contract_expiring`, `sales.credit_blocked`; **mengonsumsi** `SalesOrderRequested`
miliknya sendiri (§3I — titik masuk permintaan-billable vertikal/Core), jenis kontrak
milik-sendiri yang sama seperti `InvoiceRequested`/`BillRequested` milik Accounting. Sales juga
**berlangganan** ke `InvoicePosted`/`PaymentRecorded` milik Accounting (untuk memperbarui
`so_lines.qty_invoiced` dan memicu Commission, §3M) — terjustifikasi karena Accounting adalah
Core peer, bukan vertikal, dan ini adalah gema status read-only, bukan Accounting menjangkau ke
dalam Sales. Modul vertikal tidak pernah memicu event *bernama untuk internal Sales*, dan Sales
tidak pernah mendengarkan event *bernama untuk internal modul Vertical* — aturan searah "Core
punya nol pengetahuan tentang modul Vertical" yang sama seperti CRM/DMS/WNE (`CLAUDE.md` §2).
`SalesOrderRequested` tidak melanggar ini: ia adalah **kontrak milik-Sales, berbentuk-generik**
(`subject_type`/`subject_id` + item baris + deskripsi) yang bisa diisi dan dipicu caller mana
pun — Core atau Vertical — persis seperti `InvoiceRequested`/`BillRequested` milik Accounting,
`WorkflowRequested`/`NotificationRequested` milik WNE, dan `DocumentAttachRequested` milik DMS.
Sales tetap tidak pernah menjangkau mundur ke dalam skema modul Vertical atau berlangganan
event bernamaspace-Vertical.

**Keterkaitan vertikal tanpa coupling:** `so_hdrs`, `quot_hdrs`, dan `ret_hdrs` semuanya membawa
`subject_type`/`subject_id` sebagai kolom informasional biasa, bukan foreign key — seam yang
identik dengan `svc_cases` milik CRM dan workflow instance milik WNE. Sebuah matter Legal yang
menagih retainer memanggil `SalesOrderService::createFromExternalRequest(...)` (§3I / daftar
facade di atas), yang mengatur `subject_type = 'legal.matters'` (atau `'legal.deeds'` untuk
biaya spesifik-deed) pada Sales Order yang dihasilkan; Sales tidak pernah perlu tahu skema Legal
di luar pointer itu. Seam yang sama berjalan arah sebaliknya untuk Billing: `ar_invoices`/
`ar_invoice_lines` milik Accounting membawa `subject_type = 'sales.so_lines'` kembali ke baris
order yang ditagih — Accounting tidak pernah perlu tahu skema Sales di luar pointer itu, dan
begitulah cara Sales mengenali event `InvoicePosted`/`PaymentRecorded` sebagai "milik saya"
tanpa FK keras di kedua arah.

**Data finansial non-destruktif:** invoice dibatalkan (void), tidak pernah dihapus; revisi quote
bersifat aditif, tidak pernah ditimpa; pembalikan komisi adalah baris ledger baru, tidak pernah
edit ke settlement yang sudah dibayar — konsisten dengan disiplin jejak-audit yang ditetapkan di
DMS dan CRM, dan diperlukan untuk produk yang konservatif-pembeli-legal dan diaudit-secara-
finansial.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B (Sales Master) → 3E (Quotation, MVP
tanpa approval) → 3F (Sales Order + Credit Check 3K) → 3G (Pricing, dilipat ke 3E/3F) → 3H
(Delivery — hubungkan panggilan opsional `InventoryService::issue()` jika Inventory sudah live,
atau luncurkan 3H tanpanya dan tambahkan panggilan itu nanti, karena bersifat murni aditif) →
konfirmasikan engine AR Accounting (§3D `ACCOUNTING_SPECS.md`) sudah live → 3I (Billing —
sekarang orkestrator `InvoiceRequested`/`PaymentRequested`, bukan ledger) → hubungkan approval
WNE ke 3E/3F/3J begitu integrasi WNE terkonfirmasi bekerja → 3L (Contracts, memberi makan
generator berulang 3I) → 3J (Returns) → 3M (Commission, digerakkan oleh `PaymentRecorded` milik
Accounting) → 3C/3D (Opportunity + Portal) → 3N (Analytics) — ini meluncurkan loop
quote-to-cash yang bekerja (3B→3I) sebelum bagian mana pun dari "bagus untuk dimiliki tapi tidak
memblokir revenue", dengan Accounting sebagai satu prasyarat keras di dalam loop itu.

**Catatan kelayakan jual (marketability)**
- Quote-to-cash + billing berulang + credit control adalah diferensiator kuat melawan
  manajemen praktik berbasis-spreadsheet — langsung dapat dijual ke vertikal Legal sebagai
  "tagih retainer Anda dan lacak siapa yang berutang kepada Anda", tanpa Sales perlu tahu apa
  itu "retainer" (itu hanyalah sebuah Contract + Subscription). Karena Billing sekarang selalu
  dirutekan lewat Accounting, setiap invoice yang dihasilkan Sales benar-PPN/Faktur-Pajak
  secara konstruksi — diferensiator sungguhan untuk dipimpin, bukan sekadar perbaikan
  konsistensi internal.
- Pelacakan komisi adalah upsell alami untuk tenant mana pun dengan tim sales outbound,
  independen dari vertikal.
- Menjaga Sales tetap dapat-berdiri-sendiri (bekerja hanya dengan CRM, tanpa vertikal
  diperlukan) berarti ia bisa dijual ke tenant non-legal, non-property nanti sebagai produk
  manajemen order biasa — lever reusability yang sama yang sudah disediakan CRM dan Schedule.

**Catatan bias MVP:** untuk peluncuran pertama, Opportunity/Portal/Commission/Contract semuanya
bisa dipangkas ke bentuk paling sederhana (record flat, tanpa Kanban, tanpa otomasi
auto-renew, tanpa komisi tiered) tanpa menyentuh skema di atas — postur "pengurangan
feature-flag, bukan re-arsitektur" yang sama yang sudah dipakai untuk cakupan MVP CRM.
