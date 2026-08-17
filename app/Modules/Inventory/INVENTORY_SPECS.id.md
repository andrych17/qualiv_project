# Modul Inventory
## Sistem Manajemen Inventaris — Modul Bersama Core (dapat berdiri sendiri/standalone)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal yang bersentuhan dengan barang fisik — dan bahkan beberapa yang tidak (firma
Legal tetap melacak perlengkapan kantor, custody barang bukti, inventaris exhibit) — pada
akhirnya perlu menjawab tiga pertanyaan: *apa yang kita punya, di mana lokasinya, dan berapa
nilainya.* Jika dibiarkan tidak diselesaikan secara terpusat, ini mengulangi anti-pola yang
persis sama yang berusaha dicegah oleh WNE/DMS/CRM:

- Setiap vertikal menciptakan konsep "stok" sendiri — tidak ada notion bersama tentang produk,
  lokasi, atau pergerakan (movement), sehingga tidak ada yang bisa dibandingkan atau dilaporkan
  lintas platform.
- Tidak ada single source of truth untuk kuantitas on-hand — laporan menyimpang (drift),
  penjualan/pengeluaran ganda (double-selling/double-issuing) menjadi mungkin terjadi, dan
  rekonsiliasi dilakukan secara manual.
- Tidak ada metode costing yang konsisten — valuasi untuk kebutuhan accounting/pelaporan harus
  benar dan bisa diaudit (FIFO atau Weighted Average), bukan sekadar perkiraan mata.
- Tidak ada alur kerja barcode/scan yang dapat digunakan ulang — setiap modul yang bersentuhan
  dengan barang fisik jika tidak akan membangun UI scanning-nya sendiri.
- Tidak ada tempat umum untuk memicu notifikasi "low stock" / "cycle count due" — ini persis
  jenis event yang sudah ada mekanismenya di WNE untuk dirute, sehingga Inventory harus terhubung
  ke situ alih-alih membangun jalur notifikasi paralel.

**Kebutuhan klien:**
- Sadar multi-tenant, isolasi DB-per-tenant yang sama seperti setiap modul Core lain (tanpa
  kolom `tenant_id` — lihat `CLAUDE.md` §4/§7).
- Harus dapat berfungsi **sepenuhnya standalone** — seorang tenant bisa menjalankan Inventory
  tanpa modul lain apa pun terinstal (pelacakan produk + stok sederhana), karena ini dapat dijual
  sebagai item lini tersendiri, persis seperti DMS dan Schedule.
- Harus juga terintegrasi secara bersih ketika modul Core lain hadir: **CRM** (vendor/customer
  adalah Partners, bukan tabel kontak Inventory terpisah), **Purchase** (Goods Receipt yang
  ter-link ke PO memanggil `InventoryService::receive()` secara langsung untuk mem-post
  pergerakan stok fisik — §5), **Sales** (Delivery yang sudah dikirim memanggil
  `InventoryService::issue()` secara langsung untuk mem-post pergerakan stok fisik — §5), **DMS**
  (packing list, sertifikat QC, invoice supplier melekat via facade yang sudah ada), **WNE**
  (alert low-stock, pengingat cycle-count, workflow approval penerimaan), **Schedule** (janji
  temu dock, penjadwalan cycle-count).
- Perpetual inventory, bukan periodik — setiap perubahan kuantitas harus berupa movement yang
  tercatat dan immutable, sehingga saldo on-hand selalu bisa diturunkan/diaudit, bukan sekadar
  counter yang bisa diubah.
- Costing harus benar dan dapat ditukar per tenant (FIFO atau Weighted Average) karena ini
  memasok accounting/pelaporan, bukan sekadar kenyamanan operasional.
- Scanning barcode harus menjadi metode input kelas satu sejak hari pertama (receipt, issue,
  transfer, cycle count), karena kecepatan lantai gudang adalah nilai jual utama.
- Multi-warehouse, multi-location sejak hari pertama — bahkan tenant dengan satu warehouse
  diuntungkan oleh model bin/zone yang sama yang dibutuhkan tenant multi-site; tidak perlu
  rework nanti.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. **MVP-first** — kirim sesuatu yang bisa dijual dengan cepat; fitur
> Operational adalah fast-follow segera; Advanced/Optimization secara eksplisit ditunda.

## MVP — Core (bangun pertama, implementasi cepat)
- **Product Master** — SKU, deskripsi, kategori, UoM (+ konversi), metode costing default,
  barcode(s), reorder point (sederhana, tanpa forecasting).
- **Warehouse / Location Master** — warehouse, dan lokasi (bin) di dalamnya, cukup hierarkis
  untuk mendukung put-away nanti tanpa perubahan skema.
- **Goods Receipt** — menerima stok masuk (dari vendor, atau "opening balance" tanpa link),
  membuat entri stock ledger + layer valuasi.
- **Goods Issue** — mengeluarkan stok (ke customer, cost center, atau konsumsi tanpa link),
  mengonsumsi layer valuasi sesuai metode costing tenant.
- **Transfers** — memindahkan stok antar lokasi/warehouse, satu pasangan issue+receipt dalam
  satu transaksi sehingga tidak pernah tampak "hilang" di tengah transfer.
- **Adjustments** — mengoreksi kuantitas on-hand (varians count, kerusakan, write-off) dengan
  kode alasan wajib — tidak pernah edit kuantitas secara diam-diam.
- **Stock Card** — tampilan ledger yang immutable per produk/lokasi: saldo berjalan, setiap
  movement, dokumen referensi, selalu dapat direkonstruksi dari ledger, tidak pernah berupa
  field yang dapat diubah.
- **Inventory Valuation** — nilai on-hand berdasarkan produk/warehouse/kategori, menggunakan
  metode costing pilihan tenant.
