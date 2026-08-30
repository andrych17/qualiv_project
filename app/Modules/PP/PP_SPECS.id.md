# Modul PP
## Modul Core Bersama — Perencanaan & Penjadwalan Produksi (Engine Perencanaan Hybrid Diskret/Proses, Multi-Level)

**Kategori modul** (`CLAUDE.md` §2/§10): **Core**. PP tidak punya pengetahuan apa pun tentang
vertikal mana pun — ia adalah engine perencanaan bersama yang disewa tenant manufaktur mana pun
apa pun yang mereka buat, sikap yang sama dengan **MES** (`MES_SPECS.md`). Modul ini bukan
Platform-level (hidup di DB tenant seperti modul Core lainnya) dan tidak ada kriteria
ekstraksi-microservice `CLAUDE.md` §2 yang terpenuhi hari ini (tidak ada kebutuhan scaling yang
berbeda, tidak ada kebutuhan runtime non-PHP, tidak ada reuse lintas-produk di luar monolith ini,
tidak ada data yang butuh isolasi tinggi) — lihat §7 Item Terbuka untuk satu bagian (constraint
solver di masa depan) yang bisa ditinjau ulang nanti, bukan sekarang. Modul ini kini terdaftar di
`CLAUDE.md` §4/§5/§7A (schema `PP`, diurutkan tepat **sebelum MES** dalam urutan pembangunan
Core, karena master data Process Phases milik MES melakukan FK ke `pp_recipes` milik modul ini —
lihat §7) dan dibuka pada plan `full` di `config/tenant_modules.php`; belum ada di `starter`,
`legal`, atau `internal`, sikap placeholder yang sama seperti awal mula MES.

**Hubungan dengan MES**: PP adalah separuh *Planning* dari pemisahan yang sudah digambar
`MES_SPECS.md` §22 antara "apa yang harus kita produksi, kapan, memakai resource apa" (Planning)
dan "apa yang sebenarnya sedang terjadi di lantai produksi" (MES/eksekusi). §7 Item Terbuka
`MES_SPECS.md` mencantumkan "MRP / Production Planning… belum dispesifikasikan di mana pun"
sebagai celah yang diketahui dan memberi makan penjadwalan §3Q-nya — spesifikasi ini adalah
penutup celah itu. PP memiliki komposisi material (BOM/Recipe, §3D) sebagai master data
perencanaan — komposisi material adalah yang di-explode MRP, jadi itu ada di sini, bukan di
eksekusi. PP tidak mempersoalkan ulang apa yang sudah dimiliki dan dieksekusi MES (identitas Work
Center/Machine/Station, langkah eksekusi Routing/Process-Phase, record Production Order, event
ledger, UI eksekusi) — PP memanggil kontrak service milik MES untuk hal-hal itu dan menyerahkan
sebuah release; sebaliknya MES memanggil kontrak service milik PP sendiri untuk BOM/Recipe,
disiplin dua-arah "jangan duplikasi tabel milik modul Core lain" yang sama yang MES sendiri
terapkan pada Inventory dan HCM (`MES_SPECS.md` §5).

---

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

- Tool yang hanya-MRP mengasumsikan satu model produksi. Platform ini harus merencanakan
  **kedua-duanya**: manufaktur diskret (BOM → komponen → operasi) dan proses (formula → batch →
  yield/co-product) dengan satu engine, bukan dua, atau setiap fitur perencanaan (netting,
  capacity, scheduling, exception) harus dibangun dua kali.
- MES hari ini tidak punya sumber upstream untuk pekerjaan yang *sudah direncanakan*:
  `mes_prod_order_hdrs` (`MES_SPECS.md` §3A) hanya bisa dibuat secara manual atau dari referensi
  Sales order polos. Tidak ada lapisan yang mengubah demand menjadi rencana produksi yang
  feasible, sudah dicek kapasitasnya, sudah dicek materialnya, sebelum menjadi order yang
  dieksekusi MES — jadi planner bisa membebani mesin/tank secara berlebihan tanpa menyadarinya,
  atau baru menemukan kekurangan material setelah rilis.
- Memperlakukan "Demand → MRP → Production Order" sebagai kalkulasi satu-lintasan (MRP klasik)
  mengabaikan kapasitas sepenuhnya; rencana yang secara material feasible tapi mustahil
  dieksekusi di lantai produksi (mesin overload 124%, tank tidak tersedia) baru ditemukan
  terlalu terlambat — mode kegagalan yang sama yang dijelaskan Latar Belakang `MES_SPECS.md`
  untuk visibilitas eksekusi, satu lapis di atasnya.
- Planning dan eksekusi harus tetap decoupled (`MES_SPECS.md` §22): jika PP menjangkau langsung
  ke `MES.mes_prod_events` atau Schedule menjangkau langsung ke `MES.mes_machines`, perubahan
  pada internal salah satu modul akan merusak modul lainnya. Batasnya harus berupa kontrak
  service, bukan tabel bersama.

**Kebutuhan klien** (dari brief sumber):
- **Satu engine perencanaan, dua model produksi** — diskret dan proses berbagi konsep Demand,
  MPS, MRP, Capacity, dan Scheduling yang sama; yang berbeda hanya explosion-BOM vs.
  scaling-formula, dan PP memiliki keduanya secara langsung (§3D di bawah), bukan membaca master
  data modul lain untuk kalkulasi intinya sendiri.
- **Lima lapisan perencanaan**, masing-masing dengan horizon dan tujuannya sendiri, bukan satu
  algoritma yang mencoba menyelesaikan semuanya: Demand Planning → MPS → Material Planning (MRP)
  → Capacity Planning → Detailed Scheduling → (rilis ke) Production Orders → eksekusi MES.
- **Capacity dimodelkan secara generik** — machine-hours, labor-hours, kuantitas material,
  kapasitas storage/tank, dan kapasitas utility (steam, compressed air, listrik) semuanya adalah
  "beban resource vs. ketersediaan resource" pada suatu periode, bukan lima engine khusus
  terpisah.
