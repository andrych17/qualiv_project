# Modul MES
## Modul Core Bersama — Manufacturing Execution System (Discrete/Assembly + Continuous/Process, satu engine)

**Kategori modul** (`CLAUDE.md` §2/§10): **Core**. MES tidak punya pengetahuan apa pun tentang
vertikal mana pun — ia adalah engine eksekusi bersama yang disewa tenant mana pun apa pun yang
mereka buat. Modul ini bukan Platform-level (hidup di DB tenant seperti modul Core lainnya, bukan
di DB pusat `nusaevo`) dan tidak ada kriteria ekstraksi-microservice `CLAUDE.md` §2 yang terpenuhi
hari ini (tidak ada kebutuhan scaling yang berbeda secara fundamental, tidak ada kebutuhan runtime
non-PHP, tidak ada reuse lintas-produk di luar monolith ini, tidak ada data yang butuh isolasi
tinggi). Modul ini kini terdaftar di `CLAUDE.md` §4/§5/§7A (schema `MES`, diurutkan sesudah
Performance dalam urutan pembangunan Core — lihat di sana untuk alasannya) dan dibuka pada plan
`full` di `config/tenant_modules.php`; **belum ada di `starter`, `legal`, atau `internal`**, karena
tidak satu pun dari tenant tersebut membuat sesuatu — lihat §7 Item Terbuka untuk apa yang masih
butuh keputusan nyata (plan/vertikal manufaktur khusus) alih-alih placeholder "plan segalanya" yang
dimilikinya hari ini.

---

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Tenant manufaktur (vertikal masa depan, atau add-on plan untuk tenant mana pun yang membuat barang
fisik) perlu tahu **apa yang sebenarnya terjadi di lantai produksi**, bukan cuma apa yang
direncanakan ERP:

- Production order ERP mendeskripsikan *niat* (qty rencana, tanggal rencana). Tanpa lapisan
  eksekusi, tidak ada catatan waktu mulai/selesai aktual, material yang benar-benar terpakai,
  output aktual, scrap, atau siapa yang mengerjakan apa — sehingga varians biaya, keterlambatan
  pengiriman, dan lolosnya masalah kualitas baru diketahui belakangan, kalau pun diketahui.
- Manufaktur discrete (assembly) dan continuous (process) secara struktural terlihat berbeda —
  operation/work-center/serial number vs. batch/recipe/process-parameter/lot — yang menggoda untuk
  membangun dua modul yang tidak berhubungan. Itu menduplikasi setiap concern bersama (konsumsi
  material, kualitas, traceability, equipment, scheduling, dashboard) dua kali dan menggandakan
  beban maintenance untuk developer solo.
- Genealogy lot/serial material (lot mentah mana yang masuk ke lot/serial jadi mana) biasanya baru
  ditambal belakangan, kalau pun ditambahkan — padahal itu perbedaan antara recall yang tertarget
  dan recall menyeluruh, dan semakin menjadi kebutuhan regulasi (makanan, farmasi, otomotif).
- Produk MES standalone mahal, butuh integrasi terpisah ke inventory/kualitas/HR ERP, dan tidak
  cocok dengan model SaaS terisolasi-tenant, ter-gate-plan yang sudah dimiliki platform ini untuk
  setiap modul lainnya.

**Kebutuhan klien:**
- **Satu execution engine, dua model produksi** — `assembly` (discrete) dan `process`
  (continuous) berbagi Production Order, event ledger, konsumsi material, output, scrap, kualitas,
  traceability, equipment, dan konsep dashboard yang sama; hanya unit kerja yang berbeda
  (Operation vs. Phase) sesuai arsitektur §3.
- **Production event ledger**: setiap aksi yang signifikan bagi eksekusi (start, pause, complete,
  material diterbitkan, output diproduksi, parameter dicatat, downtime, scrap, batch split/merge)
  adalah event immutable berstempel-waktu — tulang punggung untuk traceability, OEE, dan audit.
- **UI operator shop-floor**, bukan layar ERP yang didaur ulang — target sentuh besar, order/batch
  saat ini, start/pause/complete, status material dan parameter yang live.
- **Traceability / genealogy material** — maju dan mundur, lot-ke-lot dan serial-ke-serial,
  dibangun di atas identitas lot/serial milik Inventory sendiri, bukan salinan keduanya.
- **Gerbang kualitas tertanam dalam eksekusi**, bukan aplikasi terpisah yang ditempel — inspeksi
  incoming, in-process, dan finished-goods bisa menahan material/output agar tidak lanjut.
- **MES tidak memiliki stock atau identitas karyawan** — Inventory tetap menjadi system of record
  untuk stock (MES memanggil `InventoryService`), HCM tetap menjadi system of record untuk orang
  (MES mereferensikan `HCM.employees`/`HCM.shifts` secara read-only), disiplin "jangan duplikasi
  modul Core yang sudah ada" yang sama yang sudah diterapkan di Sales/Purchase/Payroll.

---

# 2. Tujuan (Goals)

> Fitur yang ditetapkan untuk menyelesaikan Latar Belakang di atas, dibertahap untuk developer
> solo (`CLAUDE.md` §10 bias MVP — gaya bertahap yang sama seperti `INVENTORY_SPECS.md` §2).

## Fase 1 — Core (dibangun lebih dulu)
- Production Order (§3A) mencakup kedua model `assembly` dan `process`.
- Master data Routing/Operations (discrete) dan Phases/Process-Parameters (process) (§3E, §3G) —
  komposisi material (BOM/Recipe) dimiliki **PP**, bukan MES; lihat catatan batas di §3B.