- **FIFO / Weighted Average Cost** — dapat dipilih per tenant (atau per produk, dapat
  di-override), konsumsi cost-layer yang benar pada setiap issue.
- **Barcode support** — scan-to-receive, scan-to-issue, scan-to-count; barcode produk dengan
  opsi barcode alternate/case-pack.

## Operational (v1.1 — fast-follow segera, masih sebelum Advanced)
- **Batch / Lot tracking** — mengelompokkan stok berdasarkan batch/lot dengan expiry, untuk
  produk yang membutuhkannya (pharma-adjacent, food-adjacent, atau barang regulated lain apa pun
  yang mungkin dibawa vertikal masa depan).
- **Serial Number tracking** — identitas tingkat-unit untuk item bernilai tinggi/dilacak garansi.
- **Reservations** — soft-allocate stok terhadap order yang pending (Sales, atau vertikal
  mana pun) sehingga available-to-promise akurat tanpa benar-benar memindahkan apa pun dulu.
- **Picking** — menghasilkan pick list dari baris yang direservasi/dipesan, picking single-order
  sederhana (bukan wave/zone — lihat Advanced).
- **Packing** — mengonfirmasi item yang dipicking ke dalam paket shipment (carton/pallet),
  menangkap berat/dimensi untuk shipping.
- **Shipping** — hand-off ke carrier, penangkapan nomor tracking, status ships-confirmed yang
  memicu Goods Issue final.
- **Cycle Counting** — count parsial terjadwal (berdasarkan lokasi/kategori/kelas ABC) alih-alih
  physical inventory penuh, tinjauan varians → Adjustment.
- **Multi-warehouse** — sudah didukung secara struktural di MVP; tier ini menambahkan tampilan
  pelaporan/rollup lintas-warehouse dan kebijakan reorder tingkat-warehouse.
- **Put-away rules** — set aturan sederhana (berdasarkan kategori produk → zona/lokasi default)
  sehingga staf penerimaan tidak perlu memilih bin secara manual setiap kali.

## Advanced — **Future Version** (jangan dibangun sekarang)
- **Wave / Zone / Cluster Picking** — picking multi-order yang di-batch, dioptimalkan
  berdasarkan layout warehouse; membutuhkan volume order nyata untuk menjustifikasi
  kompleksitasnya.
- **FEFO** (First-Expired-First-Out) — strategi konsumsi alternatif yang dilapiskan di atas data
  expiry Batch/Lot yang sudah ditangkap di Operational; ditunda karena FIFO/Average sudah
  mencakup kebutuhan costing MVP dan FEFO adalah penyempurnaan strategi-picking, bukan keharusan
  costing.
- **Cross-docking** — receive-and-ship tanpa put-away; fitur optimasi-alur yang membutuhkan
  throughput nyata untuk menjustifikasinya.
- **Quality Management** — inspection hold, gating pass/fail QC sebelum stok tersedia; benar-benar
  bernilai tapi merupakan sub-domain yang berbeda (inspection plan, kode defect) — bangun begitu
  ada vertikal yang benar-benar membutuhkan penerimaan regulated.
- **Consignment** — stok milik vendor yang disimpan di lokasi tenant, owned-elsewhere-but-tracked-here;
  model kepemilikan yang berbeda dilapiskan di atas ledger yang sudah ada, ditunda sampai ada
  klien yang meminta.
- **Landed Cost** — mengalokasikan freight/duty/customs ke dalam cost produk setelah receipt;
  bernilai untuk tenant yang banyak impor, tapi layer valuasi MVP sudah mendukung entri "cost
  adjustment" aditif nanti tanpa perubahan skema.
- **Dock Scheduling** — slot janji temu untuk truk inbound/outbound; secara alami menggunakan
  ulang engine Resource/Availability milik modul **Schedule** alih-alih menciptakan ulang —
  bangun sebagai tipe Resource khusus-Inventory yang tipis + alur booking begitu volume dock
  nyata.

## Optimization — **Future Version** (lapisan AI/analitik, pasca-peluncuran)
- **AI Forecasting** — forecasting demand per produk/warehouse untuk menggerakkan saran reorder;
  cocok secara alami dengan pola **AIInsights Core** (di-scope per-tenant, cost-effective via
  prompt caching) begitu ada cukup data ledger historis untuk diforecast.
- **Slotting Optimization** — menyarankan penempatan bin optimal dari data frekuensi-pick.
- **Anomaly Detection** — menandai pola shrinkage/adjustment yang tidak biasa untuk ditinjau.
- **Predictive Replenishment** — otomatis menghasilkan saran PO dari forecast + kebijakan
  reorder.
- **Warehouse Performance Analytics** — dashboard cycle-time pick/pack/ship, tingkat akurasi.
- Semua fitur Optimization adalah lapisan baca/analisis **di atas** stock ledger MVP — tidak ada
  yang membutuhkan perubahan skema pada ledger itu sendiri, hanya tabel aditif (forecast, saran,
  flag anomaly) dan, untuk fitur AI, postur Claude API + ZDR yang sama yang sudah ditandai untuk
  AIInsights Core.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama (Ringkasan Inventory)

**Function / features**
- Ringkasan cepat kesehatan stok: total SKU, total nilai on-hand, jumlah low-stock, jumlah
  out-of-stock, receipt pending, shipment pending, cycle count terbuka.
- Antrean "needs attention": produk di bawah reorder point, batch yang akan segera expired
  (begitu Operational dirilis), varians count terbuka yang menunggu tinjauan.
- Aksi cepat: Goods Receipt baru, Goods Issue baru, Transfer baru, scan-to-count.