- **Resource group** (misalnya "MIXING" = Mixer 01/02/03) sehingga capacity planning
  level-tinggi tidak memaksa planner memilih mesin spesifik sebelum itu benar-benar penting.
- **UX perencanaan yang digerakkan-exception** — planner men-triage daftar exception yang
  pendek, bukan ribuan order individual.
- **Scenario / what-if planning** yang tidak pernah menyentuh inventory live, order, atau
  rencana baseline.
- **Sequencing yang sadar-campaign/changeover** untuk manufaktur proses, dan dispatch rule yang
  dapat dikonfigurasi untuk diskret — sebuah strategi yang diterapkan scheduler, bukan algoritma
  hard-coded.
- **PP tidak memiliki identitas *eksekusi*** — Work Center/Machine/Station dan record Production
  Order itu sendiri tetap milik MES; PP memanggil kontrak service MES untuk hal-hal itu dan
  menulis sebuah release, batas yang sama yang dipegang MES dengan Inventory/HCM
  (`MES_SPECS.md` §5). **PP memang memiliki** komposisi material (BOM/Recipe, §3D) sebagai
  master data perencanaan — satu-satunya tempat spesifikasi ini berbeda dari pembacaan naif "MES
  memiliki segala hal tentang manufaktur" atas brief tersebut.

---

# 2. Tujuan (Goals)

> Dibagi ke dalam fase sesuai bias MVP `CLAUDE.md` §10, gaya pemfasean yang sama dengan
> `MES_SPECS.md` §2. Prioritas di bawah adalah tabel P0–P3 milik brief sumber sendiri, dipetakan
> ke bentuk tiga-fase MES: **Fase 1 = P0, Fase 2 = P1, Fase 3 = P2 + P3 digabung** (dapat
> ditelusuri 1:1, bukan diturunkan ulang).

## Fase 1 — Core (kirim duluan, = P0 pada brief)
- Item Planning Parameters (§3A).
- Agregasi demand dari Sales order, forecast, safety stock/reorder point (§3B).
- Master data BOM/Recipe — milik PP sendiri, tidak dibaca dari MES — plus netting dan explosion
  MRP (§3D).
- Planned Order (production / purchase / transfer) dan handoff rilis-ke-MES (§3D, §3K).
- Pengecekan resource/capacity kasar yang memakai ulang identitas equipment MES dan kalender
  Schedule (§3E, §3F).
- Planning Exception Center dasar (§3M).

## Fase 2 — Operasional (menyusul cepat, = P1 pada brief)
- Grid Master Production Schedule dengan drill-down dan freeze fence (§3C).
- Board Rough-Cut Capacity Planning (RCCP) dan capacity-by-dimension (machine/labor/material/
  storage/utility) (§3F, §3G).
- Resource Group dan dukungan alternate-resource (§3E).
- Detailed Scheduling finite-capacity / board Gantt (§3H).
- Scheduling rule (dispatch strategy) dan matrix Setup/Changeover (§3I, §3J).
- Kekhususan perencanaan manufaktur-proses: campaign grouping, perencanaan batch-size terhadap
  yield recipe milik PP sendiri, kapasitas tank (§3K mencakup sisi constraint secara generik).
- Exception Center lengkap dengan drill-down dan suggested action (§3M).

## Fase 3 — Advanced (versi masa depan, = P2 + P3 pada brief — jangan dibangun sekarang)
- Scenario / what-if planning, termasuk "what-if rescheduling" untuk order tertentu (§3L, §3N).
- Optimasi sequence otomatis (di luar dispatch rule yang dapat dikonfigurasi).
- Constraint solver tingkat lanjut (material + resource + sequence + tank + quality + labor
  sekaligus, di luar pengecekan per-constraint Fase 1 di §3K).
- Perencanaan/rekomendasi jadwal berbantuan-AI — komposisi AIInsight, gerbang Zero Data
  Retention yang sama seperti fitur AI lainnya (`CLAUDE.md` §5).

---

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Parameter Perencanaan Item (`pp_item_planning_params`)

**Fungsi / fitur**
- Satu baris per item `INVENTORY.products` (bukan salinan product master — baris pendamping
  khusus-perencanaan, pola yang sama seperti ekstensi custom-fields tanpa memakai EAV untuk
  sesuatu yang sudah cukup terstruktur seperti ini): make-to-stock/make-to-order, lot sizing
  (minimum/maximum/fixed/economic), safety stock, lead time, planning lead time, order multiple,
  scrap %, yield % override, referensi kalender produksi, lini produksi preferred/alternate
  (referensi `MES.mes_work_centers`, via `MesService`, bukan reach-through FK mentah), planning
  fence.
- Terdaftar di `CUSTOMFIELDS.field_defs` seperti baris master modul Core lainnya, sehingga
  tenant bisa memperluasnya lewat customization ladder (`CLAUDE.md` §2), bukan lewat cabang kode
  PP.

## 3B. Agregasi Demand (`pp_demand_hdrs` / `pp_demand_lines`)

**Fungsi / fitur**
- Mengagregasi kebutuhan produksi dari: Sales order (`SALES.so_hdrs`, via event bergaya
  `SalesOrderRequested` yang di-subscribe PP, pola yang sama yang sudah dipakai
  `SALES_SPECS.md` §3I untuk Accounting), forecast sales (`pp_demand_forecasts`, dientri manual
  atau diimpor), safety stock / reorder point (membaca `pp_item_planning_params` +
  `InventoryService::checkAvailability()`), blanket order / jadwal pelanggan (dimiliki Sales,
  direferensikan bukan disalin), rencana produksi manual (baris yang dientri planner), dependent
  demand (explosion BOM/recipe dari planned order milik item level-lebih-tinggi — §3D), demand
  transfer antar-gudang (referensi bergaya `INVENTORY.stock_reservations`).