- Master data Work Center / Machine / Station (§3D).
- Eksekusi Shop Floor: eksekusi Operation (assembly) dan eksekusi Batch/Phase (process)
  (§3H, §3I).
- Production event ledger (§3C) — setiap aksi eksekusi dicatat.
- Konsumsi material (issue/return, memanggil `InventoryService`) dan Production output
  (finished/co-product/by-product/waste, memanggil `InventoryService`) (§3J).
- Scrap & rework (§3N).
- Traceability & genealogy lot/serial, dibangun di atas `stock_batches`/`stock_serials` milik
  Inventory (§3K).
- Quality dasar: inspeksi di checkpoint in-process dan finished-goods, pass/fail, hold/release
  (§3L).

## Fase 2 — Operational (susulan cepat)
- Referensi shift (menggunakan ulang `HCM.shifts`/`HCM.shift_assignments`, tanpa model shift baru)
  dan catatan shift handover (§3P).
- Scheduling / sequencing MES (machine, material, operator, changeover, campaign production)
  (§3Q).
- Pelacakan status equipment & downtime (planned/unplanned, ber-kode-alasan) (§3M).
- OEE (Availability × Performance × Quality) untuk lini assembly; KPI khusus-process (yield,
  % parameter-in-spec) untuk lini continuous (§3O).
- Electronic Work Instructions dilampirkan per Operation/Phase, lewat DMS (§3E/§3G).
- Tampilan detail batch genealogy, workflow hold/release kualitas lewat WNE (§3K, §3L).

## Fase 3 — Advanced (versi masa depan — jangan dibangun sekarang)
- Lapisan integrasi IoT/PLC/SCADA (ingest OPC-UA/MQTT/Modbus → production event /
  pembacaan parameter time-series) (§3S).
- Streaming process-parameter real-time dan alarm.
- Papan status visual Andon (§3R).
- Scheduling/optimisasi finite-capacity tingkat lanjut.
- Integrasi predictive-maintenance (di luar hook downtime → maintenance-request yang reaktif di
  §3M).
- Analytics tingkat lanjut (komposisi AIInsight, gerbang ZDR yang sama seperti fitur AI lainnya —
  `CLAUDE.md` §5).
- Tanda tangan elektronik pada entri audit-trail (tier industri teregulasi).

---

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Production Order (`prod_order_hdrs`)

**Fungsi / Fitur**
- Satu tipe header order untuk kedua model; `production_model` (`assembly` | `process`)
  menentukan child engine mana (§3H vs §3I) yang mengeksekusinya dan referensi master-data mana
  yang dibutuhkan: `bom_id` + `routing_id` (discrete) atau `recipe_id` (process). `bom_id`/
  `recipe_id` adalah referensi lintas-schema ke `pp_boms`/`pp_recipes` milik **PP** — komposisi
  material adalah master data milik PP, bukan MES (lihat catatan batas di §3B di bawah);
  `routing_id` (operation discrete) dan baris process-phase yang dikunci pada `recipe_id` yang
  sama (§3F) tetap milik MES.
- Field: product, referensi `bom_id`/`routing_id` atau `recipe_id`, quantity, UoM, production
  model, planned start/end, actual start/end, priority, warehouse, production line/area, status,
  parent order (untuk sub-assembly/intermediate batch), referensi source (`subject_type`/
  `subject_id` — tautan polimorfik kembali ke Sales order atau saran MRP yang menghasilkannya,
  pola yang sama seperti `stock_reservations.subject_type` milik Inventory).
- Nomor order dibuat lewat `SYSCONFIG.config_snums` (`snum_code = MES_MO_LASTID`) — nomor
  berjalan tenant-wide yang sederhana, cocok dengan rung-2 tangga kustomisasi sesuai
  `SYSCONFIG_SPECS.md` §3D (berbeda dari counter isu per-parent milik Projects, yang tetap lokal —
  catatan `PROJECTS_SPECS.md` §4).
- Siklus hidup status: `draft` → `released` → `in_progress` → (`paused`) → `completed` /
  `cancelled`. `Released` adalah event yang mengizinkan reservasi/issue material mulai berjalan.

**Aturan / Logika**
- `production_model` tidak dapat diubah setelah dibuat (mengubah execution model di tengah order
  tidak masuk akal — batalkan dan buat ulang saja).
- Merilis order memicu `MES.prod_events` (`order_released`) dan, jika Inventory aktif, membuat
  reservasi lewat `InventoryService::checkAvailability()`/reserve, sikap modul-opsional yang sama
  yang sudah dipakai Purchase untuk Goods Receipt (`PURCHASE_SPECS.md` §3E/§5).

## 3B. BOM / Recipe — Dimiliki PP (catatan batas)

**Fungsi / Fitur**
- BOM discrete (`pp_boms`/`pp_bom_lines`) dan Process Recipe/Formula (`pp_recipes`/
  `pp_recipe_ingredients`), termasuk formula scaling (`RecipeService::scale()`), dimiliki **PP**
  — `PP_SPECS.md` §3D — bukan MES. Komposisi material adalah master data perencanaan yang
  di-explode langsung oleh engine MRP; MES hanya *mengonsumsi* daftar produk/quantity yang sudah
  di-resolve saat pembuatan order dan batch, tidak menyimpan atau mengeditnya.