**Layout**
- Atas: kartu ringkasan (On-Hand Value, Low Stock, Out of Stock, Pending Receipts/Shipments).
- Utama: tabel data bertab (komponen bersama sesuai `DESIGN.md`) — "Low Stock" | "Recent
  Movements" | "Pending Documents" | "Open Counts".
- Setiap baris menggunakan **Status Rail** bersama: `danger` = out of stock / varians negatif,
  `warning` = di bawah reorder point / akan segera expired, `success` = stok sehat, `info` =
  movement yang dihasilkan sistem (misalnya auto put-away), netral = item in-stock biasa.

**Rules / logic**
- Semua query di-scope secara otomatis ke DB tenant (DB-per-tenant, tanpa filter di level
  aplikasi).
- "Low stock" membandingkan `stock_balances.qty_on_hand` saat ini terhadap `products.reorder_point`
  per warehouse (atau secara global jika tidak spesifik-warehouse).
- Item out-of-stock dan varians negatif muncul lebih dulu terlepas dari urutan sort.

## 3B. Product Master (Entry)

- Field: SKU (unik per tenant), nama, deskripsi, kategori (`product_categories`, tree yang bisa
  diedit tenant), UoM dasar, UoM tambahan + faktor konversi, metode costing (`fifo` / `average`,
  default tenant, dapat di-override per produk), reorder point, kuantitas reorder (sederhana,
  tanpa forecasting di MVP), barcode utama + barcode alternate (`product_barcodes`), is_active,
  tracking mode (`none` / `batch` / `serial` — Operational, ada di skema sejak hari pertama,
  ditegakkan begitu Operational dirilis), custom field via skema `CUSTOMFIELDS` yang sudah ada.
- List view: tabel data bersama, Status Rail mencerminkan kesehatan stok agregat saat ini untuk
  produk tersebut lintas warehouse.
- Detail view: tab — Overview, Stock by Location, Stock Card (3H), Barcodes, Custom Fields.

**Rules / logic**
- Keunikan SKU ditegakkan tenant-wide (DB-per-tenant berarti tidak ada concern collision
  lintas-tenant).
- Mengubah `costing_method` pada produk dengan layer valuasi yang sudah ada diblokir — perubahan
  metode costing membutuhkan penutupan layer yang sudah ada terlebih dulu (mencegah korupsi
  diam-diam pada valuasi historis); ditampilkan sebagai error yang jelas sesuai panduan suara
  `DESIGN.md`.
- Menonaktifkan produk memblokir receipt/issue baru tapi tidak pernah menyembunyikan entri
  ledger historis.

## 3C. Warehouse & Location Management (Entry)

- `warehouses`: nama, alamat, is_active.
- `locations`: `warehouse_id`, `parent_location_id` (self-referencing — mendukung hierarki
  zone → aisle → bin tanpa perubahan skema di masa depan), kode, tipe (`zone` / `bin` /
  `staging` / `dock` — lookup yang dapat diperluas, bukan enum hardcode), is_active.
- Tampilan tree CRUD, pola interaksi yang sama dengan Folder Management milik DMS (3D di
  `DMS_SPECS.md`).

**Rules / logic**
- Sebuah lokasi tidak dapat dihapus selama menyimpan stok on-hand (sesuai `stock_balances`) —
  reassignment/transfer diperlukan terlebih dulu, prinsip integritas yang sama dengan aturan
  penghapusan folder milik DMS.

## 3D. Goods Receipt (Entry / Engine)

- Header: warehouse, tanggal receipt, source (`subject_type`/`subject_id` — link polimorfik
  opsional ke PO Purchasing/vertikal, atau Partner vendor dari **CRM** untuk receipt langsung
  tanpa PO, atau kosong untuk "opening balance"), nomor referensi, status (`draft` → `posted`).
- Lines: produk, batch/lot (jika dilacak — Operational), kuantitas, UoM, unit cost, lokasi
  tujuan (default dari Put-away rules, 3P — Operational; manual di MVP jika rules belum
  disiapkan).
- **Saat post:** membuat satu entri `stock_ledger` per baris (tipe movement `receipt`, bertanda
  positif), membuat layer valuasi baru (`stock_valuation_layers`) pada unit cost yang diterima,
  memperbarui `stock_balances` (cache kuantitas-saat-ini yang didenormalisasi), memicu event
  `inventory.goods_received`.
- Mode scan barcode: scan barcode produk → auto-isi baris, scan barcode lokasi → set tujuan,
  kuantitas via scan-count atau input manual.

**Rules / logic**
- Posting adalah satu-satunya aksi yang menyentuh ledger — receipt `draft` dapat diedit bebas,
  receipt `posted` bersifat immutable (koreksi via Adjustment yang membalik (reversing), tidak
  pernah diedit), prinsip integritas-audit yang sama yang diterapkan DMS pada access log-nya.
- Saat dibuat via `InventoryService::receive(...)` dari Goods Receipt milik Purchase
  (`PURCHASE_SPECS.md` §3E), `subject_type = 'purchase.pur_receipt_hdrs'` dan unit cost default
  dari harga baris PO asal. Inventory tidak memvalidasi ulang matching PO/GR/Invoice sendiri —
  three-way match tetap sepenuhnya pekerjaan Purchase (`PURCHASE_SPECS.md` §3F); Inventory hanya
  butuh kuantitas dan cost yang valid untuk mem-post ledger-nya sendiri dengan benar.

## 3E. Goods Issue (Entry / Engine)

- Header: warehouse, tanggal issue, destination (`subject_type`/`subject_id` — link opsional ke
  order Sales/record vertikal, Partner customer dari CRM, atau kosong untuk konsumsi internal),
  reason (untuk issue tanpa link: consumption, sample, write-off-pending-adjustment-review),
  status (`draft` → `posted`).