- Setiap baris demand membawa `scenario_id` (nullable — lihat §5 Isolasi Scenario); `NULL`
  adalah rencana live/baseline yang secara default dibaca setiap engine lain di modul ini.

**Aturan / logika**
- Agregasi demand bersifat aditif dan sebagian besar hanya-baca: PP tidak pernah mengubah Sales
  order atau reorder point Inventory, ia hanya membacanya untuk menghitung baris demand — disiplin
  "jangan duplikasi sumber kebenaran" yang sama seperti material consumption milik MES
  (`MES_SPECS.md` §3J).

## 3C. Master Production Schedule — MPS (`pp_mps_hdrs` / `pp_mps_lines`)

**Layout**
```text
Item        W1     W2     W3     W4     W5
──────────────────────────────────────────
Product A  1,000  1,500  1,000  2,000  1,500
Product B    500    800    600    700    900
Product C  2,000  2,000  1,500  2,500  2,000
```
- Setiap cell bisa di-drill-down: **Demand → Planned Production → Material → Capacity → Orders**
  — panel detail yang disusun dari data §3B/§3D/§3F/§3D-release untuk item/periode itu, bukan
  tabel drill-down tersimpan terpisah.

**Aturan / logika**
- Time-phased (periode = minggu/bulan yang bisa dikonfigurasi tenant via
  `SYSCONFIG.config_consts`, rung 1).
- Kontrol: freeze/unfreeze period (memblokir MRP dari me-replan cell yang beku), split/gabung
  kuantitas produksi antar periode, ubah due date, ubah lini produksi (referensi
  `mes_work_centers`), firm sebuah planned order (mengecualikannya dari regenerasi MRP
  otomatis), rilis sebuah planned order (aksi rilis milik §3D), peringatan exception inline
  (membaca §3M).
- `pp_mps_lines` juga membawa `scenario_id` — MPS yang diedit di dalam sebuah scenario tidak
  pernah menulis ke baris baseline.

## 3D. Master Data BOM / Recipe & Engine MRP (`pp_boms` / `pp_bom_lines` / `pp_recipes` /
`pp_recipe_ingredients` / `pp_mrp_runs` / `pp_planned_orders`)

**Fungsi / fitur — BOM / Recipe (dimiliki di sini, bukan MES)**
- **BOM Diskret** (`pp_boms` / `pp_bom_lines`): header — product, version, tanggal efektif,
  `is_active`; baris — component product, kuantitas per unit induk, UoM, scrap %.
- **Process Recipe / Formula** (`pp_recipes` / `pp_recipe_ingredients`): header — product,
  version, batch size, UoM, expected yield %, expected waste %; ingredient — raw material
  product, kuantitas per batch recipe, UoM.
- **Scaling formula**: `RecipeService::scale(recipe, target_batch_size)` mengembalikan kuantitas
  ingredient yang di-scale (`qty = recipe_qty * target_batch_size / recipe.batch_size`) — murni
  kalkulasi, tidak ada baris hasil scale yang disimpan; Batch milik MES (`MES_SPECS.md` §3I)
  menyimpan kuantitas yang sudah *di-resolve* dan di-scale saat pembuatan (via
  `PpService::scaleRecipe()`) sehingga batch historis tetap akurat meski recipe-nya diedit
  belakangan.
- Komposisi material sengaja dijadikan master data **perencanaan**, bukan master data eksekusi:
  itulah yang di-explode MRP untuk menghitung net requirement, dan ia berubah pada kadensi
  perencanaan (engineering change, cost rollup) yang independen dari run lantai produksi
  spesifik mana pun. Routing/Process-Phase milik MES sendiri (`MES_SPECS.md` §3E/§3F) — urutan
  *langkah eksekusi* — tetap di MES, karena terikat pada identitas work-center/equipment yang
  dimiliki MES. MES membaca BOM/Recipe via `PpService::getActiveBom(productId)`/
  `getActiveRecipe(productId)`, cerminan dari PP yang memanggil `MesService::listResources()`
  untuk equipment (§3E).
- Hanya satu version `is_active = true` per product pada satu waktu; membuat version aktif baru
  tidak mengubah secara retroaktif order yang masih terbuka atau production order MES yang sudah
  dirilis (mereka tetap memakai `bom_id`/`recipe_id` yang berlaku saat dirilis).

**Fungsi / fitur — Engine MRP & Planned Order**
- Kalkulasi net requirement per item/periode:
```text
Gross Requirement
- Available Inventory
- Scheduled Receipts
- Expected Production
+ Safety Stock
-------------------
Net Requirement
```
- Explosion BOM (diskret) dan explosion Formula (proses) membaca langsung dari `pp_boms`/
  `pp_recipes` milik section ini sendiri — tidak perlu panggilan service lintas-modul untuk data
  milik PP sendiri ini, berbeda dengan Resource/Capacity (§3E/§3F) yang memang menjangkau ke MES.
- Output: baris `pp_planned_orders`, salah satu dari tiga tipe — `production` (diskret atau
  proses), `purchase` (kekurangan komponen/raw-material), `transfer` (antar-gudang). Setiap
  baris: item, kuantitas, need-by date, referensi demand sumber (`subject_type`/`subject_id`
  kembali ke baris `pp_demand_lines`/`pp_mps_lines` yang menghasilkannya), `scenario_id`.
- Penomoran order: `SYSCONFIG.config_snums` (`snum_code = PP_PLAN_LASTID`) — seri milik PP
  sendiri, berbeda dari `MES_MO_LASTID` milik MES (`MES_SPECS.md` §3A); PP tidak pernah
  menetapkan nomor order MES.