- MES membaca BOM/Recipe aktif lewat `PpService::getActiveBom(productId)` /
  `PpService::getActiveRecipe(productId)`, dan quantity batch yang sudah di-scale lewat
  `PpService::scaleRecipe(recipeId, targetBatchSize)` (membungkus `RecipeService::scale()` milik
  PP sendiri) — kebalikan dari PP memanggil `MesService` untuk equipment/routing (§3E/§3F).
- Yang tetap di MES: Routing/Operations (§3E, discrete — *urutan langkah eksekusi*, bukan
  komposisi material) dan Process Phases/Parameters (§3F, process — peran yang sama untuk
  manufaktur continuous), keduanya dikunci pada `product_id`/`recipe_id` alih-alih menduplikasi
  apa yang sudah dimiliki PP.

**Aturan / Logika**
- `bom_id`/`recipe_id` milik satu Production Order (§3A) di-resolve sekali saat waktu
  pembuatan/rilis dan disimpan pada order — disiplin "nilai yang sudah di-resolve bertahan
  meskipun master data diedit belakangan" yang sama yang dulu dipegang bagian ini ketika MES
  memiliki tabelnya, kini ditegakkan oleh versioning milik PP sendiri (`PP_SPECS.md` §3D: hanya
  satu versi aktif per product; order yang masih terbuka tetap memakai versi yang berlaku saat
  dirilis).

## 3C. Production Event Ledger (`mes_prod_events`)

**Fungsi / Fitur**
- Log append-only untuk setiap aksi yang signifikan bagi eksekusi: `order_released`,
  `material_issued`, `material_returned`, `operation_started`, `operation_paused`,
  `operation_completed`, `machine_started`, `machine_stopped`, `parameter_recorded`,
  `qc_sample_taken`, `scrap_recorded`, `output_produced`, `downtime_started`, `downtime_ended`,
  `batch_split`, `batch_merged`.
- Satu baris per event: referensi order/batch/operation, event type, `payload` (jsonb — detail
  spesifik event), `occurred_at`, `user_id`, `machine_id` (nullable).

**Aturan / Logika**
- Setiap write path di §3H/§3I/§3J/§3L/§3M menulis ke sini — tabel ini adalah satu-satunya sumber
  yang dipakai engine lain (OEE §3O, Traceability §3K, Dashboard §3T); tidak ada yang menghitung
  ulang histori secara independen.
- Immutable: koreksi adalah event baru (misalnya `scrap_recorded` korektif dengan delta negatif
  dan alasan), tidak pernah `UPDATE`/`DELETE` pada baris lama — disiplin append-only yang sama
  seperti `INVENTORY.stock_ledger`.

## 3D. Master Data Equipment (`mes_work_centers` / `mes_machines` / `mes_stations`)

**Fungsi / Fitur**
- Hierarki: Plant (tenant) → Area/Line → Work Center → Machine → Station, sesuai arsitektur user
  di §9 brief sumber spesifikasi ini.
- `mes_work_centers`: code, name, area/line, type.
- `mes_machines`: work_center_id, code, name, status saat ini (`running` / `idle` / `down` /
  `maintenance` / `setup` / `waiting_material` / `waiting_operator` / `waiting_qc`).
- `mes_stations`: work_center_id atau machine_id, code, name — titik fisik tempat operator
  bekerja (target UI Shop Floor §3H).

## 3E. Routing / Operations (assembly)

**Fungsi / Fitur**
- `mes_routings` (product, version, `is_active`) / `mes_routing_ops` (routing_id, sequence,
  operation code/name, work_center_id, setup_time, run_time, queue_time, standard output qty,
  referensi scrap rule, teks instructions, referensi tool/document).
- Electronic Work Instructions (Fase 2): melampir lewat `DocumentService::attach()` dengan
  `subject_type = 'mes.routing_ops'`, pola integrasi DMS yang sama dipakai modul lain
  (`DMS_SPECS.md`) — text/image/PDF/video/diagram, tanpa tabel file-storage terpisah di MES.

## 3F. Process Phases & Parameters (process)

**Fungsi / Fitur**
- `mes_process_phases` (`recipe_id` — referensi lintas-schema ke `pp_recipes.id` milik **PP**,
  §3B — sequence, phase name, tipe equipment/work_center_id, standard duration). Ini adalah
  padanan Routing (§3E) untuk manufaktur continuous: urutan *langkah eksekusi*, bukan daftar
  ingredient, itulah sebabnya tetap di MES meski recipe header yang digantunginya kini berada di
  PP.
- `mes_process_parameters` (`process_phase_id`, parameter code, target, min, max, UoM) —
  definisi spec/limit; pembacaan aktual hidup di `mes_batch_parameter_readings` (§3I), split
  target-vs-actual yang sama seperti `mes_routing_ops` (rencana) vs `mes_prod_events` (aktual).

## 3G. Eksekusi Assembly — UI Operation Shop Floor (`Show.vue`, layout Shop-Floor)

**Layout** (sesuai mockup user di brief sumber spesifikasi ini)
```text
WO-00125
Station: Assembly-03
Product: XYZ
Target : 100
Done   : 74
Reject : 2
[ START ]  [ PAUSE ]  [ COMPLETE ]  [ SCRAP ]
Materials: ✓ Component A  ✓ Component B  ⚠ Component C — Low Stock
```
**Aturan / Logika**
- UI shop-floor khusus, bukan chrome admin `AppLayout` standar — target sentuh besar, navigasi
  minimal, fokus pada satu order/operation.