- Lines: produk, batch/lot (jika berlaku — FIFO dalam batch dihormati), kuantitas, UoM, lokasi
  sumber.
- **Saat post:** membuat entri `stock_ledger` (tipe movement `issue`, bertanda negatif),
  mengonsumsi layer valuasi sesuai metode costing produk (oldest-layer-first untuk FIFO; rate
  blended yang dihitung ulang untuk Average), memperbarui `stock_balances`, memicu event
  `inventory.goods_issued`.
- Memblokir posting jika kuantitas yang diminta melebihi yang tersedia (on-hand dikurangi
  reserved) di lokasi tersebut — error jelas sesuai suara `DESIGN.md`: *"Hanya 12 unit SKU-1042
  tersedia di Warehouse A / Bin 03. Kurangi kuantitas atau pilih lokasi lain."*

**Rules / logic**
- Saat dibuat via `InventoryService::issue(...)` dari Delivery Engine milik Sales
  (`SALES_SPECS.md` §3H), `subject_type = 'sales.dlv_hdrs'` dan blokir over-issue yang sama di
  atas berlaku — sebuah delivery tidak dapat mengirim lebih dari yang benar-benar tersedia,
  ditampilkan kembali ke Sales sebagai transisi `shipped` yang ditolak alih-alih entri ledger
  yang parsial/salah. Inventory tidak memvalidasi legitimasi order, pricing, atau status
  customer — itu tetap sepenuhnya pekerjaan Sales; Inventory hanya butuh kuantitas dan lokasi
  yang valid untuk mem-post ledger-nya sendiri dengan benar, pembagian tanggung jawab yang sama
  yang sudah ditetapkan untuk Goods Receipt milik Purchase (`INVENTORY_SPECS.md` §3D).

## 3F. Transfers (Entry)

- Header: warehouse/location sumber, warehouse/location tujuan, tanggal transfer, status
  (`draft` → `in_transit` → `completed`, state tengah hanya bermakna untuk transfer
  lintas-warehouse dengan waktu transit nyata; transfer bin dalam-warehouse-yang-sama bisa
  langsung ke `completed`).
- Lines: produk, batch/lot, kuantitas, UoM.
- **Saat post:** satu transaksi menulis pasangan `stock_ledger` issue-di-sumber +
  receipt-di-tujuan — dimodelkan sebagai satu tipe movement `transfer` (bukan dua dokumen
  independen) sehingga stock card terbaca jelas sebagai "dipindahkan," bukan "menghilang lalu
  muncul kembali."
- Layer valuasi berpindah bersama stok (basis cost tidak berubah oleh transfer — hanya issue ke
  luar perusahaan yang mengonsumsi/merealisasikan cost).

## 3G. Adjustments (Entry)

- Header: warehouse/location, tanggal adjustment, kode alasan (`count_variance` / `damage` /
  `expiry` / `theft_loss` / `correction` / `other` — lookup yang dapat diedit tenant), referensi
  (misalnya Cycle Count yang ter-link, 3O).
- Lines: produk, batch/lot, kuantitas sistem (auto-isi dari saldo saat ini), kuantitas
  counted/aktual, varians (dihitung), basis unit cost untuk varians (menggunakan cost layer
  valuasi saat ini — adjustment positif membuat layer baru pada cost tersebut, adjustment
  negatif mengonsumsi layer sama seperti Issue).
- **Saat post:** entri `stock_ledger` (tipe movement `adjustment`, bertanda sesuai arah varians),
  memperbarui `stock_balances`, memicu event `inventory.stock_adjusted` — adjustment besar/negatif
  di atas ambang yang dapat dikonfigurasi secara opsional dapat dirutekan melalui workflow
  approval **WNE** (`workflow_code = inventory.adjustment_approval`) sebelum diposting, pola
  opt-in yang sama yang digunakan CRM untuk kualifikasi lead.

## 3H. Stock Card (View / Report)

- Per produk (× warehouse, atau × location, atau × batch/lot jika dilacak): daftar kronologis
  dan immutable dari setiap entri `stock_ledger` — tanggal, tipe movement, dokumen referensi,
  kuantitas in/out, saldo berjalan, unit cost, nilai berjalan.
- **Selalu dapat direkonstruksi dari `stock_ledger` saja** — `stock_balances` adalah cache untuk
  pembacaan cepat, bukan pernah source of truth; job rebuild dapat meregenerasi saldo dari
  ledger jika pernah menyimpang (jaring pengaman integritas).
- Filter: rentang tanggal, tipe movement, warehouse/location, batch/lot.
- Export ke CSV/PDF untuk keperluan audit (klien vertikal legal akan mengharapkan ini untuk
  discoverability, postur yang sama dengan audit trail milik DMS).

## 3I. Inventory Valuation Engine

- Laporan nilai on-hand: berdasarkan produk / kategori / warehouse, menggunakan metode costing
  aktif tiap produk, dijumlahkan dari `stock_valuation_layers` yang terbuka (belum dikonsumsi).
- Snapshot valuasi: nilai pada titik-waktu tertentu per tanggal tertentu (untuk pelaporan
  period-close), dihitung dengan mereplay ledger sampai tanggal tersebut — tidak perlu proses
  "closing" terpisah karena ledger adalah source of truth.

## 3J. FIFO / Weighted Average Cost (Engine)

- **FIFO**: `stock_valuation_layers` per receipt, `remaining_qty` dikurangi dalam urutan
  tanggal-receipt pada setiap issue; cost issue = cost berbobot dari layer yang benar-benar
  dikonsumsi (satu issue bisa mencakup beberapa layer jika menghabiskan satu layer).