**Aturan / logika — Release (seam Planning → Execution)**
- Merilis planned order bertipe `production` memanggil `MesService::createProductionOrder(bomId:
  ..., recipeId: ..., ...)`, yang membuat baris `MES.mes_prod_order_hdrs` dengan `bom_id`/
  `recipe_id` diset ke baris milik PP sendiri (referensi lintas-schema, sesuai
  `MES_SPECS.md` §3A/§3B) dan `subject_type = 'pp.planned_order'`,
  `subject_id = pp_planned_orders.id` — persis referensi sumber "MRP suggestion" yang sudah
  diantisipasi field ini di `MES_SPECS.md` §3A. MES memiliki segalanya sejak titik itu: nomor
  order miliknya sendiri, status lifecycle, dan eksekusi.
- Merilis planned order bertipe `purchase` memanggil `PurchaseService` untuk membuat purchase
  requisition (seam bergaya `BillRequested` yang sama yang sudah didefinisikan
  `PURCHASE_SPECS.md` §3F untuk AP), bukan tabel purchase-order milik PP.
- Merilis planned order bertipe `transfer` memanggil `InventoryService` untuk membuat transfer
  request.
- Planned order di dalam `scenario_id` yang non-null tidak pernah bisa dirilis — release adalah
  aksi khusus-baseline, ditegakkan di lapisan service, bukan hanya di UI (§5 Isolasi Scenario).

## 3E. Referensi Resource & Resource Group (`pp_resource_groups`, `pp_resources`)

**Fungsi / fitur**
- PP **tidak** memodelkan ulang identitas machine/work-center — `MES.mes_work_centers`/
  `mes_machines`/`mes_stations` tetap menjadi identitasnya (`MesService::listResources()`), dan
  ketersediaan labor tetap `HCM.shifts`/`HCM.shift_assignments` (read-only, sikap yang sama yang
  sudah dipegang `MES_SPECS.md` §3P). `pp_resources` hanya ada untuk *tipe* resource yang belum
  dimiliki modul Core lain — tool, tank, utility (steam/compressed air/listrik/gas/cooling
  water), warehouse-as-capacity — setiap baris: type, code, name, capacity, UoM,
  `external_type`/`external_id` (nullable — diset ketika baris ini sebenarnya adalah machine MES
  atau labor group HCM yang dialiaskan ke sebuah resource group, bukan diduplikasi).
- `pp_resource_groups` (misalnya "MIXING") / `pp_resource_group_members` (group_id, referensi
  resource — machine MES atau baris `pp_resources`) — memungkinkan planner meminta "20
  machine-hour dari MIXING" tanpa harus memilih Mixer 01 vs. 02 vs. 03 pada tahap perencanaan
  (sesuai brief sumber §7); Detailed Scheduler (§3H) yang membuat penugasan spesifiknya belakangan.

**Aturan / logika**
- Data kalender/shift/maintenance-window tidak pernah dientri ulang di PP — setiap kalkulasi
  capacity (§3F) memanggil `AvailabilityService::isFree()`/`findConflicts()` milik Schedule
  (`SCHEDULE_SPECS.md` §3E) untuk kalender, dan status equipment MES
  (`MES.mes_equipment_status_logs`, `MES_SPECS.md` §3M) untuk downtime planned/unplanned, aturan
  "compose dengan Schedule, jangan bangun kalender kedua" yang sama yang sudah dikomit
  `MES_SPECS.md` §3Q.

## 3F. Capacity Planning — RCCP (`pp_capacity_plans`)

**Layout**
```text
Work Center   Required   Available   Load
─────────────────────────────────────────
Cutting          420 hr      480 hr    88%
Welding          620 hr      500 hr   124%  ⚠
Painting         380 hr      420 hr    90%
Assembly         710 hr      800 hr    89%
```

**Fungsi / fitur**
- Satu baris `pp_capacity_plans` per resource-atau-resource-group / periode / `scenario_id`:
  beban required (dijumlahkan dari output MPS/MRP × waktu standar routing atau recipe-phase, via
  `MesService`), kapasitas available (`AvailabilityService` milik Schedule + kalender resource),
  load % yang dihitung.
- Rough-cut saja di Fase 1 (asumsi infinite-capacity, load vs. available bersifat informasional
  saja); penegakan finite adalah tugas Detailed Scheduling (§3H), sesuai pembagian
  finite/infinite di §3G.

**Aturan / logika**
- Overload (load % > 100, atau threshold yang dikonfigurasi tenant via
  `SYSCONFIG.config_consts`) menulis sebuah exception §3M dengan suggested action yang diangkat
  dari daftar §8 brief sumber: tambah overtime, tambah shift, pindahkan produksi, pakai resource
  alternate, outsource, ubah kuantitas/due date.

## 3G. Capacity Berdasarkan Beberapa Dimensi

**Fungsi / fitur**
- Bukan lima engine terpisah — kalkulasi load/available milik §3F diparameterisasi oleh
  `pp_resources.type` (machine, labor, material, storage/tank, utility) dan UoM-nya (hours, kg/
  L/units, atau unit native utility). Satu service pengecekan-capacity, satu kolom dimensi,
  sesuai framing §9 brief sumber sendiri:
```text
Production Plan
       │
       ├── Machine capacity       OK
       ├── Labor capacity         OK
       ├── Raw material           OK
       ├── Tank capacity          OVER
       └── Steam capacity         OVER
```

## 3H. Detailed Scheduling

**Layout**
```text
             Mon       Tue       Wed
             08 12 16 20 08 12 16 20
Machine 01   ████████      ███████
Machine 02       █████████████
Machine 03               █████████
```

**Fungsi / fitur**
- Proposal finite-capacity, spesifik-resource-dan-waktu untuk setiap operation (diskret) atau
  phase (proses) milik order yang planned/released: machine spesifik, start/end spesifik,
  menghormati konflik `AvailabilityService` milik Schedule dan standar setup/run-time milik MES
  (`mes_routing_ops` / durasi recipe-phase, dibaca via `MesService`).