- `START` menulis `operation_started`; `PAUSE`/`COMPLETE` menulis event yang sesuai; `COMPLETE`
  menambah qty produced dan, jika operation dikonfigurasi untuk auto-issue component 1:1 dengan
  penggunaan BOM standar, memanggil Material Consumption (§3J).
- `SCRAP` membuka alur Scrap & Rework (§3N) terlingkup pada operation saat ini.
- Strip ketersediaan component me-resolve `bom_id` milik order lewat `PpService::getActiveBom()`
  (§3B), lalu membaca `InventoryService::checkAvailability()` per baris component — peringatan
  read-only, tidak memblokir dimulainya operation (kekurangan material adalah kondisi andon,
  §3R, bukan hard stop di v1).
- Penegakan sequence: sebuah operation tidak bisa mulai sampai predecessor yang didefinisikan
  routing-nya sudah `completed`, kecuali routing menandainya parallel-eligible.

## 3H. Eksekusi Assembly — Serial Genealogy

**Fungsi / Fitur**
- `mes_serial_links` (referensi serial — `stock_serials.id` milik Inventory —
  component_serial_id atau component_lot_id, material_id, order_id, operation_id) mencatat
  component mana yang masuk ke serial jadi mana, saat masing-masing dikonsumsi/diselesaikan.
- MES **tidak** memiliki nomor serial itu sendiri; `stock_serials` (Inventory, `tracking_mode =
  serial`) adalah identitasnya, MES hanya mencatat linkage parent→component pada saat konsumsi —
  batas "jangan duplikasi ledger" yang sama seperti konsumsi material (§3J).

## 3I. Eksekusi Process — UI Batch / Phase

**Layout**
```text
BATCH B-0031              RUNNING
Mixing
Temperature   79.8 °C   ✓
Pressure       5.1 bar  ✓
RPM            1,198    ✓
Elapsed        32:14
[ PAUSE ]   [ COMPLETE PHASE ]
```
**Penyimpanan / Aturan**
- `mes_batches` (order_id, batch number, `recipe_id` — referensi lintas-schema ke
  `PP.pp_recipes` — quantity yang sudah di-scale lewat `PpService::scaleRecipe()` sesuai §3B,
  status, planned qty, actual yield %).
- `mes_batch_phases` (batch_id, process_phase_id, sequence, status, start/end, operator_id,
  equipment/machine_id).
- `mes_batch_parameter_readings` (batch_phase_id, process_parameter_id, value, recorded_at,
  recorded_by, machine_id nullable — untuk pembacaan bersumber-IoT di masa depan, §3S) —
  padanan actual-value untuk target/min/max milik `mes_process_parameters`. Pembacaan di luar
  `[min, max]` menulis event `parameter_recorded` yang ditandai `out_of_range` dan, jika
  parameter ditandai quality-critical, memicu QC hold (§3L).
- `PAUSE`/`COMPLETE PHASE` menulis baris `mes_prod_events` yang sesuai; menyelesaikan phase
  terakhir menyelesaikan batch dan memicu Production Output (§3J).
- Batch split/merge (misalnya satu run recipe dipecah jadi dua batch turunan, atau dua run
  parsial digabung): `mes_batch_relations` (parent_batch_id, child_batch_id, relation type
  `split` | `merge`, qty) — menjadi input Traceability (§3K).

## 3J. Konsumsi Material & Production Output (bersama)

**Fungsi / Fitur**
- `mes_material_consumptions` (order_id, operation_id atau batch_phase_id, product material,
  referensi lot/serial — ID milik Inventory sendiri, qty, UoM, type `issue` | `return`). Menulis
  baris di sini memanggil `InventoryService::issue()`/`return` (mencerminkan Purchase →
  `InventoryService::receive()`, `PURCHASE_SPECS.md` §3E) — MES tidak pernah menulis langsung
  ke `INVENTORY.stock_ledger`.
- `mes_production_outputs` (order_id, operation_id atau batch_phase_id, output type `finished`
  | `co_product` | `by_product` | `waste`, product, qty, UoM, referensi lot/serial). Menulis
  baris di sini memanggil `InventoryService::receive()` untuk memposting barang
  finished/co-product ke stock.
- Baris consumption dan output sama-sama menjadi bahan mentah untuk Traceability/Genealogy
  (§3K) — tidak ada penyimpanan genealogy terpisah yang menduplikasi data ini.

**Aturan / Logika**
- Jika product tenant `tracking_mode = batch`/`serial` (setting Inventory), baris Output harus
  membawa lot/serial yang sesuai; MES memanggil `InventoryService` untuk membuat lot/serial baru
  saat waktu receipt (Inventory memiliki identitasnya, sesuai Latar Belakang).
- Output waste/by-product tidak membutuhkan product master yang sellable — kategori product
  "waste" yang ringan sudah cukup, tanpa konsep product khusus-MES.

## 3K. Traceability & Genealogy (View / Report)

**Fungsi / Fitur**
- Forward trace: diberikan satu lot material mentah, daftar setiap production output (dan
  shipment hilir, lewat `shipments` milik Inventory) yang menjadi tujuannya — penelusuran
  rekursif atas `mes_material_consumptions` → `mes_production_outputs` →
  `mes_serial_links`/`mes_batch_relations`.
- Backward trace / recall: diberikan satu lot/serial jadi, daftar setiap lot material mentah dan
  batch intermediate yang dikonsumsi untuk memproduksinya.