- **Weighted Average**: tidak ada layer diskret yang dikonsumsi berurutan — melainkan
  `avg_cost` berjalan per produk/warehouse dihitung ulang pada setiap receipt
  (`new_avg = (old_qty*old_avg + received_qty*received_cost) / (old_qty+received_qty)`); issue
  cukup menggunakan `avg_cost` saat ini pada waktu posting.
- Kedua metode menulis ke tabel `stock_ledger`/`stock_valuation_layers` yang sama — metode hanya
  mengubah *bagaimana konsumsi dihitung* pada issue, bukan skemanya, sehingga mengganti default
  tenant (untuk produk baru ke depan) tidak membutuhkan migration.
- Diekspos sebagai `CostingService::costReceipt()`, `CostingService::costIssue()` — sebuah
  interface (`CostingStrategyInterface`) dengan implementasi `FifoStrategy`/`AverageStrategy`,
  sehingga strategi masa depan (misalnya Standard Cost) bersifat aditif, pola driver yang sama
  dengan `ChannelDriverInterface` milik WNE.

## 3K. Barcode Engine

- `product_barcodes`: `product_id`, nilai barcode (unik per tenant), tipe
  (`primary`/`case_pack`/`alternate`), pengali unit (misalnya barcode case-pack di-scan sebagai
  ×24 dari UoM dasar).
- Input scan diterima sebagai field teks biasa di mana pun lookup produk/lokasi terjadi (Receipt,
  Issue, Transfer, Cycle Count) — tanpa dependensi hardware khusus, bekerja dengan scanner HID
  USB/Bluetooth mana pun atau scan kamera mobile via frontend Vue yang sudah ada.
- Barcode lokasi menggunakan ulang pola tabel yang sama (`location_barcodes`) sehingga bin dapat
  dilabeli secara fisik dan di-scan selama put-away/picking.

## 3L. Batch / Lot Tracking — *Operational*

- `stock_batches`: product_id, nomor batch/lot, expiry_date (nullable), manufacture_date
  (nullable), referensi supplier.
- `stock_ledger` dan `stock_valuation_layers` membawa `batch_id` opsional — ketika
  `tracking_mode` produk adalah `batch`, setiap baris receipt/issue/transfer/adjustment
  membutuhkan satu.
- Batch yang akan segera expired muncul di Dashboard (3A) dengan Status Rail `warning`; batch
  yang sudah expired dengan peringatan yang memblokir pada Issue (dapat di-override dengan
  alasan, tercatat).

## 3M. Serial Number Tracking — *Operational*

- `stock_serials`: product_id, nomor serial (unik per tenant), status saat ini (`in_stock` /
  `reserved` / `issued`), lokasi saat ini.
- Untuk produk `tracking_mode = serial`, setiap unit adalah baris tersendiri — receipt membuat
  N baris serial untuk kuantitas N, issue harus menentukan serial mana yang dimaksud, tidak
  pernah hanya sebuah kuantitas.

## 3N. Reservations — *Operational*

- `stock_reservations`: product_id, batch/serial (jika berlaku), kuantitas, warehouse/location
  (atau unassigned-pending-pick), `subject_type`/`subject_id` (link polimorfik ke order yang
  memintanya), status (`active` / `fulfilled` / `released`), expiry (auto-release jika tidak
  fulfilled dalam jendela yang dapat dikonfigurasi).
- Available-to-promise = `stock_balances.qty_on_hand` − reservasi aktif pada produk/lokasi
  tersebut — diekspos sebagai `InventoryService::checkAvailability()`, pola "satu service yang
  dapat digunakan ulang dipanggil form lain" yang sama seperti `AvailabilityService` milik
  Schedule.

## 3O. Picking — *Operational*

- `pick_lists` / `pick_list_lines`: dihasilkan dari satu atau lebih reservasi, dikelompokkan per
  warehouse, ditugaskan ke picker, diurutkan berdasarkan lokasi untuk efisiensi jalan (sort
  lokasi sederhana di v1 — optimasi jalur sesungguhnya adalah Advanced/Wave picking).
- Alur scan-to-pick ramah mobile: scan barcode lokasi → scan barcode produk → konfirmasi
  kuantitas → baris ditandai picked.
- Menyelesaikan semua baris pada pick list memindahkannya ke status siap-`packing`.

## 3P. Packing & Shipping — *Operational*

- `pack_lists`: mengelompokkan item yang dipicking ke dalam paket (carton/pallet), menangkap
  berat/dimensi per paket.
- `shipments`: header yang menghubungkan satu atau lebih paket, carrier, nomor tracking,
  tanggal ship, status (`pending` → `shipped` → `delivered`, delivered diperbarui secara
  manual/webhook, tidak dilacak live di v1).
- **Ship-confirm** adalah yang memicu Goods Issue sesungguhnya (3E) — pengiriman secara fisik
  adalah event pengurang-inventory yang sesungguhnya, bukan langkah pick/pack sebelumnya, yang
  hanya memindahkan status stok, bukan entri ledger.

## 3Q. Cycle Counting — *Operational*

- `cycle_counts` / `cycle_count_lines`: count terjadwal (berdasarkan lokasi, kategori, atau
  kelas ABC — flag ABC manual sederhana pada Product di v1, tidak dihitung), penghitung yang
  ditugaskan, entri scan-to-count, kuantitas sistem vs kuantitas counted ditampilkan live.
- Menyelesaikan sebuah count dengan varians dirutekan ke Adjustment (3G) untuk
  tinjauan/persetujuan sebelum diposting — counting itu sendiri tidak pernah secara diam-diam
  mengubah stok.