- Finite vs. infinite capacity adalah toggle per-plan (`SYSCONFIG.config_consts`, customization
  ladder rung 1): infinite untuk MPS/MRP horizon-panjang (§3C/§3D), finite untuk board
  jangka-dekat ini.
- Operation bisa dipindah: drag/drop, ubah resource, ubah tanggal, ubah sequence, split
  operation, gabung batch (proses) — menulis ke `pp_schedule_ops`, tidak pernah ke
  `MES.mes_prod_events` (itu tetap khusus-eksekusi, sesuai Latar Belakang).

**Aturan / logika — batas scheduling PP/MES**
- **PP memiliki *proposal* jadwal** — toggle finite/infinite, penerapan sequencing-rule (§3I),
  optimasi changeover-matrix (§3J), campaign grouping (§3K), dan board Gantt ini. **MES §3Q
  menyempit ke pengurutan dispatch-list real-time di lantai produksi** — menyusun-ulang antrean
  di depan operator seiring kondisi aktual berubah (sebuah machine baru saja down, rush order
  baru saja masuk) — memakai proposal PP sebagai titik awal, bukan menggantikannya. Ini adalah
  satu-satunya titik di mana kedua spesifikasi ini akan overlap; paragraf ini adalah resolusi
  yang dirujuk kedua spesifikasi.
- Merilis sebuah scheduled operation (menetapkan resource/waktu firm-nya) adalah yang sebenarnya
  membuat atau memperbarui `MES.mes_prod_order_hdrs` yang bersangkutan via aksi rilis §3D —
  Detailed Scheduling adalah "saat planned order milik MRP menjadi schedule-committed," bukan
  jalur rilis yang terpisah.

## 3I. Aturan Scheduling (Strategi Dispatch)

**Fungsi / fitur**
- Service strategi yang pluggable (`SchedulingRuleService::apply(strategy, operations[])`),
  bukan algoritma hard-coded: FIFO, Earliest Due Date, Shortest/Longest Processing Time,
  priority (priority customer atau sales-order, dibaca dari `SALES.so_hdrs`), plus strategi
  khusus-manufaktur — minimize setup, group by product family/color/material, campaign
  production (proses), minimize changeover, maximize utilization.
- Tenant memilih strategi default per resource group (customization ladder rung 1/4); planner
  bisa override per run scheduling.

## 3J. Matrix Setup & Changeover (`pp_changeover_matrix`)

**Fungsi / fitur**
- `pp_changeover_matrix` (from_product_or_family, to_product_or_family, resource_type atau
  resource_group_id, changeover_time, cleaning_time).
```text
From \ To      Product A   Product B   Product C
─────────────────────────────────────────────────
Product A         0 min       30 min      60 min
Product B        20 min        0 min      45 min
Product C        40 min       30 min       0 min
```
- Dipakai oleh strategi scheduling "minimize changeover" (§3I) untuk mengurutkan-ulang antrean
  sebuah resource dan meminimalkan **total processing + setup + cleaning time**, bukan sekadar
  memaksimalkan utilization mentah.

## 3K. Kekhususan Perencanaan Manufaktur-Proses

**Fungsi / fitur**
- Campaign scheduling: strategi "group by product family" / "campaign production" (§3I)
  diterapkan khusus pada order proses yang berbagi recipe, sehingga lantai produksi berjalan
  White → White → Yellow → Yellow → Dark alih-alih bergantian — sebuah sequencing rule, bukan
  storage baru.
- Perencanaan batch-size: MRP (§3D) merencanakan langsung terhadap `pp_recipes.batch_size` dan
  expected yield % **milik PP sendiri** — tidak perlu panggilan lintas-modul, karena master data
  Recipe adalah milik PP sendiri (§3D). Versioning recipe (§3D: satu version aktif per product)
  adalah yang menjaga sebuah rencana historis tetap dapat ditelusuri ke version yang menjadi
  dasar perhitungannya, bukan pembacaan data modul lain.
- Kapasitas tank/utility bukan kasus khusus — ia adalah `pp_resources.type = tank` /
  `type = utility` yang mengalir lewat engine capacity §3F/§3G yang sama; "tank harus kosong
  sebelum batch berikutnya" adalah sequence constraint (§3L di bawah), bukan engine
  tank-scheduling yang terpisah.

## 3L. Constraint Produksi (pengecekan yang disurfacekan oleh §3M, bukan konsep storage terpisah)

**Fungsi / fitur** — sebuah service pengecekan-constraint yang berjalan pada waktu MRP (§3D),
Capacity (§3F), dan Detailed Scheduling (§3H), setiap pengecekan membaca modul yang sudah ada
alih-alih menduplikasi datanya:
- **Material** — `InventoryService::checkAvailability()`.
- **Resource** — `AvailabilityService::isFree()` milik Schedule + status equipment MES
  (`MES_SPECS.md` §3D/§3M).