- Tidak ada tabel genealogy khusus — ini adalah view turunan atas tabel transaksi milik
  §3H/§3I/§3J sendiri, konsisten dengan "MES tidak memiliki stock, hanya linkage-nya" dari Latar
  Belakang. Tabel genealogy yang di-materialize/cache hanya opsi Fase-Advanced kalau query
  rekursif jadi masalah performa nyata pada skala.

## 3L. Quality Control (Fase 1 dasar, Fase 2 workflow hold/release)

**Fungsi / Fitur**
- `mes_qc_inspection_plans` (referensi product atau operation/phase, name) /
  `mes_qc_characteristics` (plan_id, characteristic name, spec type numeric/pass-fail,
  target/min/max, UoM).
- `mes_qc_samples` (order_id, operation_id atau batch_phase_id, sample number, taken_by,
  taken_at) / `mes_qc_results` (sample_id, characteristic_id, actual value atau pass/fail,
  result pass/fail/hold).
- `mes_qc_holds` (subject_type/subject_id — polimorfik: order, batch, lot/serial output,
  reason, status `open`/`released`, released_by, released_at).
- Checkpoint: incoming (sebelum material_consumption diizinkan mengambil dari lot yang sedang
  hold — mendelegasikan ke kolom `quality_status` yang dicadangkan Inventory di
  `stock_batches`/`stock_ledger`, `INVENTORY_SPECS.md` §3S), in-process (sample selama satu
  operation/phase), finished-goods (sebelum Production Output diizinkan posting sebagai
  `available`, bukan cuma `on_hand`).

**Aturan / Logika**
- Hasil `fail` pada checkpoint finished-goods otomatis membuat baris `mes_qc_holds` terhadap
  lot/serial output dan memblokirnya dari flag ketersediaan `InventoryService::receive()`
  (posting ke stock sebagai on-hand-tapi-hold, bukan sellable) alih-alih memblokir receipt fisik
  itu sendiri.
- Hold release (Fase 2) bisa secara opsional lewat **WNE** (`WorkflowRequested`,
  `workflow_code = mes.qc_hold_release`) untuk tenant yang butuh dual sign-off — pola
  approval-opsional yang sama dipakai Sales untuk quote approval-nya (`SALES_SPECS.md`).
- NCR / CAPA di luar scope Fase 1 — hasil `fail` plus hold plus alasan free-text sudah cukup
  untuk MVP; workflow NCR/CAPA khusus adalah tambahan Fase-Advanced begitu tenant industri
  teregulasi membutuhkannya.

## 3M. Equipment Status & Downtime (Fase 2)

**Fungsi / Fitur**
- `mes_equipment_status_logs` (machine_id, status, started_at, ended_at) — histori status
  append-only, status saat ini di-denormalisasi ke `mes_machines.status` untuk pembacaan
  dashboard yang cepat (bisa dibangun ulang dari log, pola cache `stock_balances`-dari-
  `stock_ledger` yang sama dipakai Inventory).
- `mes_downtime_events` (machine_id atau work_center_id, order_id nullable, category `planned`
  | `unplanned`, reason_code — `maintenance`/`setup` untuk planned, `mechanical`/`electrical`/
  `material_shortage`/`quality`/`operator` untuk unplanned, started_at, ended_at).
- Downtime unplanned yang melewati ambang durasi yang bisa dikonfigurasi
  (`SYSCONFIG.config_consts`, rung 1 tangga kustomisasi) otomatis membuat maintenance request —
  MES memiliki *event operasional*-nya, modul Maintenance khusus di masa depan (belum dibangun,
  di luar scope di sini) akan memiliki work order-nya; sampai itu ada, request-nya adalah
  notifikasi WNE sederhana ke kontak maintenance yang tercatat, bukan work order yang tersimpan.

## 3N. Scrap & Rework (bersama)

**Fungsi / Fitur**
- Scrap adalah baris `mes_production_outputs` dengan `output_type = waste` plus `reason_code`
  dan `disposition` (`scrap` | `rework`).
- Rework: `disposition = rework` mengarahkan quantity ke operation/phase rework (menggunakan
  ulang execution engine §3G/§3I terhadap langkah routing/recipe yang ditandai rework) →
  diinspeksi ulang lewat §3L → `pass` posting sebagai `finished`, `fail` posting sebagai
  `scrap`.
- Yield dihitung, bukan disimpan: `good_output_qty / (good_output_qty + scrap_qty)` per
  order/batch, dibaca dari `mes_production_outputs`.

## 3O. OEE & KPI Process (Fase 2, View / Report)

**Fungsi / Fitur**
- Assembly: `OEE = Availability × Performance × Quality`, masing-masing dihitung dari
  `mes_prod_events` (Availability: planned time dikurangi `mes_downtime_events`),
  `mes_routing_ops` standard vs. actual cycle time (Performance), dan hasil §3L (Quality).
  Drill-down Line → Station → Machine → Day, sesuai mockup user.
- Process: yield % (§3N), % parameter-in-spec (porsi `mes_batch_parameter_readings` dalam
  `[min, max]`), jumlah QC hold — KPI khusus-process alih-alih memaksakan gaya OEE assembly ke
  lini continuous, sesuai Latar Belakang.
- Semua angka adalah read model yang dihitung atas §3C/§3J/§3L/§3M — tanpa tabel penyimpanan
  KPI terpisah; cache/materialize hanya jika satu query dashboard tertentu terbukti terlalu
  lambat pada skala tenant nyata.

## 3P. Referensi Shift & Handover (Fase 2)