- Dapat secara opsional dijadwalkan melalui modul **Schedule** (task cycle-count berulang per
  lokasi) begitu kedua modul diaktifkan untuk tenant — Inventory tidak mewajibkan Schedule,
  tapi berkomposisi dengannya jika hadir, postur standalone-tapi-composable yang sama seperti
  setiap modul Core lainnya.

## 3R. Put-away Rules — *Operational*

- `putaway_rules`: warehouse, kondisi (berdasarkan kategori produk, atau produk tertentu), zona/
  lokasi tujuan, urutan prioritas (rule yang cocok pertama menang).
- Diterapkan secara otomatis sebagai tujuan default pada baris Goods Receipt (3D); selalu dapat
  di-override manual saat receipt.

## 3S. Advanced & Optimization — **Future Version** (tidak dibangun sekarang, dicatat untuk kompatibilitas skema ke depan)

- **Wave/Zone/Cluster Picking**, **FEFO**, **Cross-docking**: dilapiskan di atas 3N–3R tanpa
  mengubah ledger — FEFO adalah varian urutan-konsumsi dari 3J yang di-scope terhadap data
  expiry batch yang sudah ditangkap di 3L; wave/zone/cluster picking adalah strategi
  pembuatan-`pick_lists` yang lebih pintar, bukan model data baru.
- **Quality Management**: menambahkan status inspection-hold ke `stock_ledger`/`stock_batches`
  (`quality_status`: pending/passed/failed) yang menggerbang ketersediaan — kolom placeholder
  yang dicadangkan di skema MVP (nullable, tidak digunakan sampai dibangun), disiplin "hanya
  migration aditif" yang sama yang diterapkan DMS pada `extracted_text`.
- **Consignment**: menambahkan `ownership_type` (owned/consignment) + referensi partner pemilik
  (`partner_id` CRM) ke `stock_valuation_layers` — tidak mengubah logika costing, hanya
  mengecualikan layer consignment dari laporan valuasi neraca milik tenant sendiri.
- **Landed Cost**: tipe entri ledger "cost adjustment" aditif yang mendistribusikan ulang
  freight/duty di seluruh layer valuasi receipt yang sudah ada, setelah faktanya.
- **Dock Scheduling**: sebuah `Resource` (pintu dock) khusus-Inventory yang tipis, didaftarkan
  terhadap engine Resource/Availability yang sudah ada milik modul **Schedule**
  (`SCHEDULE_SPECS.md` §3D/3E) — Inventory tidak boleh membangun logika booking/availability
  sendiri ketika Schedule sudah memilikinya.
- **AI Forecasting / Slotting / Anomaly Detection / Predictive Replenishment / Analytics**:
  lapisan analisis read-only di atas histori `stock_ledger`, ekstensi alami dari pola "ask your
  data" per-tenant milik **AIInsights Core** — persyaratan ZDR yang sama yang dicatat di sana
  juga berlaku di sini sebelum menawarkan fitur AI kepada tenant vertikal-legal (atau tenant
  mana pun) yang membayar.

---

# 4. Penyimpanan

**Database (skema `INVENTORY`, DB tenant):**

**Tabel master / lookup**
- `INVENTORY.products`
- `INVENTORY.product_categories`
- `INVENTORY.product_barcodes`
- `INVENTORY.uoms`, `INVENTORY.uom_conversions`
- `INVENTORY.warehouses`
- `INVENTORY.locations` (self-referencing `parent_location_id`)
- `INVENTORY.location_barcodes`
- `INVENTORY.adjustment_reasons`
- `INVENTORY.putaway_rules` *(Operational)*

**Tabel transaksi / ledger**
- `INVENTORY.stock_ledger` — append-only, immutable, single source of truth tunggal untuk setiap
  perubahan kuantitas (`receipt` / `issue` / `transfer` / `adjustment`), mereferensikan dokumen
  asal.
- `INVENTORY.stock_valuation_layers` — cost layer (FIFO) / snapshot rata-rata berjalan,
  dikonsumsi sesuai entri tipe-issue `INVENTORY.stock_ledger`.
- `INVENTORY.stock_balances` — cache on-hand saat ini yang didenormalisasi per
  product × warehouse × location (× batch/serial jika dilacak); dapat dibangun ulang dari
  `stock_ledger`.
- `INVENTORY.goods_receipts` / `INVENTORY.goods_receipt_lines`
- `INVENTORY.goods_issues` / `INVENTORY.goods_issue_lines`
- `INVENTORY.transfers` / `INVENTORY.transfer_lines`
- `INVENTORY.adjustments` / `INVENTORY.adjustment_lines`
- `INVENTORY.stock_batches` *(Operational)*
- `INVENTORY.stock_serials` *(Operational)*
- `INVENTORY.stock_reservations` *(Operational)*
- `INVENTORY.pick_lists` / `INVENTORY.pick_list_lines` *(Operational)*
- `INVENTORY.pack_lists` *(Operational)*
- `INVENTORY.shipments` *(Operational)*
- `INVENTORY.cycle_counts` / `INVENTORY.cycle_count_lines` *(Operational)*
- Metadata custom pada `products` (dan entitas mana pun yang membutuhkannya) menumpang pada
  skema `CUSTOMFIELDS` yang sudah ada, sama seperti setiap modul Core lainnya.

**Object File (sesuai `CLAUDE.md` §7B, mencerminkan struktur per-tenant yang sudah ada):**
```text
tenant_001/INVENTORY/
├── receipts/{receipt_id}/        # supplier invoice, packing list scans
├── shipments/{shipment_id}/      # BOL, carrier labels
└── counts/{count_id}/            # count sheets, photos of variance
```
- Penyimpanan file yang sesungguhnya ditangani oleh **DMS**, tidak diduplikasi di sini —
  dokumen Inventory melekat via `DocumentService::attach()` dengan
  `subject_type = 'inventory.goods_receipts'` dll, sama seperti integrasi DMS setiap modul
  lainnya. Struktur folder ini hanya ada sebagai partisi `owning_module` yang sudah dicadangkan
  DMS.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul modular monolith di `app/Modules/Inventory/`, bentuk yang sama
