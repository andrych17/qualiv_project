# Modul POS
## Modul Core Bersama — Engine Transaksi Point-of-Sale yang Bisa Dikonfigurasi (profile Retail/Toko-Kelontong + Restoran, Offline-first)

**Kategori modul** (`CLAUDE.md` §2/§10): **Core**. POS tidak punya pengetahuan apa pun tentang
vertikal industri mana pun — ia adalah engine transaksi bersama yang bisa disewa tenant mana pun
apa pun yang mereka jual (top-up retainer meja depan sebuah firma hukum, konter factory-outlet
tenant manufaktur, atau tenant Retail/F&B khusus tanpa modul lain terpasang sama sekali). Modul
ini bukan Platform-level (hidup di DB tenant, schema `POS`, seperti modul Core lainnya) dan tidak
ada bagian mana pun darinya yang memenuhi ambang batas ekstraksi-microservice `CLAUDE.md` §2 hari
ini — bahkan klien offline-nya pun tidak, yang merupakan PWA sisi-browser, bukan proses server.
Modul ini kini terdaftar di `CLAUDE.md` §4/§5/§7A (schema `POS`, diurutkan sesudah HCM dalam
urutan pembangunan Core — lihat di sana untuk alasannya) dan dibuka pada plan `full` di
`config/tenant_modules.php` saja — lihat §7 Item Terbuka untuk apa yang masih butuh keputusan
nyata (tier plan retail/F&B khusus) alih-alih ditebak di sini.

**Satu keputusan arsitektur yang menjadi dasar segalanya** (sesuai brief milik user sendiri, yang
spec ini adopsi sepenuhnya): **jangan bangun sistem Shop POS / Mini-Market POS / Restaurant POS
yang terpisah-pisah.** Bangun satu engine transaksi, dan biarkan **POS Profile** — sebuah baris
config, rung 5 tangga kustomisasi (`CLAUDE.md` §2) — menyalakan atau mematikan kapabilitas per
terminal. **Restoran** dan **Toko Kelontong (Convenience Store)** karena itu bukan modul Vertikal
dalam pengertian `LEGAL`/`PROPERTY` masa depan; mereka adalah **POS Profile** yang hidup di dalam
satu modul Core ini (§3A), sama seperti three-way-match milik Purchase adalah satu engine yang
digunakan secara identik oleh setiap tenant apa pun yang mereka beli. Sebuah profile bukan cuma
checklist kapabilitas, meskipun begitu — Restoran benar-benar butuh tabelnya sendiri
(Floor/Table, Modifier, KDS, §3M–§3O) yang tidak pernah disentuh konter retail murni; bagian-bagian
itu adalah schema sungguhan, di-gate oleh flag kapabilitas profile, bukan placeholder "someday".

---

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

- **Retail, mini-market, dan point-of-sale restoran terlihat seperti tiga produk berbeda**, yang
  menggoda untuk membangun tiga modul yang tidak berhubungan (atau menyewa tiga produk SaaS
  berbeda). Itu melipatgandakan tiga kali beban maintenance untuk developer solo dan, lebih buruk
  lagi, membuang integrasi ERP yang sudah dimiliki platform ini — produk POS yang dibeli terpisah
  butuh sync katalog produknya sendiri, database customer-nya sendiri, laporannya sendiri, yang
  semuanya tidak akan cocok dengan angka ERP sendiri. Setiap konsep khusus-POS yang sudah punya
  jawaban modul-Core (pricing → price list **Sales**, stock → **Inventory**, identitas customer →
  partner **CRM**, AR → **Sales → Accounting**, approval/notifikasi → **WNE**, dokumen/struk →
  **DMS**) harus menggunakan ulang jawaban itu, bukan menduplikasinya — disiplin "jangan miliki
  pekerjaan modul Core yang sudah ada" yang sama yang sudah diterapkan `MES_SPECS.md` dan setiap
  spec lain di platform ini.