**Fungsi / Fitur**
- Tanpa model shift milik MES — membaca `HCM.shifts`/`HCM.shift_assignments` langsung
  (read-only), sesuai Latar Belakang ("simpan employee master data di HR dan biarkan MES
  mereferensikannya").
- `mes_shift_handover_notes` (shift_assignment_id — referensi HCM, ringkasan order/batch saat
  handover, catatan free-text misalnya masalah mesin / hasil QC terakhir) adalah satu-satunya
  tabel milik MES di sini, karena konten shift handover spesifik-produksi, bukan urusan HCM.

## 3Q. Scheduling MES (Fase 2)

**Fungsi / Fitur**
- Sequencing shop-floor jangka pendek, mengonsumsi rencana produksi tingkat-ERP (MRP masa
  depan, di luar scope di sini) alih-alih menggantikannya, sesuai arsitektur Planning di brief
  sumber spesifikasi ini (`Sales → MRP → Production Plan → MES Schedule → Shop Floor`).
- Mempertimbangkan kapasitas machine (`mes_machines`), ketersediaan material
  (`InventoryService::checkAvailability()`), ketersediaan operator
  (`HCM.shift_assignments`), waktu setup/changeover (`mes_routing_ops.setup_time` / padanan
  recipe phase), priority, due date.
- Campaign scheduling untuk manufaktur process: mengelompokkan order recipe yang sama secara
  berurutan untuk meminimalkan changeover — aturan sequencing atas `mes_prod_order_hdrs`, bukan
  konsep penyimpanan baru.
- Berkomposisi dengan Resource/Availability engine milik modul Core **Schedule** yang sudah ada
  (`SCHEDULE_SPECS.md` §3D/3E) untuk kalender operator/machine alih-alih membangun engine
  kalender kedua — aturan "jangan duplikasi modul Core yang sudah ada" yang sama sudah diikuti
  Dock Scheduling (Advanced) milik Inventory (`INVENTORY_SPECS.md` §3S).

## 3R. Alert & Andon (Fase 3)

**Fungsi / Fitur**
- State Andon (`running` / `attention` / `stopped` / `maintenance`) diturunkan dari
  `mes_machines.status` + `mes_downtime_events` yang terbuka + `mes_qc_holds` yang terbuka +
  peringatan kekurangan material — sebuah read model, bukan penyimpanan baru.
- Pengiriman alert (kekurangan material, machine berhenti, parameter out-of-spec, terlambat dari
  jadwal, batch overdue, maintenance dibutuhkan) lewat notification engine milik **WNE** yang
  sudah ada (`NotificationRequested`), seam integrasi yang sama dipakai modul lain — MES tidak
  membangun channel notifikasinya sendiri.

## 3S. Integrasi IoT / PLC (Fase 3)

**Fungsi / Fitur**
- Hanya lapisan integrasi, tidak pernah hard-code penanganan protokol machine di dalam service
  MES sendiri: `PLC/SCADA → IoT Gateway → MQTT/OPC-UA → MES Integration Layer →
  mes_prod_events / mes_batch_parameter_readings`.
- Ingestion adalah queued job (Redis, sesuai `CLAUDE.md` §3) yang menulis ke tabel §3C/§3I yang
  sama dipakai data yang dimasukkan operator — execution engine punya satu write path terlepas
  apakah event dihasilkan manusia atau machine.
- Protocol adapter (OPC-UA/MQTT/Modbus/REST/WebSocket) adalah interface pluggable; jika
  kebutuhan throughput/runtime protokol tertentu melampaui monolith (misalnya ingestion
  time-series frekuensi-tinggi yang sustained), adapter itu — bukan seluruh MES — adalah
  kandidat ekstraksi-microservice, dievaluasi terhadap kriteria `CLAUDE.md` §2 pada saat itu,
  bukan lebih dulu.

## 3T. Dashboard

**Fungsi / Fitur**
- Plant Dashboard: % production-to-plan, OEE, downtime, reject rate, active orders, active
  batches.
- Line Dashboard: running state per-line, OEE, target vs. actual, reject count, downtime.
- Process Area Dashboard: active batches, rata-rata yield, parameter alarms, QC holds.
- Sikap "beberapa dashboard fokus, bukan satu dashboard raksasa" yang sama seperti brief sumber
  user — masing-masing read model atas §3C/§3J/§3L/§3M/§3O, disusun dari `StatCard`/`Panel`
  (`CLAUDE.md` §9D5) sesuai design system bersama.

## 3U. Digital Audit Trail (`mes_audit_logs`)

**Fungsi / Fitur**
- Log perubahan level-field — siapa/apa/kapan/di mana/sebelum/sesudah/alasan — untuk edit yang
  sensitif-governance (misalnya target process-parameter diubah pada recipe aktif, hasil QC
  di-override, hold dilepas secara manual). Berbeda dari `mes_prod_events` (§3C): event adalah
  stream aksi *bisnis* (start/stop/consume/produce); `mes_audit_logs` adalah stream
  *change-history* untuk edit terhadap data yang sudah tercatat, split yang sama
  didokumentasikan `SYSCONFIG` antara `config_audit_logs` dan runtime config-nya sendiri
  (`SYSCONFIG_SPECS.md` §3G).
- Konvensi `*_audit_logs` append-only per-modul yang sama seperti `SYSCONFIG.config_audit_logs`,
  `WNE.wrkflow_audit_logs`, `ACCOUNTING.audit_logs`, `DMS.access_logs`.

---

# 4. Penyimpanan

> Tabel dan tata letak schema di bawah schema PostgreSQL `MES` milik tenant.

**Tabel master / lookup**
- `MES.mes_work_centers`, `MES.mes_machines`, `MES.mes_stations` (§3D)
- `MES.mes_routings`, `MES.mes_routing_ops` (§3E, discrete)
- `MES.mes_process_phases`, `MES.mes_process_parameters` (§3F, process) — `recipe_id` adalah
  referensi lintas-schema ke `PP.pp_recipes` (catatan batas §3B; BOM/Recipe itu sendiri hidup
  di `PP.pp_boms`/`PP.pp_recipes`, bukan di sini)
- `MES.mes_qc_inspection_plans`, `MES.mes_qc_characteristics` (§3L)

**Tabel transaksi / eksekusi**
- `MES.mes_prod_order_hdrs` (§3A) — mereferensikan `INVENTORY.products`,
  `INVENTORY.warehouses`, dan `PP.pp_boms`/`PP.pp_recipes` (`bom_id`/`recipe_id`,
  lintas-schema — §3B)
- `MES.mes_prod_events` — append-only (§3C)
- `MES.mes_batches` (`recipe_id` lintas-schema ke `PP.pp_recipes`), `MES.mes_batch_ingredients`
  (quantity per-ingredient yang sudah di-scale — penyimpanan konkret di balik kalimat §3I
  "batch menyimpan quantity yang sudah di-resolve/di-scale"), `MES.mes_batch_phases`,
  `MES.mes_batch_parameter_readings`, `MES.mes_batch_relations` (§3I, process)
- `MES.mes_serial_links` (§3H, assembly) — mereferensikan `INVENTORY.stock_serials`
- `MES.mes_material_consumptions`, `MES.mes_production_outputs` (§3J) — mereferensikan
  `INVENTORY.stock_batches`/`INVENTORY.stock_serials`
- `MES.mes_qc_samples`, `MES.mes_qc_results`, `MES.mes_qc_holds` (§3L)
- `MES.mes_equipment_status_logs`, `MES.mes_downtime_events` (§3M, Fase 2)
- `MES.mes_shift_handover_notes` (§3P, Fase 2) — mereferensikan `HCM.shift_assignments`
- `MES.mes_audit_logs` — append-only (§3U)

**Custom fields:** `mes_prod_order_hdrs` dan `mes_batches` didaftarkan sebagai entity extensible
di `CUSTOMFIELDS.field_defs`, pola yang sama seperti header master/transaksi modul Core lainnya
(registrasi custom-field milik BOM/Recipe sendiri adalah milik PP, `PP_SPECS.md` §4).

**Object File** (sesuai `CLAUDE.md` §7B):
- MES tidak memiliki folder R2 top-level sendiri — Electronic Work Instructions, sertifikat QC,
  dan lampiran batch/order apa pun sepenuhnya lewat struktur milik **DMS**
  (subfolder `DMS/MES/...`) dengan pointer `subject_type`/`subject_id` kembali ke record
  pemiliknya, sama seperti yang sudah dilakukan WNE/HCM/Sales (`CLAUDE.md` §7B).

---

# 5. Catatan Teknis

- **Satu engine, dua model**: `production_model` pada `mes_prod_order_hdrs` adalah satu-satunya
  titik cabang. Eksekusi discrete berjalan lewat Operations (§3E/§3G); eksekusi process berjalan
  lewat Phases (§3F/§3I). Setiap engine lainnya (event, material, output, quality, traceability,
  equipment, OEE, dashboard, audit) model-agnostic dan membaca/menulis tabel bersama yang sama —
  ini adalah keputusan arsitektur inti tempat modul ini dibangun, sesuai §25 brief sumber.
- **MES tidak pernah memiliki stock atau identitas yang tidak perlu dimilikinya**: konsumsi/
  output material selalu lewat `InventoryService`; identitas lot/serial milik Inventory;
  identitas shift/employee milik HCM; komposisi material (BOM/Recipe) milik **PP** (catatan
  batas §3B). MES memiliki *fakta eksekusi produksi* (apa yang terjadi, kapan, oleh siapa,
  terhadap order/batch mana) plus master data *langkah-eksekusi* (Routing/Process-Phases,
  §3E/§3F) yang mengonsumsi data komposisi milik PP — disiplin batas yang sama diwajibkan
  `CLAUDE.md` §2 antar modul Core.
- **Frontend**: layout Shop Floor khusus (§3G/§3I) terpisah dari chrome admin `AppLayout` —
  tetap disusun dari design system bersama (`DataTable`, `Panel`, `StatCard`, `StatusBadge`,
  `CLAUDE.md` §9D) untuk setiap layar non-shop-floor (entry order, admin BOM/recipe, dashboard,
  admin QC).
- **Tabel append-only**: `mes_prod_events` dan `mes_audit_logs` tidak pernah di-update/dihapus
  di tempat, sesuai disiplin yang sudah ada di `INVENTORY.stock_ledger` dan
  `SYSCONFIG.config_audit_logs`.
- **Async secara default untuk data bersumber-machine**: ingestion IoT/PLC (§3S) dan streaming
  parameter frekuensi-tinggi di masa depan lewat Redis queue, tidak pernah request path
  sinkron, agar gateway yang tidak stabil tidak bisa memblokir eksekusi yang menghadap operator.
- **Kode menu/permission**: `menu.perm:MES_*` (misalnya `MES_PROD_ORDER`, `MES_QC`,
  `MES_SHOPFLOOR`) lewat middleware trustee SYSCONFIG, sama seperti modul lainnya
  (`CLAUDE.md` §4).
- **Plan gating**: middleware `module:MES` + entry `config/tenant_modules.php` — ditambahkan ke
  `full` saja; apakah MES sebaiknya berada di plan Manufaktur khusus yang lebih sempit dilacak
  sebagai open item di bawah, bukan ditebak di sini.

---

# 6. Urutan Pembangunan

> Urutan yang direkomendasikan untuk membangun bagian-bagian milik modul ini sendiri, dan
> alasannya. Ini internal untuk MES — lihat `CLAUDE.md` §5 untuk posisi seluruh modul dalam
> urutan pembangunan platform (diurutkan sesudah Performance, sebelum AIInsight — bergantung
> pada Inventory/HCM/WNE/DMS, semuanya lebih dulu dalam daftar itu).

1. **Master data (§3D, §3E/§3F)** — Work Centers/Machines/Stations dulu (tanpa dependent), lalu
   Routing (discrete) dan Process Phases/Parameters (process). Routing hanya bergantung pada
   `INVENTORY.products`; Process Phases tambahan bergantung pada `pp_recipes` milik **PP** yang
   sudah ada (catatan batas §3B) untuk FK `recipe_id`-nya — master data BOM/Recipe itu sendiri
   tidak lagi dibangun di sini, lihat §3B.
2. **Production Order (§3A)** — bergantung pada master data di atas; tidak ada bagian lain di
   MES yang berfungsi tanpa order untuk melekat.
3. **Production Event Ledger (§3C)** — dibangun bersamaan dengan Production Order, karena Order
   Released adalah event pertama; setiap engine belakangan menulis ke sini, jadi harus ada
   sebelum §3G/§3I.
4. **Konsumsi Material & Output (§3J)** — butuh integrasi `InventoryService`; dibangun sebelum
   §3G/§3I agar UI eksekusi punya sesuatu untuk dipanggil saat Complete.
5. **Eksekusi Assembly (§3G, §3H)** atau **Eksekusi Process (penyimpanan §3F, §3I)** — bangun
   model produksi mana pun yang benar-benar dibutuhkan tenant nyata pertama; yang satunya bisa
   menyusul, karena tidak ada bagian lain di Fase 1 yang bergantung pada keduanya ada
   bersamaan.
6. **Scrap & Rework (§3N)** — lapisan tipis di atas tabel output §3J, dirilis bersamaan dengan
   UI eksekusi mana pun (§5) yang tiba lebih dulu.
7. **Traceability & Genealogy (§3K)** — read model murni atas §3H/§3I/§3J; dirilis begitu
   consumption dan output sama-sama punya data nyata untuk di-query.
8. **Quality dasar (§3L)** — checkpoint bergantung pada event completion §3G/§3I dan pembuatan
   output §3J; butuh keduanya ada lebih dulu.
9. **Digital Audit Trail (§3U)** — concern cross-cutting yang tipis, disambungkan bertahap
   seiring path edit sensitif-governance tiap engine di atas teridentifikasi, alih-alih
   dibangun monolitik di depan.
10. Item Fase 2 (§3M, §3O, §3P, §3Q) dan item Fase 3 (§3R, §3S) menyusul hanya setelah Fase 1
    tervalidasi dengan tenant nyata, sesuai pembertahapan §2.

---

# 7. Item Terbuka

- [x] **Penempatan urutan pembangunan platform** — `CLAUDE.md` §5 mencantumkan MES, diurutkan
      sesudah Performance, sebelum AIInsight.
- [x] **Entry `config/tenant_modules.php`** — ditambahkan ke `full`.
- [ ] **Plan/vertikal Manufaktur khusus** — `full` adalah bundle placeholder ("segalanya");
      apakah MES pada akhirnya butuh tier plan sendiri (agar tenant manufaktur tidak membayar
      modul khusus-Legal, atau sebaliknya) adalah keputusan untuk saat tenant manufaktur nyata
      jadi prospek, tidak ditebak di sini.
- [ ] **Modul Maintenance** — hook downtime → maintenance-request milik §3M hanya notifikasi
      WNE sampai modul Maintenance khusus (work order preventive/corrective, spare parts,
      asset) ada; di luar scope spesifikasi ini.
- [x] **MRP / Production Planning** — terselesaikan: **PP** (`app/Modules/PP/PP_SPECS.md`)
      adalah engine Demand/MPS/MRP/Capacity/Scheduling yang diasumsikan §3Q akan ada; PP
      memiliki master data BOM/Recipe (catatan batas §3B) dan merilis planned order ke
      `mes_prod_order_hdrs` (§3A).
- [ ] **Implementasi kontrak `PpService`** — §3B/§3F kini memanggil `PpService::getActiveBom`/
      `getActiveRecipe`/`scaleRecipe`; disebutkan di sini dan di `PP_SPECS.md` §3D tapi belum
      diimplementasikan — dilacak sekali, di `PP_SPECS.md` §7, tidak diduplikasi di sini.
- [x] **Konsekuensi urutan pembangunan dari perpindahan BOM/Recipe** — karena Process Phases
      (§3F) kini FK ke `PP.pp_recipes`, master data BOM/Recipe milik PP harus ada sebelum
      Process Phases milik MES bisa dibangun. `CLAUDE.md` §5 kini mengurutkan **PP sebelum
      MES**, membalik penempatan yang tercatat saat PP pertama kali ditambahkan (lihat
      `PP_SPECS.md` §7 untuk update yang berkaitan).