seperti setiap modul Core lainnya. Tidak ada ekstraksi microservice untuk MVP/Operational — ini
adalah CRUD transaksional + aritmetika (costing), bukan workload runtime-berbeda atau
async-berat sesuai kriteria ekstraksi `CLAUDE.md` §2. Satu-satunya bagian yang mungkin akhirnya
menjustifikasi ekstraksi, sesuai aturan yang sama, adalah **AI Forecasting/Anomaly Detection**
(tier Optimization — alasan yang sama yang sudah diterapkan pada OCR milik DMS dan AIInsights) —
tidak sebelum itu.

- **Facade internal** — `InventoryService::receive()`, `::issue()`, `::transfer()`,
  `::adjust()`, `::checkAvailability()`, `::reserve()` — titik integrasi pilihan untuk modul
  lain, terutama Delivery Engine milik **Sales** (mengeluarkan stok saat ship-confirm, §5 di
  atas), Goods Receipt milik **Purchase** (menerima stok, §5 di atas), dan modul vertikal mana
  pun (misalnya Legal melacak item barang bukti).
- **Event bus internal** — `inventory.goods_received`, `inventory.goods_issued`,
  `inventory.stock_adjusted`, `inventory.low_stock`, `inventory.count_variance_found` —
  memungkinkan **WNE** merutekan alert low-stock / workflow approval tanpa Inventory perlu tahu
  apa pun tentang pengiriman notifikasi, seam yang sama seperti setiap modul lainnya.
- **Cross-schema FK, bukan duplikasi.** `goods_receipts`/`goods_issues` mereferensikan
  `CRM.partners` secara langsung (Vertical/Core → Core adalah arah yang diizinkan, dan CRM itu
  sendiri adalah Core) untuk vendor/customer — Inventory **tidak** membangun tabel kontaknya
  sendiri, sesuai prinsip "registry Partner terpadu" yang dibangun CRM untuk ditetapkan.
- **Penggunaan ulang lintas-modul WNE** untuk semua alerting (low stock, batch akan expired,
  varians count, approval adjustment) — tidak ada kode notifikasi paralel, aturan yang sama yang
  diikuti DMS dan Schedule.
- **Penggunaan ulang lintas-modul Schedule** untuk Dock Scheduling (Future) dan Cycle Count
  terjadwal opsional (Operational) — Inventory tidak membangun engine resource/availability-nya
  sendiri.
- **Penggunaan ulang lintas-modul DMS** untuk semua lampiran dokumen (receipt, BOL, sertifikat
  QC) — tidak ada kode penyimpanan-file paralel.
- **Penggunaan ulang lintas-modul menuju Purchase (soft dependency, hanya penerimaan).**
  Goods Receipt milik Purchase (`PURCHASE_SPECS.md` §3E) memanggil `InventoryService::receive()`
  ketika Inventory diaktifkan untuk tenant, untuk mem-post movement stock-ledger dan layer
  valuasi yang sesungguhnya; `pur_receipt_hdrs`/`pur_receipt_lines` milik Purchase sendiri tetap
  menjadi record procurement otoritatif (digunakan untuk three-way match, `PURCHASE_SPECS.md`
  §3F) terlepas dari apakah Inventory terinstal. Inventory sendiri tidak memiliki dependensi
  compile-time apa pun terhadap Purchase — ia menerima receipt dari caller mana pun (Partner
  vendor via CRM, PO Purchase, atau "opening balance" kosong) melalui entry point
  `InventoryService::receive()` yang sama.
- **Penggunaan ulang lintas-modul menuju Sales (soft dependency, hanya fulfillment).** Delivery
  Engine milik Sales (`SALES_SPECS.md` §3H) memanggil `InventoryService::issue()` ketika
  Inventory diaktifkan untuk tenant, untuk mem-post movement stock-ledger dan layer valuasi yang
  sesungguhnya saat ship-confirm; `dlv_hdrs`/`dlv_lines` milik Sales sendiri tetap menjadi record
  order-fulfillment otoritatif (`so_lines.qty_delivered` diturunkan darinya) terlepas dari apakah
  Inventory terinstal. Inventory sendiri tidak memiliki dependensi compile-time apa pun terhadap
  Sales — ia menerima issue dari caller mana pun (Partner customer via CRM, delivery Sales, atau
  alasan konsumsi-internal tanpa link) melalui entry point `InventoryService::issue()` yang sama.
- **Penggunaan ulang lintas-modul menuju Accounting (hanya posting GL — bukan dependensi
  costing).** Inventory mempublikasikan `inventory.goods_received`, `inventory.goods_issued`,
  `inventory.stock_adjusted` — **Accounting** (`ACCOUNTING_SPECS.md` §3H) berlangganan semata
  untuk mem-post journal GL yang sesuai (Inventory-asset ↔ COGS/GRNI/Adjustment), menggunakan
  unit cost/value yang sudah dihitung Inventory. Inventory tetap menjadi satu-satunya source of
  truth baik untuk kuantitas (`stock_ledger`/`stock_balances`) maupun valuasi
  (`stock_valuation_layers`/`CostingStrategyInterface`) — Accounting tidak pernah menghitung
  ulang atau menyimpan angka pesaing. Jika Accounting tidak terinstal/diaktifkan untuk sebuah
  tenant, Inventory berfungsi sepenuhnya secara mandiri (tidak ada journal GL yang diposting,
  tidak ada yang lain berubah) — postur consumer-hilir-opsional yang sama yang digunakan setiap
  modul lain terhadap Accounting.