- **Sequence** — aturan predecessor routing/phase, penegakan yang sama yang diterapkan
  shop-floor UI milik MES sendiri (`MES_SPECS.md` §3G "tidak bisa mulai sebelum predecessor
  selesai").
- **Tank** — ketersediaan `pp_resources.type = tank` (§3E/§3G).
- **Quality** — `MES.mes_qc_holds` yang terbuka (`MES_SPECS.md` §3L) memblokir lot/serial dari
  direncanakan sebagai input.
- **Labor** — referensi sertifikasi/skill `HCM` (read-only), memblokir scheduled operation dari
  penugasan resource yang tidak bersertifikat.
- Setiap pengecekan yang gagal menulis sebuah exception §3M; tidak satu pun dari ini adalah
  tabel baru, semuanya adalah pengecekan baca terhadap modul yang sudah memiliki fakta tersebut.

## 3M. Planning Exception Center (`pp_exceptions`, read model)

**Fungsi / fitur**
```text
⚠ 12 material shortages
⚠ 4 capacity overloads
⚠ 3 late production orders
⚠ 2 missing routings
⚠ 5 orders without available resources
⚠ 1 critical machine maintenance conflict
⚠ 7 purchase orders arriving late
```
- Satu baris per kondisi yang terdeteksi (type, severity, referensi `pp_planned_orders`/
  `pp_mps_lines` yang terdampak, referensi material/resource yang terdampak, detected_at, status
  open/acknowledged/resolved). Diregenerasi oleh pengecekan-constraint setiap engine (§3L) dan
  kalkulasi capacity (§3F), tidak dipelihara secara manual.
- Drill-down: **Problem → Affected Order → Affected Material/Resource → Suggested Actions**
  (daftar aksinya adalah opsi yang sama yang sudah dienumerasi aturan overload §3F, digeneralisasi
  per tipe exception).

## 3N. Scenario Planning & What-if (Fase 3)

**Fungsi / fitur**
- `pp_scenarios` (name, base scenario = baseline ketika null, created_by, status). Sebuah
  scenario dibuat dengan menyalin baris-baris relevan dari baseline saat ini dengan
  `scenario_id` baru yang non-null (§5 Isolasi Scenario) — setiap engine §3B–§3M sudah
  membaca/menulis data yang di-scope `scenario_id`, sehingga menjalankan MRP/Capacity/Scheduling
  "di dalam" sebuah scenario tidak butuh perubahan engine, hanya scoping query.
- View perbandingan antar scenario (total produksi, capacity %, jam overtime, jumlah shortage
  material, jumlah order terlambat) — sebuah read model di atas tabel yang sama, difilter
  berdasarkan `scenario_id`.
- **What-if rescheduling** untuk satu order/tanggal ("bisakah kita kirim 2 hari lebih cepat?"):
  menjalankan §3D/§3F/§3H terhadap scenario sekali-pakai yang di-seed dari baseline, melaporkan
  feasibility dan aksi spesifik yang dibutuhkan (overtime, pindah resource, purchase lebih
  awal), bentuk yang sama seperti contoh §19 brief sumber — tidak pernah commit kecuali planner
  secara eksplisit merilis hasilnya.

## 3O. Production Planning Dashboard

**Fungsi / fitur**
```text
Production Plan — September 2026
────────────────────────────────
Demand       125,400 units
Planned      121,000 units
Gap            4,400 units

Capacity        87%
Material        94% available
On-time         93%

CAPACITY
Cutting     █████████░ 89%
Welding     ██████████ 103%  ⚠
Assembly    ████████░░ 82%
Packaging   █████████░ 91%

EXCEPTIONS
⚠ 12 material shortages
⚠ 4 capacity overloads
⚠ 3 late orders
✓ 82 orders ready
```
- Disusun sepenuhnya dari `StatCard`/`Panel`/`DataTable` (`CLAUDE.md` §9D) di atas
  §3B/§3D/§3F/§3M — tidak ada storage khusus-dashboard.

---

# 4. Penyimpanan

> Tabel di bawah schema PostgreSQL `PP` tenant. Referensi eksternal dibaca lewat kontrak service
> modul pemilik, tidak pernah lewat join lintas-schema langsung di kode aplikasi.

**Tabel master / parameter**
- `PP.pp_item_planning_params` (§3A) — mereferensikan `INVENTORY.products`
- `PP.pp_boms`, `PP.pp_bom_lines`, `PP.pp_recipes`, `PP.pp_recipe_ingredients` (§3D) —
  mereferensikan `INVENTORY.products`; direferensikan lintas-schema oleh
  `MES.mes_prod_order_hdrs` (`bom_id`/`recipe_id`) dan `MES.mes_process_phases` (`recipe_id`),
  sesuai `MES_SPECS.md` §3B/§3F
- `PP.pp_resource_groups`, `PP.pp_resource_group_members`, `PP.pp_resources` (§3E)
- `PP.pp_changeover_matrix` (§3J)
- `PP.pp_demand_forecasts` (§3B)

**Tabel perencanaan** (semua membawa `scenario_id` nullable — §5)
- `PP.pp_demand_hdrs`, `PP.pp_demand_lines` (§3B)
- `PP.pp_mps_hdrs`, `PP.pp_mps_lines` (§3C)
- `PP.pp_mrp_runs`, `PP.pp_planned_orders` (§3D) — `subject_type`/`subject_id` saat rilis
  menunjuk ke `MES.mes_prod_order_hdrs`, `PURCHASE.pur_req_hdrs`, atau sebuah transfer request
  `INVENTORY`
- `PP.pp_capacity_plans` (§3F)
- `PP.pp_schedule_ops` (§3H)

**Tabel read-model / lintas-fungsi**
- `PP.pp_exceptions` (§3M)
- `PP.pp_scenarios` (§3N, Fase 3)
- `PP.pp_audit_logs` — append-only, konvensi per-modul yang sama seperti `MES.mes_audit_logs`
  (`MES_SPECS.md` §3U), untuk edit yang sensitif-governance (misalnya sebuah cell MPS yang
  di-firm lalu di-override, sebuah planned order yang dijadwal-ulang manual melewati
  exception-nya).

**Custom fields:** `pp_item_planning_params`, `pp_boms`, `pp_recipes`, `pp_planned_orders`, dan
`pp_mps_lines` terdaftar di `CUSTOMFIELDS.field_defs`, pola yang sama seperti baris
master/transaksi modul Core lainnya.

**Object File** (sesuai `CLAUDE.md` §7B): PP tidak memiliki folder R2 level-atas — lampiran apa
pun (file import forecast, export scenario) lewat **DMS** (`DMS/PP/...`) dengan pointer
`subject_type`/`subject_id`, sama seperti WNE/HCM/Sales/MES.

---

# 5. Catatan Teknis

- **Planning memiliki komposisi material; eksekusi memiliki identitas lantai produksi.**
  BOM/Recipe (komposisi material) adalah master data **milik PP sendiri** (§3D) — MRP
  meng-explode-nya secara langsung, tidak perlu panggilan service. Identitas Work
  Center/Machine/Station, langkah eksekusi Routing/Process-Phase, record Production Order, dan
  event ledger semuanya tetap **milik MES** — PP memanggil kontrak `MesService`
  (`listResources`, `createProductionOrder`) untuk hal-hal itu, dan MES memanggil kontrak
  `PpService` (`getActiveBom`, `getActiveRecipe`, `scaleRecipe`) untuk milik PP, persis batas
  dua-arah yang sudah dipegang `MES_SPECS.md` §5 dengan Inventory/HCM (jangan pernah duplikasi
  tabel modul Core lain). Ini adalah keputusan batas yang paling menentukan di spesifikasi ini,
  direvisi satu kali dari draft awal yang menyimpan BOM/Recipe di MES — lihat §7 Item Terbuka.
- **Isolasi scenario adalah satu kolom nullable, bukan shadow table.** Setiap tabel perencanaan
  di §4 membawa `scenario_id`; `NULL` adalah baseline yang menjadi default setiap query
  non-scenario. Ini menjadikan kebutuhan §11 brief sumber ("scenario tidak boleh memengaruhi
  inventory/order yang sebenarnya") bersifat struktural, bukan sekadar konvensi, dan menghindari
  salinan kedua dari setiap tabel perencanaan per scenario.
- **Batas Planning/Detailed-Scheduling dengan MES §3Q diselesaikan di §3H**: PP mengajukan
  proposal jadwal (toggle finite/infinite, strategi sequencing, optimasi changeover, campaign
  grouping, Gantt); §3Q milik MES sendiri hanya menyusun-ulang antrean dispatch live di depan
  operator seiring kondisi lantai produksi berubah. Baca §3H sebelum menyentuh logika scheduling
  di kedua spesifikasi.
- **Seri penomoran sendiri.** `SYSCONFIG.config_snums`, `snum_code = PP_PLAN_LASTID`, berbeda
  dari `MES_MO_LASTID` milik MES — PP tidak pernah menetapkan nomor order MES, MES tidak pernah
  menetapkan nomor planned-order PP.
- **Frontend**: chrome admin `AppLayout` standar di seluruh bagian (berbeda dari layout Shop
  Floor khusus milik MES) — grid MPS, board Capacity, Gantt, dan Exception Center semuanya
  adalah tool meja-planner, bukan UI sentuh-lantai-produksi. Disusun dari
  `DataTable`/`Panel`/`StatCard`/`StatusBadge` (`CLAUDE.md` §9D).
- **Append-only**: hanya `pp_audit_logs`, disiplin yang sama seperti `MES.mes_audit_logs`/
  `MES.mes_prod_events`; tabel perencanaan milik PP sendiri (`pp_mps_lines`, `pp_planned_orders`,
  dll.) adalah baris mutable biasa karena replanning memang diharapkan menimpa version rencana
  sebelumnya — jejak event/audit hidup di `pp_audit_logs`, bukan di row history.
- **Kode menu/permission**: `menu.perm:PP_*` (misalnya `PP_MPS`, `PP_CAPACITY`, `PP_SCHEDULE`,
  `PP_EXCEPTIONS`) via middleware trustee SYSCONFIG, sama seperti modul lainnya (`CLAUDE.md`
  §4).
- **Plan gating**: middleware `module:PP` + entri `config/tenant_modules.php` — sudah
  ditambahkan; lihat §7.

---

# 6. Urutan Pembangunan

> Urutan yang disarankan untuk mengimplementasikan bagian-bagian modul ini sendiri. Lihat
> `CLAUDE.md` §5 untuk posisi PP dalam urutan pembangunan platform secara keseluruhan
> (bergantung pada Inventory, Sales, Schedule, HCM; diurutkan *sebelum* MES, karena master data
> Process Phases milik MES melakukan FK ke `pp_recipes` milik modul ini — BOM/Recipe adalah
> milik PP sendiri, tidak ada dependensi MES di situ. Identitas equipment/resource dan standar
> timing Routing/Process-Phase (§3E/§3F) tetap bergantung pada MES, sehingga bagian-bagian PP
> itu secara alami tertinggal dari master data equipment milik MES sendiri — lihat §7).

1. **Item Planning Parameters (§3A)** — tidak ada dependent di dalam PP, dibutuhkan sebelum
   demand/MRP bisa menghitung lot size atau safety stock.
2. **Agregasi Demand (§3B)** — bergantung pada akses baca Sales/Inventory; tidak ada yang di
   hilir bisa bekerja tanpa baris demand untuk direncanakan.
3. **Master Data BOM/Recipe & Engine MRP termasuk release (§3D)** — explosion BOM/Recipe hanya
   butuh `INVENTORY.products` dan `InventoryService`; berbeda dari draft awal spesifikasi ini,
   tidak ada **dependensi MES** untuk netting — ini sekarang master data milik PP sendiri.
   Hanya sub-aksi release yang bergantung pada `MesService::createProductionOrder`
   (tipe-production), `PurchaseService` (tipe-purchase), atau `InventoryService`
   (tipe-transfer) — bangun explosion/netting dulu, release belakangan. Ini adalah titik pertama
   PP menghasilkan sesuatu yang actionable (sebuah planned order), jadi prioritaskan ini di atas
   grid UI MPS jika ada tradeoff yang harus dibuat.
4. **MPS (§3C)** — grid yang menghadap-planner di atas data §2/§4; bisa menyusul §3D karena ini
   presentasi plus aksi firm/release pada baris yang sama.
5. **Referensi Resource/Resource Group (§3E) dan Capacity Planning (§3F/§3G)** — bergantung pada
   `MesService::listResources` dan `AvailabilityService` milik Schedule; ini adalah satu-satunya
   irisan PP yang benar-benar menunggu MES (master data Work Center/Machine/Station-nya,
   `MES_SPECS.md` §3D langkah 1), meski PP secara keseluruhan diurutkan sebelum MES di
   `CLAUDE.md` §5 — dependensinya berjalan dua arah pada tingkat sedetail ini, lihat §7.
6. **Planning Exception Center (§3M)** — bergantung pada pengecekan-constraint §3D/§3F (§3L)
   yang sudah punya sesuatu untuk ditandai; bangun pengecekannya inline dengan §3–§5 di atas,
   lalu agregasikan di sini.
7. **Detailed Scheduling (§3H), Aturan Scheduling (§3I), Matrix Changeover (§3J)** — bergantung
   pada §3E/§3F yang sudah ada; ini adalah titik di mana batas MES §3Q (paragraf resolusi §3H)
   harus sudah diselesaikan di kode, bukan hanya di dokumen ini.
8. **Kekhususan manufaktur-proses (§3K)** — lapisan tipis di atas §3D (baca recipe) dan §3I/§3J
   (strategi campaign); dirilis berbarengan dengan model produksi (diskret/proses) mana pun yang
   dibutuhkan tenant nyata pertama, sikap "bangun model mana pun yang dibutuhkan lebih dulu" yang
   sama seperti `MES_SPECS.md` §6 langkah 5.
9. **Dashboard (§3O)** — read model murni, dirilis begitu §3B/§3D/§3F/§3M sudah punya data
   nyata.
10. **Scenario Planning & What-if (§3N)** — Fase 3; membutuhkan setiap engine sebelumnya sudah
    menghormati scoping `scenario_id`, sehingga retrofit-nya mahal — baca catatan
    isolasi-scenario §5 sebelum memulai langkah mana pun sebelumnya jika Fase 3 kemungkinan akan
    dimajukan.

---

# 7. Item Terbuka

- [x] **Registrasi `CLAUDE.md` §4/§5/§7A dan entri `config/tenant_modules.php`** — schema `PP`
      ditambahkan ke daftar otoritatif §4/§7A, entri urutan-pembangunan ditambahkan ke §5, dan
      `PP` dibuka pada plan `full` saja, sikap placeholder yang sama seperti awal mula MES,
      menunggu keputusan tenant-manufaktur yang nyata.
- [x] **Kepemilikan BOM/Recipe — dipindah ke PP.** Menggantikan keputusan awal spesifikasi ini
      (yang menyimpan BOM/Recipe di MES dan membuat PP memanggil
      `MesService::resolveBom`/`resolveRecipe`): komposisi material adalah master data
      perencanaan, sehingga kini hidup di sini (§3D) sebagai `pp_boms`/`pp_recipes`. MES tetap
      memegang Routing/Process-Phase (`MES_SPECS.md` §3E/§3F — urutan langkah eksekusi,
      di-rename dari `mes_recipe_phases`/`mes_recipe_parameters` menjadi `mes_process_phases`/
      `mes_process_parameters` dan kini melakukan FK lintas-schema ke `PP.pp_recipes`) dan
      memanggil `PpService::getActiveBom`/`getActiveRecipe`/`scaleRecipe` untuk data komposisi —
      cerminan dari PP yang memanggil `MesService` untuk identitas equipment. Baik
      `MES_SPECS.md` maupun spesifikasi ini diperbarui bersamaan sehingga tidak ada dokumen yang
      saling bertentangan.
- [x] **Penempatan urutan-pembangunan platform relatif terhadap MES — dibalik.** Sekarang
      BOM/Recipe hidup di sini dan `MES.mes_process_phases` melakukan FK ke `PP.pp_recipes`,
      **PP diurutkan sebelum MES** pada level kasar daftar-modul `CLAUDE.md` §5 (alasan entri
      sebelumnya sudah digantikan — ia bergantung pada kontrak
      `MesService::resolveBom`/`resolveRecipe` yang kini usang). Ini bukan dependensi satu-arah
      yang ketat pada level yang lebih detail, meski begitu: engine Resource/Capacity milik PP
      sendiri (§3E/§3F, langkah 5 §6 spesifikasi ini) tetap menunggu master data Work
      Center/Machine/Station dan standar timing Routing/Process-Phase milik MES. Solo dev
      sebaiknya membaca ini sebagai "bangun Item Params/BOM-Recipe/Demand/MPS/MRP milik PP dan
      identitas Work-Center milik MES dalam urutan mana pun (tidak saling bergantung satu sama
      lain), lalu Routing/Process-Phase milik MES (butuh recipe milik PP) dan
      Resource/Capacity/Scheduling milik PP (butuh equipment milik MES) belakangan," bukan
      sebagai satu urutan linear modul-demi-modul — format satu-baris-per-modul `CLAUDE.md` §5
      tidak bisa mengekspresikan nuansa itu, jadi ia menyatakan penempatan kasar "PP sebelum MES"
      dan catatan ini adalah caveat-nya.
- [ ] **Kontrak `PpService::getActiveBom`/`getActiveRecipe`/`scaleRecipe` dan
      `MesService::listResources`/`createProductionOrder`** — dinamai di sini dan
      disilang-referensikan di `MES_SPECS.md` §3B/§3E/§3F, tapi belum satu pun diimplementasikan.
- [x] **Terjemahan `.id.md`** — dokumen ini sendiri; setiap spesifikasi modul kecuali MES (yang
      paling baru) punya sibling `.id.md` dan kini PP juga.
- [ ] **Constraint solver tingkat lanjut (Fase 3)** — jika beban optimasi gabungan
      material+resource+sequence+tank+quality+labor sebuah tenant nyata ternyata butuh
      library/runtime solver khusus, evaluasi ekstraksi microservice terhadap kriteria
      `CLAUDE.md` §2 pada saat itu; belum ada apa pun hari ini yang memenuhi ambang batas
      tersebut.