- **Konektivitas tidak bisa diandalkan di titik penjualan.** Seorang kasir atau pelayan tidak bisa
  bilang ke customer "internet-nya lagi mati, tolong datang lagi nanti" — penjualan yang hilang
  adalah pendapatan yang hilang dan pengalaman yang buruk, dan khususnya di retail/F&B Indonesia
  (pasar pertama platform ini, sesuai framing Legal-dulu `CLAUDE.md` §1 yang secara alami meluas ke
  basis tenant Retail/F&B masa depan), konektivitas yang terputus-putus di konter fisik adalah
  kasus normal, bukan pengecualian. **Inilah alasan Offline diperlakukan sebagai kebutuhan Fase 1,
  bukan item Advanced/Future** — setiap modul lain di platform ini menunda penanganan offline
  (`LEGAL_SPECS.md` §3M secara eksplisit menunda antrean sync offline-nya sendiri, "belum ada pola
  di codebase ini untuk dibangun di atasnya") — POS adalah modul yang akhirnya membangun pola itu,
  karena ia adalah satu-satunya modul yang tidak bisa dirilis tanpanya.
- **Register fisik butuh disiplin operasionalnya sendiri** yang belum pernah dibutuhkan modul lain
  mana pun di platform ini: rekonsiliasi laci kas, buka/tutup shift, identitas level-terminal,
  hardware (scanner/printer/drawer/scale) — tidak satu pun yang memetakan ke modul Core yang sudah
  ada.
- **Transaksi basket bukan Sales Order.** Pipeline Quotation→Order→Delivery→Invoice milik Sales
  mengasumsikan ada jeda waktu nyata antar langkah dan piutang yang layak di-aging. Basket
  toko-kelontong di-quote, di-order, dibayar, dan di-receipt dalam waktu kurang dari semenit, oleh
  customer walk-in yang tidak berutang apa pun begitu laci ditutup. Memaksa setiap basket melalui
  siklus hidup Sales Order penuh akan menciptakan ratusan "piutang" bersaldo-nol dalam detik yang
  sama setiap hari — noise yang AR aging milik Accounting tidak pernah dimaksudkan untuk menampung.
  §3J menguraikan batas posting yang dihasilkan secara detail; ini adalah keputusan desain
  terpenting dalam spec ini, karena di sinilah POS paling mudah melanggar resolusi "Sales adalah
  satu-satunya caller sisi-AR" yang sudah diputuskan `CLAUDE.md` §11 kalau ditangani sembarangan.
- **Layanan restoran bukan antrean basket, itu meja dengan orang di dalamnya.** Sebuah meja dibuka,
  mengumpulkan order selama sejam, di-split atau di-merge, dan settle sebagai satu bill — status
  meja, routing dapur, dan modifier (sesuai §13–§15 brief user) adalah masalah operasional
  sungguhan mereka sendiri, bukan fitur retail dengan skin berbeda.

**Kebutuhan klien** (diringkas dari brief user; penomoran lengkap §1–§28 brief tersebut disimpan
sebagai komentar cross-reference di dalam masing-masing subbagian di bawah alih-alih direproduksi
verbatim):
- Satu engine transaksi POS + kapabilitas yang bisa dikonfigurasi (POS Profile, §3A) + Restaurant
  Extension dibangun sebagai bagian-bagian yang di-gate profile dari modul yang sama ini, bukan
  produk paralel.
- **Offline-first adalah Fase 1, bukan tempelan** — sebuah terminal terus berjualan, menerima
  pembayaran, dan mencetak struk dengan konektivitas nol, lalu sync (§3S).
- Topologi terminal/branch/register, cash session dengan open/close dan pelaporan variance
  (§3B–3D).
- Pencarian produk yang cukup cepat untuk antrean sungguhan: barcode, PLU, touch grid, favorit
  (§3E), menggunakan ulang master product/barcode/UoM milik Inventory yang sudah ada alih-alih
  katalog kedua.
- Cart dengan hold/park/resume, discount item dan basket, catatan, penugasan customer (§3F).
- Pricing dan promotion menggunakan ulang engine milik Sales di mana bentuknya cocok, memperluas
  di mana aturan level-basket (BxGy, bundle, mix-and-match) belum ada di sana (§3G, §3H).
- Split/partial payment, perhitungan kembalian cash, banyak tipe tender (§3I).
- Return/refund yang otomatis membalik efek inventory dan finansial asli (§3L).
- Restoran: manajemen floor/table, dining mode, modifier, Kitchen Display System (§3M–§3O).
- Konsumsi ingredient berbasis recipe untuk item restoran/prepared-food, lewat **PP**, bukan engine
  BOM kedua (§3P).
- Hardware diperlakukan sebagai adapter yang bisa dipasang-cabut, jangan pernah logika khusus
  device di-inline ke dalam service (§3R).
- Permission yang granular termasuk override supervisor dalam-transaksi yang terpisah dari hak
  trustee level-menu (§3U).

---

# 2. Tujuan (Goals)

> Fitur yang ditetapkan untuk menyelesaikan Latar Belakang di atas, dibertahap untuk developer solo
> (`CLAUDE.md` §10 bias MVP — gaya bertahap yang sama seperti `INVENTORY_SPECS.md`/`MES_SPECS.md`
> §2). **Pilihan bertahap yang disengaja**: Restoran tidak ditunda ke Fase 3 seperti biasanya
> kapabilitas baru — ia adalah kebutuhan utama dalam brief user dan alasan yang bisa dijual mengapa
> tenant dengan konter dine-in menyewa modul ini sama sekali. Yang *ditunda* adalah semua hal yang
> baru penting begitu engine core dan arsitektur offline-nya terbukti end-to-end di jalur
> retail/toko-kelontong yang lebih sederhana — membangun lapisan table/KDS Restoran di atas
> cart/payment/offline core yang belum terbukti berarti mengulang keduanya sekaligus.

## Fase 1 — Core (dibangun lebih dulu): jalur Retail/Toko-Kelontong, end-to-end, mampu offline
- POS Profile & capability matrix (§3A) — walaupun cuma satu profile (`retail`) yang merilis UI
  penuhnya di Fase 1, lapisan config-nya sendiri harus ada lebih dulu, karena semua yang lain
  membacanya.
- Topologi Terminal / Branch / Register, POS Session (cash shift) dengan open/close, cash in/out,
  penghitungan dan variance cash (§3B–3D).
- Katalog produk & pencarian yang mengonsumsi master product/barcode/UoM milik Inventory, termasuk
  scan barcode case-pack dan parsing barcode embedded-weight/price (§3E).
- Cart engine: add/remove/qty/UoM/price override, discount item dan basket, hold/park/resume,
  penugasan customer (§3F).
- Pricing lewat engine Price List milik Sales yang sudah ada (§3G) — belum ada promotion engine di
  Fase 1 (cuma discount % / fixed sederhana, bentuk yang sama yang sudah didukung Quotation milik
  Sales).
- Payment engine: cash/card/QRIS/e-wallet, split payment, perhitungan kembalian (§3I).
- Batas posting AR/Revenue (§3J) — jalur journal ringkasan session-close untuk penjualan walk-in,
  dan jalur on-account customer bernama lewat Sales — keduanya **harus** rilis di Fase 1, karena
  ini keputusan yang menjaga POS dari merusak jaminan AR milik Accounting sejak hari pertama.
- Posting Inventory: stock-out + COGS otomatis saat penjualan selesai, dengan toggle kebijakan
  oversell yang eksplisit (§3K).
- Return/refund dasar dengan reversal stock dan finansial otomatis (§3L).
- **Arsitektur Offline**: cache katalog/price/customer lokal, penangkapan transaksi offline,
  antrean sync yang idempotent, aturan konflik (§3S) — inilah yang membuat sisa Fase 1 nyata untuk
  konter fisik, bukan cuma demo happy-path.
- Cetak struk (58/80mm) dan adapter hardware dasar: scanner, printer, cash drawer (§3R).
- Permission level-menu (`menu.perm:POS_*`) dan override PIN supervisor dalam-transaksi untuk
  discount/void/refund (§3U).

## Fase 2 — Operational: Restaurant Extension + Promotion + Loyalty
- **Restaurant Extension**: manajemen Floor & Table, dining mode (dine-in/takeaway/delivery), baris
  order dengan modifier, split/merge bill, routing course/seat (§3M, §3N).
- **Kitchen Display System**: station, routing order, NEW→PREPARING→READY→SERVED (§3O).
- **Konsumsi recipe**: penjualan POS atas item prepared meledakkan recipe PP-nya dan mengonsumsi
  ingredient lewat Inventory, batas yang sama yang sudah ditetapkan MES untuk produksi (§3P).
- **Promotion Engine**: Buy-X-Get-Y, bundle, mix-and-match, threshold, time-of-day (happy hour),
  customer-tier (§3H).
- **Loyalty / Membership**: point, tier, redemption (§3T) — dimiliki POS, karena baik CRM maupun
  Sales belum punya konsep loyalty hari ini (dicek terhadap kedua spec; lihat catatan batas §3T).
- Gift card / store credit (bersebelahan dengan §3U, lihat §3T).
- Variance price/tax/promotion multi-branch, roll-up pelaporan antar-branch.

## Fase 3 — Advanced (versi masa depan — jangan dibangun sekarang)
- Agregasi order omnichannel (website/marketplace/WhatsApp → antrean order terpadu).
- Mode terminal self-checkout, customer-facing display.
- QR table ordering (customer scan → menu digital → order langsung masuk ke KDS).
- Adapter hardware weighing-scale di luar entry berat manual (Fase 1/2 menerima berat
  yang diketik/discan; integrasi scale serial/network langsung adalah Advanced).
- Komposisi AIInsight (saran reorder yang sadar-demand, promotion berbasis afinitas-basket) —
  gerbang ZDR yang sama seperti fitur AI lainnya (`CLAUDE.md` §5).
- Hardware tingkat lanjut: integrasi langsung card-terminal EMI/payment-gateway di luar penangkapan
  nomor-referensi manual (Fase 1/2 mencatat pembayaran dan referensinya; integrasi processor
  langsung dengan konfirmasi webhook adalah Advanced).

---

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain DB.

## 3A. POS Profile & Capability Matrix

**Fungsi / Fitur**
- `pos_profiles`: code, name, base type (`retail` | `restaurant` | `service` — tiga model operasi
  level-atas dari brief user §1/§27; `service` — appointment/repair — disebut di sini cuma untuk
  kompatibilitas-maju schema, belum dibangun sampai ada use case sungguhan, lihat §7), dan
  sekumpulan flag kapabilitas: `requires_barcode`, `touch_menu`, `multi_uom`,
  `batch_expiry_tracking`, `weight_scale`, `customer_required`, `loyalty_enabled`,
  `promotion_enabled`, `table_management`, `modifiers_enabled`, `kds_enabled`,
  `recipe_consumption`, `delivery_enabled`, `offline_enabled` (default **nyala** untuk setiap
  profile — lihat Latar Belakang), `multi_branch`.
- `pos_terminals.profile_id` menugaskan sebuah profile per terminal — **bukan per tenant**, jadi
  satu branch bisa menjalankan konter depan toko-kelontong dan sudut dine-in kecil di dua terminal
  berbeda tanpa butuh dua tenant atau dua modul.
- Shell POS Vue membaca capability set terminal aktif yang sudah di-resolve sekali saat
  session-open dan secara kondisional me-mount bagian UI (Table view, Modifier picker, panel tiket
  KDS) — prinsip "baca config, jangan cabang berdasarkan identitas tenant" yang sama seperti tangga
  kustomisasi (`CLAUDE.md` §2), diterapkan di lapisan profile alih-alih lapisan tenant.

**Aturan / Logika**
- Dua profile default dirilis bersama modul: `Convenience Store` (barcode-dulu, tanpa
  table/modifier/KDS) dan `Restaurant` (touch-menu-dulu, table/modifier/KDS nyala) — cocok persis
  dengan matriks kapabilitas §28 brief user. Tenant boleh clone dan edit salah satunya alih-alih
  terkunci ke default (tangga kustomisasi rung 3/4: flag kapabilitas hari ini cuma boolean
  sederhana; kalau tenant butuh axis kapabilitas yang benar-benar baru nanti, itu flag baru di
  tabel ini, tidak pernah tenant-branch di kode aplikasi).
- Sebuah flag kapabilitas meng-gate **visibilitas UI dan validasi**, bukan penyimpanan data —
  misalnya baris `pos_txn_hdrs` sebuah terminal `retail` tetap punya kolom `table_id` (tidak
  terpakai); mematikan `table_management` cuma berarti UI tidak pernah menampilkan table picker dan
  layer Store tidak pernah mewajibkannya. Ini menjaga schema §3M–§3O tetap dibagi alih-alih
  bercabang jadi tabel transaksi khusus-restoran yang paralel.

## 3B. Topologi Terminal / Branch / Register POS

**Fungsi / Fitur**
- `pos_terminals`: branch_id (mereferensikan sebuah Branch — `SYSCONFIG` atau master Branch khusus
  masa depan kalau sudah ada; sampai saat itu, lookup `pos_branches` sederhana, karena tidak ada
  modul Core lain saat ini yang memiliki konsep multi-branch formal di luar warehouse milik
  Inventory — lihat catatan §5), warehouse_id (referensi **Inventory** — warehouse mana yang
  digunakan penjualan terminal ini untuk issue stock), profile_id (§3A), code, name,
  default_price_list_id (referensi **Sales**, §3G), konfigurasi tax default, referensi receipt
  template, device fingerprint (untuk identitas klien offline, §3S), is_active.
- Override khusus-terminal: price list, tax, metode pembayaran yang diaktifkan, receipt template —
  masing-masing kolom override opsional di `pos_terminals`, jatuh-balik ke default branch/tenant,
  pola cascade-override yang sama yang sudah digunakan resolusi Price List milik Sales (§3G).
- Konfigurasi hardware (§3R) dilekatkan per terminal, bukan per tenant — satu branch bisa punya
  terminal printer 58mm dan terminal printer 80mm berdampingan.

**Aturan / Logika**
- Sebuah terminal tidak bisa dihapus selagi punya baris `pos_sessions` yang terbuka (§3C) atau
  transaksi offline yang belum sync (§3S) — aturan integritas "tidak bisa hapus yang sedang
  dipakai" yang sama yang diterapkan Inventory ke Location (`INVENTORY_SPECS.md` §3C).

## 3C. POS Session (Cash Shift)

**Fungsi / Fitur**
- `pos_sessions`: terminal_id, cashier (referensi `HCM.employees` atau user `SYSCONFIG` — apa pun
  identitas yang benar-benar dipakai staf tenant untuk login; lihat §5), opened_at, opening_cash,
  status (`open` → `closed`), closed_at, expected_cash (dihitung saat close — opening_cash +
  penjualan cash + cash-in − cash-out − refund cash, sesuai contoh kerja §11 brief user),
  actual_cash (diinput cashier saat close), variance (dihitung), closed_by, `approved_by` (nullable
  — diisi ketika variance melebihi threshold yang bisa dikonfigurasi dan butuh sign-off supervisor,
  §3U).
- **Ini berbeda dari `HCM.shifts`/`HCM.shift_assignments`** (konsep roster-kerja karyawan yang
  dibaca MES secara read-only, `MES_SPECS.md` §3P) — sebuah POS Session adalah session laci-kas
  yang terikat ke terminal, bukan jadwal kerja; seorang cashier bisa open/close POS Session
  independen dari shift HR apa pun yang dia jalani. Tidak ada FK ke tabel shift HCM di Fase 1;
  cross-reference untuk pelaporan saja adalah kemewahan Fase 2, bukan dependency.
- Satu session terbuka per terminal pada satu waktu — memulai session baru butuh menutup (atau
  force-close oleh supervisor) session terbuka sebelumnya dulu.

**Aturan / Logika**
- `pos_cash_movements` (session_id, type `cash_in`/`cash_out`/`petty_cash`, amount, reason,
  user_id, occurred_at) — setiap pergerakan cash non-penjualan adalah barisnya sendiri, tidak
  pernah mutasi `opening_cash`, disiplin append-only yang sama yang sudah diterapkan
  `MES.mes_prod_events`/`INVENTORY.stock_ledger` untuk domainnya masing-masing.
- Session close juga jadi titik pemicu untuk posting journal ringkasan revenue/tax §3J — sebuah
  session tidak bisa close selagi masih punya transaksi offline yang belum sync (§3S), jadi total
  yang di-posting selalu lengkap, tidak pernah snapshot parsial yang direkonsiliasi diam-diam
  belakangan.
- Variance cash melebihi threshold yang dikonfigurasi tenant (`SYSCONFIG.config_consts`, tangga
  kustomisasi rung 1) memblokir close sampai override PIN supervisor ditangkap (§3U) — pola
  approve-lalu-lanjut yang sama seperti approval adjustment besar milik Inventory
  (`INVENTORY_SPECS.md` §3G).

## 3D. Manajemen Cash — Pelaporan

**Fungsi / Fitur**
- Laporan cashier per-session (opening/sales/refund/cash-in/cash-out/expected/actual/variance),
  cocok persis dengan §11 dan §24 brief user "laporan Cashier" — read model di atas baris session
  §3C plus `pos_cash_movements`-nya dan `pos_txn_hdrs` yang selesai untuk session itu, tanpa
  penyimpanan terpisah.
- Roll-up multi-session, multi-terminal per branch/hari — postur read-model yang sama.

## 3E. Katalog Produk & Pencarian

**Fungsi / Fitur**
- POS mengonsumsi Product Master milik **Inventory** yang sudah ada (`INVENTORY_SPECS.md` §3B)
  langsung — tanpa tabel produk kedua. Barcode (termasuk case-pack, `product_barcodes` dengan unit
  multiplier — sudah menyelesaikan contoh §3 brief user "1 Karton = 24 Botol" persis seperti
  di-spec), konversi UoM, batch/serial/expiry, dan category semuanya menggunakan ulang schema
  Inventory yang sudah ada dan Barcode Engine `INVENTORY_SPECS.md` §3K tanpa modifikasi.
- **Satu hal yang benar-benar ditambahkan POS**: parsing barcode EAN-13 embedded-weight/embedded-
  price (umum pada item deli/produce yang diberi label scale — rentang digit prefix yang bisa
  dikonfigurasi per tenant, digit sisanya dipecah jadi kode item + weight atau price sesuai
  template yang bisa dikonfigurasi). Ini dimiliki POS (`pos_weighted_barcode_templates`: rentang
  prefix, span digit kode-item, span digit value, tipe value `weight`/`price`, jumlah desimal)
  karena tabel barcode milik Inventory sendiri tidak punya alasan untuk tahu encoding
  khusus-retail ini.
- Search: scan barcode/weighted-barcode (input text field, postur "bekerja dengan scanner HID mana
  pun, tanpa dependency hardware khusus" yang sama seperti `INVENTORY_SPECS.md` §3K), SKU, name,
  kode PLU (`product_barcodes.type = 'plu'`, menggunakan ulang tabel yang sama alih-alih yang
  baru), browse category touch-grid (profile Restoran/touch), favorit dan recently-sold
  (per-terminal atau per-cashier, `pos_favorite_items`).
- Semua ini harus bekerja penuh **offline** terhadap katalog yang di-cache lokal (§3S) — search
  tidak pernah jadi panggilan network dari sudut pandang cashier.

**Aturan / Logika**
- Produk yang tidak ada di master Inventory tidak bisa dijual di POS kecuali sebagai "Open Item"
  (deskripsi teks bebas + harga manual, `pos_txn_lines.is_open_item = true`, tanpa `product_id`) —
  untuk penjualan lain-lain langka yang tidak ingin di-master-data tenant secara formal untuk
  setiap SKU; baris open-item tidak pernah memposting pergerakan stock (§3K), hanya revenue.

## 3F. Cart Engine

**Fungsi / Fitur**
- Add/remove baris, ubah qty/UoM (dikonversi lewat faktor UoM Inventory yang sudah ada), price
  override level-baris (di-gate permission, §3U) dan discount (%, fixed), discount level-basket,
  catatan (baris dan basket), penugasan customer (default walk-in, §3G... lihat §3I Identifikasi
  Customer), aturan rounding (dikonfigurasi tenant, misalnya bulatkan ke kelipatan 100/500 untuk
  pasar cash-heavy).
- **Hold / Park / Resume**: `pos_txn_hdrs.status = 'parked'` — cart yang di-park bukan draft yang
  masih diedit cashier, itu penjualan yang disisihkan untuk melayani customer lain, sesuai contoh
  kerja §6 brief user. Banyak cart yang di-park per terminal, terdaftar berdasarkan waktu
  park/label (misalnya "Meja 4" dalam konteks service, nama customer, atau cuma nomor urut).
- Status cart hidup di sisi klien dulu (§3S) dan cuma durable di sisi server begitu tersinkron —
  ini berarti Hold/Park harus bekerja penuh offline juga, karena antrean sibuk justru saat
  konektivitas paling mungkin tertekan.

**Aturan / Logika**
- Cart yang di-park otomatis expired (bisa dikonfigurasi, misalnya 4 jam) jadi status cancelled
  untuk menghindari lock stock/price yang "nyangkut" tanpa batas — cuma informasional di Fase 1
  (tidak ada reservation sungguhan yang ditahan terhadap Inventory untuk cart yang di-park; stock
  cuma disentuh saat completion §3K), karena menahan reservation live per basket yang di-park akan
  jadi beban Inventory sungguhan untuk fitur yang seharusnya ringan.

## 3G. Pricing Engine (integrasi Sales, catatan batas)

**Fungsi / Fitur**
- POS **tidak memiliki tabel pricing**. Resolusi harga menggunakan ulang engine milik Sales yang
  sudah ada persis seperti di-dokumentasikan (`SALES_SPECS.md` §3B/§3G): Price List yang ditugaskan
  terminal (§3B) → Price List yang ditugaskan customer (kalau ada customer bernama di basket, §3I)
  → override baris (di-gate permission). Tidak ada konsep price list khusus-POS — "harga POS" yang
  disebut §7 brief user sebagai kemungkinan tier harga cuma baris `SALES.price_lists` lain yang
  di-scope ke terminal/branch POS, sama seperti price list Wholesale atau Member yang sudah ada.
- Price List (dan barisnya) adalah bagian dari cache katalog offline (§3S) — sebuah terminal tidak
  bisa me-resolve pricing secara offline terhadap price list yang belum pernah di-sync-nya.

**Aturan / Logika**
- Disiplin "promotion aditif terhadap pricing price-list, tidak pernah mengedit price list itu
  sendiri" yang sama seperti sudah dinyatakan `SALES_SPECS.md` §3G — Promotion Engine milik POS
  sendiri (§3H) mewarisi aturan ini alih-alih menyatakan aturan berbeda.

## 3H. Promotion Engine (Fase 2)

**Fungsi / Fitur**
- **Mengapa POS memiliki ini alih-alih menggunakan ulang `SALES.promo_codes`**: bentuk promotion
  milik Sales (satu kode, % atau fixed, diterapkan di entry Quotation/Order, `SALES_SPECS.md` §3G)
  cocok untuk alur kerja sales-order dengan manusia mengetik kode. Basket POS butuh *evaluasi
  aturan* real-time, tanpa-kode, level-baris-dan-basket — Buy-X-Get-Y, harga bundle, tier
  mix-and-match, threshold pengeluaran, jendela time-of-day, rate customer-tier (§8 brief user) —
  dievaluasi otomatis saat item discan, dan harus dievaluasi **offline**, terhadap rule set yang
  di-cache lokal, tanpa panggilan live sama sekali. Itu adalah bentuk engine berbeda, bukan versi
  lebih kecil dari yang sama; memaksa POS memanggil Sales untuk setiap item yang discan juga akan
  memunculkan kembali round-trip server yang justru ingin dihilangkan offline-first. `SALES.
  promo_codes` tetap tidak tersentuh dan masih bekerja persis seperti sebelumnya untuk alur
  Quotation/Order milik Sales sendiri; tidak ada apa pun di sini yang mengubahnya.
- `pos_promotion_rules`: name, type (`simple_discount` / `buy_x_get_y` / `bundle` /
  `mix_and_match` / `threshold` / `time_window` / `customer_tier`), scope (product/category/
  basket-wide), value (% atau fixed atau harga bundle), constraints (jsonb — threshold qty, set
  produk yang valid, jendela waktu yang valid, tier/segment customer yang valid), valid_from/
  valid_to, priority (urutan evaluasi ketika banyak rule bisa berlaku), stackable (bool).
- Dievaluasi di sisi klien terhadap rule set yang di-cache (§3S) setiap kali cart berubah — postur
  "search tidak pernah jadi panggilan network" yang sama seperti §3E.
- Tenant yang juga menggunakan `promo_codes` milik Sales untuk channel e-commerce/quote-nya tetap
  bisa menghormati kode yang sama di POS sebagai fallback entry-kode manual
  (`pos_promotion_rules.type = 'promo_code_passthrough'`, membaca bentuk discount kode dari
  `SALES.promo_codes` saat sync, di-cache lokal) — inilah satu-satunya tempat kedua engine bertemu,
  dan cuma sebagai baca, tidak pernah tabel bersama.

**Aturan / Logika**
- Cuma satu rule `simple_discount`/`bundle`/`mix_and_match` yang berlaku per baris kecuali secara
  eksplisit ditandai `stackable` — mencegah discount majemuk yang lepas kendali, postur
  "eksplisit, bukan implisit" yang sama yang disebut brief user sendiri sebagai penting untuk
  kepercayaan-cashier terhadap total yang sudah didiskon.

## 3I. Payment Engine & Identifikasi Customer

**Payment Engine**

**Fungsi / Fitur**
- `pos_payments`: txn_id, method (`cash` / `card` / `qris` / `bank_transfer` / `e_wallet` /
  `voucher` / `gift_card` / `store_credit` / `customer_credit` / `on_account`), amount, reference
  (kode auth/approval, teks bebas — Fase 1/2 tidak mengintegrasikan payment gateway live, sesuai
  §2 Fase 3), change_given (cash saja).
- **Split payment**: banyak baris `pos_payments` per `pos_txn_hdrs`, jumlahnya harus sama dengan
  `grand_total` sebelum penjualan bisa selesai — cocok persis dengan contoh kerja §10 brief user.
- **Partial payment**: diizinkan hanya ketika `on_account`/`customer_credit` jadi salah satu
  tender dan ada customer bernama (Identifikasi Customer §3I di bawah, jangan bingung dengan
  bagian ini) di basket — partial payment terhadap customer walk-in tidak masuk akal (tidak ada
  yang bisa ditagih saldonya belakangan), jadi UI memblokirnya kecuali ada customer yang
  ditugaskan.
- Customer Display (hardware, §3R) mencerminkan total berjalan dan rincian tender secara live.

**Aturan / Logika**
- Redemption gift card dan store credit (§3T) adalah **bahaya double-spend lintas-terminal**
  ketika offline — lihat aturan eksplisit §3S untuk apa yang dilakukan sebuah terminal ketika
  tidak bisa memverifikasi saldo secara live.

**Identifikasi Customer**

**Fungsi / Fitur**
- Walk-in secara default (`pos_txn_hdrs.customer_id = null`, label tampilan "Walk-in").
  Identifikasi opsional terhadap tabel `partners` milik **CRM** (`CRM_SPECS.md` §3B) — search
  berdasarkan telepon/nama/scan kartu-loyalty, atau quick-create (nama + telepon minimum, pola
  lightweight-create yang sama seperti Quick Contact milik CRM sendiri).
- Penugasan customer pada basket membuka: Price List yang ditugaskan (§3G), akrual/redemption
  Loyalty (§3T), pembayaran on-account/partial (§3I), riwayat pembelian (read view di atas
  `pos_txn_hdrs` masa lalu customer, pola "satu store, banyak view" yang sama yang sudah
  didokumentasikan `SALES_SPECS.md` §3B untuk Customer view-nya sendiri di atas `CRM.partners`).
- **POS tidak membuat `customer_sales_profile`** (itu tetap dimiliki Sales sesuai `SALES_SPECS.md`
  §3B) — POS membaca price list yang ditugaskan customer dari profile Sales yang sudah ada kalau
  tenant punya Sales terpasang, dan jatuh-balik ke price list default terminal kalau tidak, postur
  optional-module yang sama yang sudah diterapkan Sales terhadap Inventory (`SALES_SPECS.md`
  §3H).

**Aturan / Logika**
- Identifikasi customer opsional untuk profile `retail`/`convenience` (flag kapabilitas
  `customer_required = false` secara default, §3A) dan bisa diwajibkan per profile untuk tenant
  yang ingin penangkapan loyalty terjamin (misalnya mini-market berbasis membership).

## 3J. Batas Posting AR / Revenue — keputusan desain kritis

> **Bagian ini adalah alasan mengapa resolusi "Sales adalah satu-satunya caller sisi-AR" milik
> `CLAUDE.md` §11 tidak dilanggar diam-diam oleh modul ini.** Baca ini sebelum menyentuh kode
> `pos_txn_hdrs` atau `pos_sessions`.

**Masalahnya**: desain naif merutekan setiap basket yang selesai lewat
`SalesOrderService::createFromExternalRequest(...)` (`SALES_SPECS.md` §3I, entry point yang sama
yang dipakai Legal, `LEGAL_SPECS.md` §2) seperti cara setiap event billable modul lain. Pada volume
toko-kelontong sungguhan (ratusan basket/hari, dibayar penuh di konter dalam transaksi yang sama
saat mereka dibuat) itu memproduksi ratusan Sales Order → AR Invoice → Payment yang langsung
di-apply dalam detik yang sama setiap hari — masing-masing membuka dan menutup saldo AR yang tidak
pernah benar-benar ada sebagai piutang. Itu mencemari AR aging milik Accounting dengan noise, dan
mengubah replay sync offline Fase 1 jadi masalah bulk-Sales-Order-creation alih-alih masalah
bulk-journal-posting.

**Resolusinya — dua jalur posting berbeda, dipilih berdasarkan apakah ada piutang sungguhan:**

- **Walk-in / dibayar-penuh-saat-jual (kasus default — cash, card, QRIS, e-wallet, gift card,
  store credit, tender apa pun yang menutupi total penuh dalam transaksi yang sama)**: **tidak ada
  AR yang dibuat sama sekali.** `pos_txn_hdrs`/`pos_txn_lines`/`pos_payments` (§3F/§3I) adalah
  system of record milik POS sendiri untuk penjualan — tidak ada apa pun lagi yang perlu
  menduplikasinya. Saat **POS Session close** (§3C), POS memanggil
  `AccountingService::postJournal(...)` / memicu `JournalPostingRequested`
  (`ACCOUNTING_SPECS.md` §3R — entry point generik "modul mana pun bisa memposting transaksi
  finansial", secara eksplisit **tidak** dibatasi ke Sales seperti `InvoiceRequested`) **sekali per
  session**, dengan posting ringkasan yang dikelompokkan berdasarkan metode pembayaran dan kode
  pajak: Dr Cash/Bank (per tipe tender) dan Sales Discount, Cr Sales Revenue dan Tax Payable,
  `subject_type = 'pos.pos_sessions'`, `subject_id` = id session. Ini tidak pernah menyentuh akun
  kontrol AR, karena tidak ada apa pun yang berupa piutang dari transaksi yang dibayar penuh saat
  itu terjadi — peran orkestrasi-AR milik Sales ada untuk menjawab "siapa berutang berapa," yang
  tidak relevan di sini, jadi ini bukan pengecualian terhadap aturan `CLAUDE.md` §11, ini kasus
  yang memang tidak dimaksudkan aturan itu untuk dicakup. COGS memposting otomatis dan terpisah,
  sama seperti yang sudah dilakukannya untuk setiap modul lain — panggilan
  `InventoryService::issue()` milik §3K memicu `inventory.goods_issued`, yang
  `InventoryGlPostingService` (`ACCOUNTING_SPECS.md` §3R, sudah dibangun untuk Delivery Engine
  milik Sales sendiri) sudah dengarkan dan posting, tanpa kode khusus-POS sama sekali.
- **Customer bernama, on-account / partial payment (piutang sungguhan — customer berutang saldo
  setelah pergi)**: ini benar-benar piutang, jadi ia melalui jalur biasa yang dipakai setiap modul
  lain — `SalesOrderService::createFromExternalRequest(...)`, `subject_type =
  'pos.pos_txn_hdrs'`, `subject_id` = id transaksi, customer = referensi `CRM.partners`. Sales
  membuat dan mengonfirmasi Sales Order dan memicu `InvoiceRequested` untuk saldo yang belum
  dibayar sesuai Billing Engine-nya sendiri (`SALES_SPECS.md` §3I); tender apa pun yang sudah
  ditangkap di POS (bagian yang dibayar dari partial payment) ikut sebagai `PaymentRequested`
  langsung terhadap invoice itu. POS tetap memiliki `pos_txn_hdrs` sebagai catatannya sendiri atas
  basket; Sales Order adalah bayangan sisi-AR-nya, ditautkan lewat pointer `subject_type`/
  `subject_id`, hubungan satu-arah yang sama seperti yang dimiliki matter Legal terhadap Sales
  Order-nya sendiri.
- **Refund/return (§3L)** mengikuti bayangan dari jalur mana pun yang diambil penjualan asli:
  refund walk-in memposting journal pembalik di session-close berikutnya (atau segera, bisa
  dikonfigurasi tenant, kalau refund cash hari-yang-sama perlu langsung mengenai total till); refund
  on-account meminta `ArCreditNote` dari Accounting persis seperti yang sudah didokumentasikan
  `SALES_SPECS.md` §3J untuk Returns Engine milik Sales sendiri.

**Aturan / Logika**
- Baris `pos_txn_hdrs` tidak pernah jadi dokumen relevan-AR sendiri — Accounting tidak pernah
  meng-query atau mereferensikan schema POS secara langsung, aturan dependency satu-arah `Core →
  Accounting` yang sama yang dinyatakan `ACCOUNTING_SPECS.md` untuk setiap caller lain.
- Kalau Accounting tidak terpasang untuk tenant, POS tetap berfungsi (sale, payment, receipt,
  posting inventory) — panggilan journal session-close dan jalur Sales Order on-account keduanya
  cuma dilewati, postur optional-module yang sama yang sudah didokumentasikan `SALES_SPECS.md`
  §3I untuk Billing Engine-nya sendiri ketika Accounting tidak terpasang.
- Kalau Sales tidak terpasang tapi Accounting ada, jalur on-account tidak punya entry point (Sales
  memilikinya secara eksklusif) — on-account/partial payment dinonaktifkan di UI untuk tenant itu;
  penjualan walk-in yang dibayar penuh tidak terpengaruh, karena jalur journal session-close tidak
  bergantung pada Sales sama sekali.

## 3K. Posting Inventory

**Fungsi / Fitur**
- Saat penjualan selesai, POS memanggil `InventoryService::issue(...)` (`INVENTORY_SPECS.md` §3E)
  per baris, `subject_type = 'pos.pos_txn_lines'`, dari warehouse yang ditugaskan terminal (§3B) —
  Inventory membuat entri `goods_issues`/`stock_ledger`-nya sendiri dan mengonsumsi valuation layer
  sesuai metode costing-nya, persis pola yang sudah ditetapkan untuk Delivery Engine milik Sales
  (`SALES_SPECS.md` §3H) dan Material Consumption milik MES (`MES_SPECS.md` §3J). POS tidak
  menulis ke `INVENTORY.stock_ledger` secara langsung, dan tidak mengimplementasikan logika
  costing-nya sendiri.
- Baris open-item (§3E) dan produk tipe-service tidak pernah memanggil `InventoryService` — tanpa
  efek stock.
- Produk yang dilacak batch/serial/expiry (`INVENTORY.stock_batches`/`stock_serials`) di-resolve
  saat waktu scan dari katalog yang di-cache (§3S); batch/serial spesifik yang dikonsumsi dicatat
  di `pos_txn_lines` persis seperti modul terintegrasi-Inventory mana pun.

**Aturan / Logika**
- **Kebijakan oversell (aturan kritis-offline)**: Goods Issue milik Inventory normalnya *memblokir*
  posting ketika qty yang diminta melebihi yang tersedia (`INVENTORY_SPECS.md` §3E) — sebuah
  register tidak bisa menerapkan blok itu dengan cara yang sama. Terminal live yang membaca
  `checkAvailability()` sebagai informasional-saja (memperingatkan, tidak pernah memblokir —
  postur yang sama seperti strip ketersediaan-komponen milik MES sendiri, `MES_SPECS.md` §3G)
  baik-baik saja saat online; tapi **penjualan offline diposting belakangan**, mungkin terhadap
  stock yang sudah habis oleh penjualan offline terminal lain yang diantrekan untuk jendela sync
  yang sama. POS karena itu memperkenalkan toggle `SYSCONFIG.config_consts` level-tenant,
  `POS_ALLOW_OVERSELL` (tangga kustomisasi rung 1, default **nyala**): ketika nyala,
  `InventoryService::issue()` dipanggil dengan flag override `allow_negative = true` yang
  eksplisit yang diteruskan POS (parameter baru yang sempit di panggilan yang sudah ada — bukan
  perubahan perilaku untuk caller lain mana pun), dan saldo on-hand negatif yang dihasilkan
  ditandai di Dashboard (§3U) sebagai variance untuk diselidiki, tidak pernah disembunyikan diam-
  diam. Ketika mati, penjualan offline yang akan oversell tetap selesai di POS (sebuah register
  tidak bisa menolak penjualan di tengah antrean) tapi posting Inventory yang dihasilkan diantrekan
  ke daftar exception review-manual alih-alih posting otomatis — pilihan tenant antara "selalu
  biarkan penjualan terjadi, rekonsiliasi stock belakangan" dan "jangan pernah oversell diam-diam,
  review sebelum kena ledger."

## 3L. Return / Refund

**Fungsi / Fitur**
- `pos_return_hdrs`/`pos_return_lines`: referensi `txn_id` asli, kode alasan, qty/kondisi
  level-baris, metode refund (tipe tender yang sama seperti Payment, §3I, plus `store_credit`/
  `voucher` sebagai tujuan khusus-refund), status (`requested` → `approved` → `completed`).
- **Reversal otomatis** (sesuai §12 brief user): menyelesaikan return memanggil
  `InventoryService::receive(...)` untuk baris yang bisa direstock (membalik issue §3K) dan
  mengikuti jalur posting yang dibayangkan §3J untuk reversal finansial — tidak pernah dua-langkah
  manual "adjust stock, lalu adjust buku secara terpisah".
- Return-tanpa-receipt-asli: diizinkan kalau dikonfigurasi tenant, butuh PIN manager (§3U), harga
  default ke price list saat ini alih-alih harga asli yang tidak diketahui.

**Aturan / Logika**
- Return di atas threshold nilai yang bisa dikonfigurasi melalui **WNE**
  (`workflow_code = pos.return_approval`) untuk approval manager sebelum menyelesaikan — pola
  approval-opsional yang sama yang sudah dipakai `SALES_SPECS.md` §3J untuk Returns Engine-nya
  sendiri.

## 3M. Restaurant Extension — Manajemen Floor & Table (Fase 2, kapabilitas `table_management`)

**Fungsi / Fitur**
- `pos_floors` (name, referensi layout) → `pos_tables` (floor_id, code/label, jumlah kursi,
  posisi x/y untuk visual floor-plan view, status: `available` / `occupied` / `reserved` /
  `cleaning`).
- `pos_txn_hdrs.table_id` (nullable — cuma berarti ketika `table_management` nyala, §3A/§3M)
  menautkan transaksi ke sebuah table; satu table bisa membawa banyak transaksi terbuka sepanjang
  siklus hidup dining-nya sampai settle (misalnya minuman dipesan terpisah dari makanan utama,
  tetap satu bill saat close).
- Operasi: open table (membuat transaksi aktif table itu), assign waiter, move table
  (menugaskan-ulang `table_id`), merge table (menggabungkan baris dari dua transaksi aktif jadi
  satu, void yang lain), split table (kebalikannya — memindahkan sebagian baris ke transaksi
  baru), transfer, split bill (membagi total satu transaksi ke N set pembayaran tanpa membagi
  barisnya sendiri — view alokasi-pembayaran, bukan split level-baris), merge bill (kebalikannya).
- Visual floor-plan view: `pos_tables` dirender pada posisi x/y-nya dengan status berwarna
  `StatusBadge`, disiplin komponen-bersama yang sama seperti view lain mana pun (`CLAUDE.md`
  §9D6).

**Aturan / Logika**
- Sebuah table tidak bisa ditandai `available` selagi punya transaksi terbuka (bukan `completed`/
  `cancelled`) — aturan integritas "tidak bisa hapus/bebaskan yang sedang dipakai" yang sama yang
  sudah diterapkan ke Location (`INVENTORY_SPECS.md` §3C) dan Terminal milik modul ini sendiri
  (§3B).

## 3N. Restaurant Extension — Baris Order & Modifier (Fase 2, kapabilitas `modifiers_enabled`)

**Fungsi / Fitur**
- `pos_modifier_groups` (name, tipe seleksi `single`/`multiple`, min/max selection) →
  `pos_modifiers` (group_id, name, price delta — aditif atau item group-nya bisa ditandai
  `replaces_base_price` untuk group bergaya size-tier). Dilekatkan ke produk lewat
  `pos_product_modifier_groups` (product_id, group_id) — menggunakan ulang `product_id` milik
  Inventory, tanpa konsep produk kedua.
- `pos_txn_line_modifiers` (txn_line_id, modifier_id, price delta yang ditangkap saat waktu
  penjualan — tidak pernah di-resolve-ulang dari harga modifier saat ini belakangan, disiplin
  "nilai yang sudah diresolve bertahan melewati edit master-data belakangan" yang sama yang sudah
  diterapkan `MES_SPECS.md` §3B ke resolusi BOM/Recipe).
- Instruksi khusus (teks bebas, misalnya "tanpa bawang"), catatan dapur, course (`appetizer`/
  `main`/`dessert`/dll, lookup yang bisa diedit tenant), nomor seat — semua di `pos_txn_lines`.

**Aturan / Logika**
- Constraint min/max selection sebuah modifier group diterapkan di sisi klien saat waktu
  add-to-cart (mampu offline, postur yang sama seperti evaluasi promotion §3H), bukan cuma di sisi
  server saat sync.

## 3O. Kitchen Display System (Fase 2, kapabilitas `kds_enabled`)

**Fungsi / Fitur**
- `pos_kds_stations` (name, misalnya Kitchen/Bar/Dessert — target routing-printer dan
  routing-screen). `pos_txn_lines.kds_station_id` di-resolve dari station yang dikonfigurasi
  produk (`pos_product_kds_routing`), jadi satu order otomatis terbagi ke station yang tepat.
- Status tiket per baris: `new` → `preparing` → `ready` → `served`, masing-masing tulisan
  berstempel-waktu — disiplin bergaya-event yang sama seperti `MES.mes_prod_events`
  (`MES_SPECS.md` §3C), meskipun di-scope kecil di sini sampai tidak perlu tabel ledger terpisah;
  kolom status + timestamp di `pos_txn_lines`/tabel ringan `pos_kds_ticket_events` sudah cukup
  pada volume ini.
- Layar KDS: antrean tiket real-time (polling atau broadcast, sesuai §5) per station, priority,
  waktu berlalu, flag item-dibatalkan, aksi re-fire (membuka-ulang baris `served`/`ready` kembali
  ke `new` dengan catatan audit — tidak pernah duplikat diam-diam).
- Routing kitchen printer sebagai satu lagi adapter hardware (§3R) bersebelahan dengan receipt
  printer — sebuah tiket bisa print, tampil di layar KDS, atau keduanya, sesuai hardware tenant.

**Aturan / Logika**
- KDS butuh konektivitas untuk berguna lintas banyak screen/printer secara real-time — ketika
  terminal offline (§3S), routing KDS turun ke print tiket lokal saja di kitchen printer yang
  dilekatkan terminal itu sendiri (tidak ada sync lintas-station yang mungkin tanpa network),
  dimunculkan dengan jelas ke cashier/waiter alih-alih gagal diam-diam.

## 3P. Konsumsi Recipe — Dimiliki Batas PP/MES (umum untuk kedua profile)

**Fungsi / Fitur**
- Sebuah produk prepared-food (hidangan restoran, atau item deli/bakery retail yang dibuat
  in-house) me-resolve recipe aktifnya lewat `PpService::getActiveRecipe(productId)`
  (`PP_SPECS.md` §3D, kontrak yang sama yang sudah dikonsumsi `MES_SPECS.md` §3B) pada saat
  penjualan, meledakkannya jadi qty ingredient, dan meng-issue setiap ingredient lewat
  `InventoryService::issue()` — POS tidak memiliki tabel recipe/BOM sendiri, batas "jangan
  duplikasi modul Core yang sudah ada" yang sama yang sudah ditetapkan MES untuk komposisi
  material-nya sendiri.
- Ini adalah **postur optional-module**: kalau PP tidak terpasang, produk cuma dijual sebagai satu
  baris finished-goods dengan issue stock langsungnya sendiri (§3K) dan tanpa peledakan
  ingredient — pola graceful-degradation yang sama yang sudah diterapkan Sales ketika Inventory
  tidak terpasang (`SALES_SPECS.md` §3H).

**Aturan / Logika**
- Konsumsi ingredient dari penjualan POS **tidak** dirutekan lewat mesin production-order milik
  MES (`mes_prod_order_hdrs`) — tidak ada langkah eksekusi shop-floor untuk order bar menuang
  minuman; itu adalah peledakan-recipe-ke-issue langsung, sepupu ringan dari eksekusi
  batch/process milik MES sendiri untuk konteks yang tidak butuh work order, routing, atau
  gerbang QC.

## 3Q. Integrasi Hardware

**Fungsi / Fitur**
- Interface `POSHardwareAdapter` (mencerminkan pola pluggable-adapter `IotProtocolAdapter` milik
  MES, `MES_SPECS.md` §3S) — adapter konkret untuk: receipt printer (58mm/80mm/A4, ESC/POS lewat
  USB/network), kitchen printer (§3O), cash drawer (dibuka lewat kick-pulse printer atau USB
  langsung), barcode scanner (input-text HID, tanpa adapter yang dibutuhkan — sudah dicakup §3E),
  customer display (pole display atau tab/window browser kedua yang mencerminkan total berjalan),
  weighing scale (Fase 1/2: entry berat manual/diketik saja; adapter scale live adalah Fase 3,
  §2), card/payment terminal (Fase 1/2: penangkapan nomor-referensi manual saja; adapter gateway
  live adalah Fase 3).
- Config hardware hidup per terminal (§3B) — `pos_terminal_devices` (terminal_id, device_type,
  adapter_code, config koneksi jsonb).

**Aturan / Logika**
- Jangan pernah meng-inline logika protokol khusus-device ke dalam service `POS` — disiplin yang
  sama yang sudah dinyatakan `MES_SPECS.md` §3S untuk adapter protokolnya sendiri; kegagalan
  hardware (printer offline, drawer macet) turun secara graceful (antrekan job print, munculkan
  retry on-screen yang jelas) dan tidak pernah memblokir penjualan yang mendasarinya untuk
  selesai.

## 3R. Loyalty / Membership & Gift Card / Store Credit (Fase 2/3)

**Fungsi / Fitur**
- **Mengapa POS memiliki ini**: dicek terhadap `CRM_SPECS.md` (Contacts/Companies/Leads/After
  Sales/Helpdesk — tanpa konsep loyalty) dan `SALES_SPECS.md` (Price Lists/Credit — tanpa konsep
  point/tier juga) — tidak satu pun modul Core yang mengklaim wilayah ini, dan ini secara
  fundamental adalah concern yang digerakkan-volume-transaksi (point mengakru per basket), jadi
  POS adalah pemilik alaminya. Kalau ada kebutuhan masa depan untuk loyalty bisa dipakai di luar
  POS (misalnya channel e-commerce milik Sales sendiri mengakru point yang sama), mempromosikan
  ini jadi concern Core-nya sendiri adalah opsi sungguhan nanti — tidak ditebak di sini, lihat §7.
- `pos_loyalty_tiers` (name, rate point-per-unit-currency, tier threshold) →
  `pos_loyalty_accounts` (`customer_id` — referensi CRM.partners, tier saat ini, saldo point) →
  `pos_loyalty_ledger` (account_id, txn_id nullable, type `earn`/`redeem`/`expire`/`adjust`, delta
  point, occurred_at) — append-only, disiplin yang sama seperti ledger lain mana pun di platform
  ini.
- `pos_gift_cards` (code, balance, currency, expiry, status `active`/`redeemed`/`expired`) dan
  `pos_store_credits` (customer_id, balance, referensi source misalnya sebuah return, §3L) —
  keduanya bisa dipakai sebagai tipe tender Payment Engine (§3I).

**Aturan / Logika**
- **Double-spend lintas-terminal ketika offline**: saldo gift card/store-credit yang di-cache
  lokal bisa basi sesaat setelah terminal kedua me-redeem dari kartu yang sama selagi offline.
  Kebijakan default (`SYSCONFIG.config_consts`, bisa dikonfigurasi tenant): redemption
  gift-card/store-credit/point-loyalty **butuh konektivitas** (diblokir di terminal dengan pesan
  yang jelas kalau offline — "Redemption gift card butuh koneksi — coba cash/card, atau
  sambungkan ulang dan coba lagi," sesuai panduan suara `DESIGN.md`) sementara akrual
  gift-card/loyalty (menerbitkan kartu baru, mendapatkan point dari penjualan) selalu aman-offline,
  karena akrual tidak bisa di-double-spend, hanya redemption yang bisa. Tenant yang menerima
  risiko fraud kecil boleh membalik redemption jadi diizinkan-offline; konflik saldo yang
  dihasilkan lalu jadi laporan rekonsiliasi Fase 2 (tandai akun mana pun yang jumlah ledger-nya
  jadi negatif setelah sync), tidak pernah diserap diam-diam.

## 3S. Arsitektur Offline (Fase 1 — kapabilitas penentu modul ini)

> **Pola offline-first / PWA pertama sungguhan di codebase ini.** `LEGAL_SPECS.md` §3M secara
> eksplisit menunda antrean sync offline field-visit-nya sendiri, "belum ada pola di codebase ini
> untuk dibangun di atasnya" — bagian ini adalah pola itu, dibangun sungguhan karena POS tidak bisa
> dirilis tanpanya. Apa pun yang mendarat di sini seharusnya ditulis supaya item yang ditunda milik
> Legal sendiri (dan modul masa depan mana pun yang butuh toleransi offline) bisa dibangun di
> atasnya alih-alih menciptakan pendekatan ketiga.

**Fungsi / Fitur**
- **Klien**: sebuah PWA (bisa diinstal, didukung service-worker) dibangun di atas frontend Vue
  3/Inertia yang sudah ada khusus untuk shell POS — bukan seluruh app admin. Sesuai batas Web vs
  klien masa depan `CLAUDE.md` §2 ("Rilis REST hanya ketika klien non-Inertia itu nyata, bukan
  spekulatif"), POS offline **memang** kasus nyata dan non-spekulatif itu (ambang yang sama yang
  sudah dilewati `LEGAL_SPECS.md` §3M untuk surface mobile field-visit-nya sendiri) — jadi shell
  POS dilayani lewat surface REST tipis dan ber-versi `api/v1/pos/*` alih-alih siklus
  request/response Inertia, karena Inertia tidak punya cerita offline sama sekali. Menggunakan
  ulang pola auth yang sama yang sudah dibangun untuk API mobile milik Legal: token bearer Sanctum
  + header `X-Tenant-Id`, middleware `InitializeTenancyByHeader` (`LEGAL_SPECS.md` §3M), tanpa
  mekanisme auth baru yang diciptakan.
- **Penyimpanan lokal**: IndexedDB (lewat wrapper tipis, misalnya Dexie.js — penambahan dependency
  sungguhan, dijustifikasi karena membuat logika transaksi IndexedDB sendiri dari nol untuk volume
  data yang disinkronkan ini persis roda yang menurut disiplin coding Ponytail `CLAUDE.md` tidak
  boleh diciptakan-ulang) yang menyimpan: katalog produk + barcode + UoM yang di-cache (§3E), price
  list aktif (§3G), rule promotion aktif (§3H), subset lookup customer (§3I, misalnya yang
  belum-lama-bertransaksi + terindeks-kartu-loyalty, bukan seluruh basis customer tenant), status
  POS Session yang terbuka (§3C), dan **antrean sync keluar** (setiap baris `pos_txn_hdrs`/
  `pos_payments`/`pos_return_hdrs`/`pos_cash_movements` yang dibuat/dimodifikasi offline).
- **Sync**: saat reconnect, klien mem-post antreannya ke `api/v1/pos/sync` dalam batch
  berurutan-klien; server menerapkan setiap mutasi yang diantrekan, mengembalikan
  sukses/konflik per-item, dan klien membersihkan item yang sudah sync / memunculkan konflik untuk
  yang gagal.
- **Refresh cache**: cache katalog/price/promotion/customer di-refresh secara oportunistik kapan
  pun online (background, non-blocking) dan saat session-open — terminal yang sudah offline
  berhari-hari tetap berjualan terhadap cache yang basi alih-alih tidak berjualan sama sekali,
  yang justru itulah intinya.
- **Persistensi penyimpanan**: klien memanggil `navigator.storage.persist()` saat checkin/
  session-open untuk meminta bucket penyimpanan "persistent" milik browser, supaya cache
  IndexedDB dan antrean sync keluar tidak diam-diam dihapus akibat tekanan disk sebagaimana
  bisa terjadi pada penyimpanan "best-effort" — terminal POS tidak boleh sampai kehilangan
  transaksi yang sudah diantrekan namun belum ter-sync akibat pembersihan storage browser.

**Aturan / Logika — idempotency adalah aturan penopang seluruh bagian ini**
- Setiap transaksi yang dibuat offline membawa **UUID yang dibuat-klien**
  (`pos_txn_hdrs.client_txn_uuid`, unik, dihasilkan saat waktu pembuatan-cart, bukan saat waktu
  sync) — sync bersifat idempotent terhadap key ini: request sync yang diulang/diduplikasi untuk
  UUID yang sama adalah no-op di percobaan kedua dan seterusnya, tidak pernah double-post stock
  atau revenue. Ini adalah aturan kebenaran terpenting di bagian ini; setiap jalur tulis yang
  disentuh bagian ini (posting journal §3J, issue inventory §3K, pencatatan payment §3I) harus
  berpegang padanya.
- Harga/tax/discount sebuah penjualan offline adalah **fakta yang dicatat, bukan query** — sync
  tidak pernah boleh me-reprice ulang transaksi yang diantrekan terhadap tampilan master-data saat
  ia sync. Transaksi membawa harga baris, jumlah tax, dan hasil promotion yang sudah sepenuhnya
  di-resolve seperti dihitung offline pada `occurred_at`; server mempercayai dan memposting apa
  adanya (tunduk pada guard oversell/redemption di §3K/§3R, yang merupakan pemeriksaan kebijakan,
  bukan re-pricing).
- Dua timestamp selalu dicatat dan dijaga tetap berbeda: `occurred_at` (jam terminal itu sendiri,
  kapan penjualan sebenarnya terjadi) dan `synced_at` (waktu penerimaan server) — pelaporan (§3U)
  selalu berdasarkan `occurred_at`, tidak pernah `synced_at`, jadi penjualan yang dibuat jam 2
  siang dan sync jam 6 sore tetap dilaporkan sebagai penjualan jam 2 siang.
- **Nomor receipt tidak bisa memakai `SYSCONFIG.config_snums`** — engine itu adalah counter
  live-DB atomik `SELECT ... FOR UPDATE` (`SYSCONFIG_SPECS.md` §3D) dan tidak bisa dialokasikan
  selagi offline. POS sebagai gantinya memberi setiap terminal sequence miliknya sendiri secara
  lokal: `pos_terminals.receipt_prefix` (misalnya `POS01`) + counter `last_local_seq` yang
  di-increment di sisi klien tanpa perlu lock (satu device, tanpa writer konkuren) — nomor receipt
  adalah `{prefix}-{seq}` (misalnya `POS01-000123`), unik secara global per terminal tanpa pernah
  menyentuh server. Ini adalah deviasi eksplisit yang disengaja dari pola counter tenant-wide yang
  dipakai setiap modul lain (kategori deviasi yang sama yang sudah diukir `SYSCONFIG_SPECS.md`
  §3D untuk `protocol_books` milik Legal, yang juga butuh scope locking-nya sendiri alih-alih
  engine generik) — dicatat di sini supaya tidak dikira kesalahan belakangan.
- Penanganan konflik sengaja dibuat sempit: satu-satunya konflik sungguhan adalah kasus oversell
  (§3K) dan double-spend redemption (§3R), keduanya sudah diberi aturan kebijakan eksplisit di
  atas — tidak ada langkah "merge" generik, karena transaksi POS offline adalah fakta immutable
  begitu dibuat (postur append-only yang sama seperti ledger mana pun di platform ini), tidak
  pernah diedit belakangan oleh sync.

## 3T. Keamanan & Permission

**Fungsi / Fitur**
- **Trustee level-menu** (`menu.perm:POS_*` — `POS_TERMINAL`, `POS_SESSION`, `POS_SALE`,
  `POS_RETURN`, `POS_REPORTS`, `POS_ADMIN`) lewat middleware trustee `SYSCONFIG`, sama seperti
  modul lain mana pun (`CLAUDE.md` §4) — mengatur layar/aksi POS mana yang bisa dijangkau role
  user yang login sama sekali.
- **Override supervisor dalam-transaksi** — concern yang berbeda dari di atas: seorang cashier
  yang login dengan hak `POS_SALE` mengenai aksi elevated tertentu di tengah transaksi (discount
  di atas threshold, void item, void penjualan yang sudah selesai, refund, price override, buka
  cash-drawer di luar penjualan, membuka-ulang session yang sudah ditutup — matriks §23 brief
  user) dan harus menangkap **PIN supervisor/manager** (kode numerik singkat yang dicek terhadap
  akun user itu sendiri, bukan password bersama) tanpa mengeluarkan cashier lalu login lagi.
  `pos_override_logs` (txn_id atau session_id, action_type, requested_by (cashier), authorized_by
  (supervisor yang PIN-nya dimasukkan), reason nullable, occurred_at) — setiap override adalah
  baris audit-nya sendiri, mencerminkan postur audit append-only yang dipakai modul lain mana pun
  (`mes_audit_logs`, `config_audit_logs`, dll).
- Entry PIN sendiri mampu offline (dicek terhadap hash user/PIN yang di-cache lokal dari sync
  terakhir, §3S) — sebuah override tidak bisa diblokir oleh pemadaman konektivitas lebih dari
  sebuah penjualan bisa.

**Aturan / Logika**
- Matriks role §23 brief user (Cashier / Supervisor / Manager) diimplementasikan sebagai
  **kombinasi** trustee level-menu (bisakah role ini menjangkau layar Sale/Refund sama sekali) dan
  config threshold-override per-aksi (`SYSCONFIG.config_consts`, misalnya
  `POS_DISCOUNT_PIN_ABOVE = 10%`) — bukan tabel role ketiga khusus-POS.

## 3U. Report & Dashboard

**Fungsi / Fitur**
- Operasional: penjualan per jam/cashier/terminal/branch/produk/category, campuran metode
  pembayaran, discount, return, void — semua read model di atas §3F/§3I/§3K/§3L, disusun dari
  `DataTable`/`Panel`/`StatCard` (`CLAUDE.md` §9D4/5) sesuai design system bersama.
- Finansial: gross/net sales, discount, tax, COGS, gross profit/margin — COGS dan margin ditarik
  dari valuation milik Inventory sendiri (`INVENTORY_SPECS.md` §3I), tidak pernah dihitung ulang
  secara independen oleh POS.
- Cash: laporan cashier per-session (§3D).
- Dashboard "apa yang sedang terjadi sekarang" live (sesuai §25 brief user): penjualan/transaksi/
  customer/avg-ticket/gross-profit hari ini, penjualan per jam, produk teratas, campuran
  pembayaran — dan, ketika `table_management`/`kds_enabled` nyala, tile khusus-restoran (table
  yang terbuka, order, kedalaman antrean dapur, avg prep/table time, sesuai contoh dashboard
  restoran brief).
- Semua angka dihitung dari tabel §3F/§3I/§3K/§3L/§3C-nya sendiri — tanpa tabel penyimpanan KPI
  terpisah, postur "cache/materialize hanya kalau satu query spesifik terbukti terlalu lambat
  pada skala nyata" yang sama yang sudah dinyatakan `MES_SPECS.md` §3O untuk dashboard OEE-nya
  sendiri.

---

# 4. Penyimpanan

> Tabel dan tata-letak schema di bawah schema tenant `POS` PostgreSQL.

**Tabel master / config**
- `POS.pos_profiles` (§3A)
- `POS.pos_branches` (§3B — hanya kalau tidak ada konsep Branch modul Core lain untuk digunakan
  ulang; lihat catatan §5), `POS.pos_terminals`, `POS.pos_terminal_devices` (§3B, §3Q)
- `POS.pos_weighted_barcode_templates` (§3E)
- `POS.pos_favorite_items` (§3E)
- `POS.pos_promotion_rules` (§3H, Fase 2)
- `POS.pos_floors`, `POS.pos_tables` (§3M, Fase 2)
- `POS.pos_modifier_groups`, `POS.pos_modifiers`, `POS.pos_product_modifier_groups` (§3N, Fase 2)
- `POS.pos_kds_stations`, `POS.pos_product_kds_routing` (§3O, Fase 2)
- `POS.pos_loyalty_tiers` (§3R, Fase 2)

**Tabel transaksi**
- `POS.pos_sessions` (§3C) — mereferensikan `HCM.employees`/user, `POS.pos_terminals`
- `POS.pos_cash_movements` — append-only (§3C/§3D)
- `POS.pos_txn_hdrs` (§3F/§3J) — mereferensikan `POS.pos_sessions`, `POS.pos_terminals`,
  `CRM.partners` (nullable), `POS.pos_tables` (nullable), `SALES.price_lists`; membawa
  `client_txn_uuid` (§3S) dan `occurred_at`/`synced_at`
- `POS.pos_txn_lines` — mereferensikan `INVENTORY.products`, `INVENTORY.stock_batches`/
  `stock_serials` (nullable)
- `POS.pos_txn_line_modifiers` (§3N, Fase 2)
- `POS.pos_kds_ticket_events` (§3O, Fase 2)
- `POS.pos_payments` (§3I) — mereferensikan `POS.pos_gift_cards`/`pos_store_credits` kalau berlaku
- `POS.pos_return_hdrs`, `POS.pos_return_lines` (§3L)
- `POS.pos_loyalty_accounts`, `POS.pos_loyalty_ledger` — append-only (§3R, Fase 2)
- `POS.pos_gift_cards`, `POS.pos_store_credits` (§3R, Fase 2)
- `POS.pos_override_logs` — append-only (§3U)

**Custom fields:** `pos_txn_hdrs` didaftarkan sebagai entity extensible di
`CUSTOMFIELDS.field_defs`, pola yang sama seperti header transaksi modul Core lain mana pun.

**Object File** (sesuai `CLAUDE.md` §7B):
- POS tidak memiliki folder R2 level-atas sendiri — receipt PDF (kalau diarsipkan di luar salinan
  cetak) dan dokumen terlampir apa pun dirutekan lewat struktur **DMS** (subfolder `DMS/POS/...`)
  dengan pointer `subject_type`/`subject_id`, sama seperti yang sudah dilakukan WNE/HCM/Sales/MES.

**Sisi klien (bukan DB tenant — lihat §3S):** penyimpanan IndexedDB yang mencerminkan subset
katalog/price/promotion/customer yang di-cache dan antrean sync keluar; tidak pernah system of
record, selalu bisa dibangun-ulang dari sync yang segar.

---

# 5. Catatan Teknis

- **Satu engine, kapabilitas yang di-gate profile**: `pos_profiles` (§3A) adalah satu-satunya
  titik cabang, persis seperti `production_model` untuk MES — setiap engine lain (cart, payment,
  posting inventory, batas AR, sync offline) agnostik-profile dan membaca/menulis tabel bersama
  yang sama; hanya UI yang me-mount bagian berbeda berdasarkan capability set yang sudah
  di-resolve. Ini adalah keputusan arsitektur inti yang menjadi dasar pembangunan modul ini,
  secara langsung mengadopsi rekomendasi inti brief user sendiri ("satu engine transaksi POS +
  sales mode yang bisa dikonfigurasi + extension khusus-industri").
- **POS tidak pernah memiliki ledger yang tidak perlu dimilikinya**: pricing milik Sales (§3G),
  stock/COGS milik Inventory (§3K), identitas customer milik CRM (§3I), AR (kalau sungguhan)
  milik Sales (§3J), komposisi material untuk item prepared milik PP (§3P) — POS memiliki *fakta
  transaksi* (apa yang dijual, ke siapa, berapa harganya, dibayar bagaimana) plus lapisan
  operasional khusus-retail/restoran (session/cash, table/KDS, sync offline, loyalty) yang tidak
  punya alasan dimiliki modul Core lain mana pun.
- **Batas posting AR (§3J) adalah tempat modul ini paling mudah melanggar** resolusi "Sales
  adalah satu-satunya caller sisi-AR" yang sudah diputuskan `CLAUDE.md` §11. Modul ini tidak
  melanggarnya, karena penjualan walk-in yang dibayar penuh memang bukan AR sejak awal — mereka
  memakai kontrak `JournalPostingRequested` yang terpisah dan selalu tersedia, bukan
  `InvoiceRequested`. Perubahan masa depan apa pun ke modul ini yang membuat POS memanggil
  `AccountingService::createInvoice(...)` atau memicu `InvoiceRequested` secara langsung
  (melewati Sales) adalah regresi terhadap spec ini, bukan shortcut yang valid.
- **Konsep Branch**: tidak ada modul Core lain hari ini yang memiliki entity multi-branch formal
  di luar Warehouse milik Inventory (`INVENTORY_SPECS.md` §3C) — POS memperkenalkan lookup
  `pos_branches` minimal hanya kalau tidak ada yang lebih baik pada saat ini dibangun; kalau
  SysConfig atau modul lain sudah memformalkan konsep Branch/Location tenant-wide, POS
  seharusnya mereferensikan itu alih-alih menambah yang kedua (dilacak di §7, tidak diselesaikan
  di sini karena bergantung pada waktu urutan pembangunan di luar kendali modul ini).
- **Pemeriksaan PIN adalah credential lokal yang ringan**, terpisah dari login platform penuh —
  ia ada murni untuk penangkapan override dalam-transaksi (§3U) dan pemeriksaan PIN offline
  (§3S), tidak pernah sebagai sistem autentikasi paralel; user tanpa session platform aktif tidak
  bisa memakai override PIN untuk melewati login sepenuhnya.
- **Frontend**: shell POS khusus (touch-first, target besar, chrome minimal) terpisah dari chrome
  admin `AppLayout` untuk permukaan penjualan sungguhan (§3F/§3I/§3M-O) — split yang sama yang
  sudah didokumentasikan `MES_SPECS.md` §5 untuk layout Shop Floor-nya sendiri — sementara semua
  layar POS back-office (admin terminal/profile, rule promotion, report, dashboard) tetap disusun
  dari design system bersama (`DataTable`, `Panel`, `StatCard`, `StatusBadge`, `CLAUDE.md` §9D).
- **Tabel append-only**: `pos_cash_movements`, `pos_loyalty_ledger`, `pos_override_logs`, dan
  setiap tabel `*_events` tidak pernah di-update/dihapus di tempat, cocok dengan disiplin ledger
  platform-wide (`INVENTORY.stock_ledger`, `MES.mes_prod_events`, `SYSCONFIG.config_audit_logs`).
- **Surface REST API adalah pengecualian yang disengaja dan sempit** terhadap aturan "Inertia
  untuk web, REST hanya untuk klien non-Inertia sungguhan" `CLAUDE.md` §2 — dijustifikasi dengan
  cara yang sama seperti API mobile field-visit milik Legal (`LEGAL_SPECS.md` §3M/catatan "Kenapa
  REST"-nya sendiri): toleransi offline benar-benar bentuk klien yang berbeda, bukan preferensi
  gaya. `api/v1/pos/*`, bearer Sanctum + `X-Tenant-Id`, stack middleware yang sama yang sudah
  dibangun untuk Legal — tanpa mekanisme auth baru.
- **Async di mana volume menuntutnya**: posting journal session-close (§3J) dan pemrosesan
  antrean-sync (§3S) melalui Redis queue (`CLAUDE.md` §3), tidak pernah request sinkron yang
  ditunggu cashier, disiplin "jangan blokir jalur operator-facing" yang sama yang dinyatakan
  `MES_SPECS.md` §5 untuk ingestion IoT-nya sendiri.
- **Kode menu/permission**: `menu.perm:POS_*` (§3U) lewat middleware trustee SYSCONFIG.
- **Plan gating**: belum ditambahkan ke `config/tenant_modules.php` — lihat §7 Item Terbuka.

---

# 6. Urutan Pembangunan

> Urutan yang direkomendasikan untuk mengimplementasikan bagian-bagian modul ini sendiri, dan
> alasannya. Internal untuk POS — lihat `CLAUDE.md` §5 untuk urutan platform-wide (POS belum
> ditempatkan di sana, §7).

1. **POS Profile & capability matrix (§3A)** — tidak ada apa pun lagi yang bisa dirender secara
   kondisional tanpanya; seed dua profile default (Convenience Store, Restaurant) segera.
2. **Topologi Terminal / Branch / Register (§3B) dan POS Session (§3C/§3D)** — setiap transaksi
   butuh session terbuka pada terminal sungguhan untuk dilekatkan.
3. **Katalog Produk & Pencarian (§3E)**, menggunakan ulang master product/barcode/UoM milik
   Inventory yang sudah ada — tabel template weighted-barcode adalah satu-satunya schema baru di
   sini.
4. **Cart Engine (§3F)** dan **Pricing (§3G)** — dibangun terhadap engine Price List milik Sales
   yang sudah ada sebelum menyentuh Promotion (§3H adalah Fase 2 dan bergantung pada cart yang
   sudah bekerja).
5. **Core Arsitektur Offline (§3S)** — bangun ini *bersebelahan* dengan langkah 3–4, bukan
   sesudahnya. Menambal offline belakangan ke cart/katalog yang sudah dibangun online-saja persis
   pekerjaan-ulang yang ingin dihindari pembertahapan spec ini (§2); cache IndexedDB dan antrean
   sync perlu ada sebelum Payment/posting Inventory dikawat-kan, jadi keduanya bisa dibangun
   offline-first sejak awal.
6. **Payment Engine dan Identifikasi Customer (§3I)** — bergantung pada cart + core offline.
7. **Batas Posting AR/Revenue (§3J)** — bergantung pada Payment (perlu tahu apa yang sebenarnya
   dikumpulkan) dan Session (pemicu session-close). Bangun kedua jalur posting bersama, bukan
   satu sekarang dan yang lain "nanti" — jalur walk-in sendiri yang membuat kebutuhan jalur
   on-account jadi jelas dalam code review.
8. **Posting Inventory (§3K)** — bergantung pada §3J yang sudah ada secara konseptual (baris mana
   yang "sungguhan" penjualan vs. masih di-park) meskipun keduanya panggilan terpisah.
9. **Return/Refund (§3L)** — bergantung pada §3J/§3K keduanya sudah ada, karena sebuah return
   membalik keduanya.
10. **Adapter hardware (§3Q)** — receipt printer + cash drawer + scanner dulu (esensial Fase 1);
    kitchen printer/customer display/scale/card-terminal mendarat bersama fitur Fase 2/3-nya
    masing-masing.
11. **Keamanan & Permission (§3U)** — permission menu bisa dikawat-kan secara bertahap sejak
    langkah 1; tabel override-log dan alur PIN mendarat begitu ada aksi elevated sungguhan
    (discount, void) untuk di-gate, sekitar langkah 6–7.
12. **Report & Dashboard (§3U)** — read model murni, dirilis terakhir di Fase 1 begitu tabel
    transaksi yang mendasari punya data sungguhan.
13. **Fase 2**: Restoran (§3M/§3N/§3O) sebagai satu potongan yang terhubung — Floor/Table dulu
    (tidak ada apa pun lagi dalam potongan restoran yang bekerja tanpa table untuk dilekatkan
    order), lalu Modifier, lalu KDS. Promotion Engine (§3H) dan Loyalty/Gift Card (§3R) bisa
    dibangun paralel dengan potongan Restoran, karena tidak satu pun bergantung padanya.
14. **Konsumsi Recipe (§3P)** dirilis kapan pun kontrak `PpService::getActiveRecipe()`/
    `scaleRecipe()` milik PP sungguhan (`PP_SPECS.md` §7 melacak implementasi itu) — gate sebagai
    optional-module sampai saat itu, persis seperti caller lain yang bergantung-PP mana pun di
    platform ini.

---

# 7. Item Terbuka

- [x] **Penempatan `CLAUDE.md` §4/§5/§7A** — POS kini terdaftar di antara schema DB tenant (§4,
      §7A) dan urutan pembangunan platform (§5), ditempatkan segera setelah HCM — yang terakhir
      dari dependency-nya (Inventory, CRM, Sales, Accounting, WNE, DMS, HCM) dalam urutan
      pembangunan — dan jauh sebelum Payroll/Performance/PP/MES/AIInsight, tidak satu pun yang
      bergantung padanya.
- [x] **Entry `config/tenant_modules.php`** — ditambahkan ke `full` saja, postur bundle-placeholder
      yang sama seperti yang sudah dimiliki PP/MES. Bundle plan `retail`/`fnb` khusus yang lebih
      sempit (Inventory + CRM + Sales + Accounting + WNE + DMS + HCM + POS + Design System) tetap
      kandidat sungguhan untuk kapan prospek tenant retail/F&B sungguhan ada — tidak ditebak
      sekarang, postur "jangan tebak tier plan sebelum ada tenant sungguhan" yang sama yang sudah
      dicatat `MES_SPECS.md` §7 untuk plan manufaktur-nya sendiri.
- [ ] **Resolusi master Branch** — catatan §5: apakah POS memperkenalkan lookup `pos_branches`
      minimalnya sendiri atau konsep Branch tenant-wide mendarat di tempat lain lebih dulu, itu
      pertanyaan waktu urutan-pembangunan, bukan pertanyaan desain POS. Tinjau ulang saat waktu
      pembangunan.
- [ ] **Sumber identitas cashier** — §3C mengasumsikan `HCM.employees` ketika HCM terpasang;
      tenant yang menjalankan POS tanpa HCM (misalnya toko sangat kecil pada plan sempit) butuh
      fallback ke user platform `SYSCONFIG` biasa. Keduanya harus bekerja; mana yang jadi default
      butuh keputusan begitu bundle modul sungguhan plan `retail`/`fnb` (di atas) sudah
      difinalkan.
- [ ] **Rumah jangka-panjang Loyalty** — §3R mencatat POS memiliki Loyalty hari ini karena tidak
      ada modul Core lain yang mengklaimnya; kalau kebutuhan e-commerce/omnichannel masa depan
      (§2 Fase 3) butuh earning/redemption loyalty di luar transaksi POS fisik, mempromosikannya
      jadi concern-nya sendiri (atau ke CRM) adalah opsi sungguhan untuk ditinjau ulang saat itu,
      belum diputuskan sekarang.
- [ ] **Implementasi kontrak `PpService`** — §3P memanggil `PpService::getActiveRecipe()`/
      `scaleRecipe()`; sudah dilacak sekali di `PP_SPECS.md` §7 (sesuai konvensi yang sama yang
      dipakai `MES_SPECS.md` §7), tidak diduplikasi di sini.
- [ ] **Integrasi live weighing-scale dan payment-gateway** — secara eksplisit Fase 3 (§2); Fase
      1/2 dirilis dengan entry manual untuk keduanya, secara sengaja, bukan sebagai gap.