**Integritas ledger-first (keputusan desain inti):** `stock_ledger` bersifat append-only dan
merupakan satu-satunya source of truth untuk kuantitas; `stock_balances` adalah cache, dapat
dibangun ulang kapan saja dengan mereplay ledger. Ini mencerminkan filosofi audit-log milik DMS
(`access_logs` bersifat append-only, immutable) yang diterapkan pada konteks
finansial/kuantitas alih-alih konteks jejak-akses — satu pola yang konsisten di seluruh platform
alih-alih dua cara berpikir berbeda tentang "history yang tidak boleh pernah diedit."

**Pola strategi costing:** `CostingStrategyInterface` dengan implementasi `FifoStrategy` /
`AverageStrategy`, dipilih per default tenant (dapat di-override per produk) — strategi baru
(Standard Cost, disesuaikan-Landed-Cost) adalah class aditif, tanpa perubahan engine inti, pola
driver yang sama yang sudah ditetapkan `ChannelDriverInterface` milik WNE,
`ConferenceDriverInterface` milik Schedule, dan `OcrDriverInterface` milik DMS. Menjaga
konsistensi ini lintas modul adalah kesengajaan — inilah yang memungkinkan satu solo dev
(ditambah Claude Code) memahami seluruh codebase tanpa harus mempelajari ulang pola ekstensi
baru per modul.

**Batas cakupan MVP (jelas tentang apa yang ditunda):**
- Kolom placeholder `products.tracking_mode`, `quality_status`, dan `ownership_type` ada secara
  struktural di tempat yang murah untuk dicadangkan, tapi tidak digunakan/nullable sampai
  Batch/Serial (Operational) dan Quality/Consignment (Advanced) benar-benar dibangun —
  hanya migration aditif, tidak ada perubahan breaking nanti, disiplin yang sama yang
  diterapkan DMS pada `extracted_text`/`pgvector`.
- Tidak ada optimasi wave/zone picking, tidak ada forecasting, tidak ada dock scheduling di
  MVP — semuanya melapis dengan bersih di atas primitif ledger + reservation/pick begitu
  dibangun.

**Agnostik format barcode:** tidak ada asumsi tentang simbologi barcode (UPC/EAN/Code128/QR) —
tabel `product_barcodes`/`location_barcodes` hanya menyimpan nilai string yang sudah didecode;
hardware/kamera scanning mendecode ke teks sebelum pernah mencapai lapisan aplikasi, sehingga
tidak ada vendor lock-in.

**Queues:** Posting Receipt/Issue/Transfer/Adjustment bersifat sinkron (cepat, menghadap
pengguna, dan kritis-terhadap-correctness — costing harus dihitung dalam transaksi yang sama
dengan penulisan ledger untuk menghindari race condition pada posting konkuren terhadap produk
yang sama). Hanya leg "publish event `inventory.*` → WNE mengambilnya" yang bersifat async,
menggunakan ulang queue `notifications` yang sudah ada milik WNE — tidak perlu queue baru untuk
v1.

**Catatan konkurensi:** kalkulasi costing (terutama konsumsi layer FIFO dan penghitungan ulang
Weighted Average) harus menggunakan row-level locking (`SELECT ... FOR UPDATE`) pada baris
`stock_balances`/`stock_valuation_layers` yang relevan selama posting, untuk mencegah dua issue
simultan mengonsumsi layer yang sama secara ganda — ditandai secara eksplisit karena ini adalah
satu-satunya tempat di modul ini di mana bug konkurensi yang halus bisa secara diam-diam
mengorupsi valuasi.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B/3C (master produk + lokasi) →
3D/3E (receipt + issue, minimum untuk memiliki ledger yang berfungsi) → 3H (stock card — murah
begitu ledger ada, bernilai tinggi untuk memverifikasi correctness) → 3I/3J (engine valuasi +
costing) → 3F/3G (transfer + adjustment) → 3J (input barcode dikawatkan ke dalam form-form di
atas) — **MVP selesai di sini** — lalu 3L/3M (batch/serial) → 3N/3O (reservation + picking) →
3P (packing/shipping) → 3Q (cycle counting) → 3R (put-away rules) untuk Operational — lalu
tinjau kembali 3S (Advanced/Optimization) hanya begitu volume penggunaan nyata
menjustifikasinya.

**Catatan kelayakan jual (marketability)**
- Inventory berbasis ledger yang perpetual (dibanding field kuantitas mutable yang naif) adalah
  titik jual correctness/trust yang genuine untuk pembeli mana pun yang regulated atau sadar
  audit — layak ditampilkan secara eksplisit, cara yang sama audit trail milik DMS menjadi
  titik jual vertikal-Legal.
- Inventory yang dapat dijual mandiri (tanpa membutuhkan modul Sales/Purchasing) membuka gerakan
  go-to-market kedua di luar vertikal Legal — warehouse/retailer kecil bisa dijual Inventory +
  DMS + Schedule sebagai bundle tanpa pernah menyentuh modul Legal, yang merupakan cara
  berbiaya-rendah untuk memvalidasi arah vertikal kedua sesuai `CLAUDE.md` §5.
- Dukungan barcode sejak hari pertama adalah ekspektasi dasar untuk pembeli warehouse mana pun —
  mengirim MVP tanpa itu akan merugikan penjualan; diprioritaskan dengan benar sebagai Core,
  bukan Operational.
- FIFO/Average yang dapat dipilih per tenant (bukan hardcode) menghindari kehilangan deal karena
  mismatch metode akuntansi — keputusan modeling yang murah sekarang yang mencegah penghambat
  penjualan nyata nanti.
