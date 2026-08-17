# Modul AIInsight
## Analitik "Ask Your Data" Bertenaga AI — Modul Bersama Inti (tidak dapat dijual mandiri —
membutuhkan data modul lain agar berguna)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal (Legal hari ini; Property, dan lainnya nanti) dan setiap modul Core di
platform ini (CRM, Accounting, HCM, Inventory, ...) mengumpulkan data terstruktur yang
semakin ingin ditanyakan tenant dalam bahasa biasa alih-alih melalui layar laporan yang
tetap — "case mana yang menunggak," "berapa aging AR kami per klien bulan ini," "berapa
banyak permintaan cuti yang tertunda." Jika dibiarkan tidak diselesaikan secara terpusat,
ini mengulangi persis anti-pola yang dibangun untuk dihindari setiap modul Core lain di
platform ini:

- Setiap modul jika tidak akan membangun "pencarian pintar" atau report-builder ad hoc
  miliknya sendiri — tanpa lapisan keamanan bersama, tanpa kontrol biaya bersama, tanpa UX
  yang konsisten, dan risiko nyata seseorang akhirnya mengawatkan jalur query yang
  bisa-menulis ke satu modul dan tidak ke modul lain.
- Kosakata khusus-vertikal (sebuah "matter" Legal, sebuah "unit" Property) tidak punya
  rumah alami jika AIInsights meng-hardcode logika per-vertikal — itu akan melanggar aturan
  Core-tidak-punya-pengetahuan-tentang-Vertical yang sama (`CLAUDE.md` §2) yang diikuti
  setiap modul Core lain di platform ini.
- Memberi LLM akses baca ke database live sebuah tenant adalah kapabilitas yang benar-benar
  sensitif — tanpa batas-baris keras, statement timeout, jejak audit lengkap, dan postur
  Zero Data Retention sungguhan dengan penyedia model, ini bukan sesuatu yang akan
  dipercaya audiens pembeli legal yang konservatif (brief `DESIGN.md` sendiri), dan tidak
  boleh dikirimkan tanpa guardrail itu dibangun sejak versi pertama, bukan diretrofit
  nanti.

**Kebutuhan klien:**
- **Nol logika khusus-vertikal di dalam modul itu sendiri.** Terminologi dan konteks
  domain sebuah vertikal (misalnya kosakata "case"/"matter"/"deed" milik Legal) dipasok ke
  AIInsights melalui titik ekstensi terdaftar (§3E), tidak pernah di-hardcode — pola
  "Core mendefinisikan kontrak, pemanggil mana pun mengisinya" yang sama yang sudah dipakai
  untuk `SalesOrderRequested` milik Sales (`SALES_SPECS.md` §3I) dan setiap pola
  driver-interface di platform ini.
- Sadar multi-tenant, isolasi DB-per-tenant yang sama seperti setiap modul Core lainnya —
  tanpa kolom `tenant_id`, dan yang krusial, tidak ada jalur query lintas-tenant yang
  mungkin bahkan secara prinsip, karena setiap query dieksekusi terhadap role Postgres
  read-only milik tenant tersebut di dalam database tenant tersebut sendiri (§3B).
- **Read-only, selalu** — tidak ada tool write yang diekspos ke model dalam versi modul ini
  yang mana pun. Ini adalah keputusan produk permanen, bukan batasan v1 yang akan
  dilonggarkan nanti (§2).
- **Tidak dapat dijual mandiri**, tidak seperti kebanyakan modul Core lain di platform ini
  (DMS, Schedule, Inventory, dll.) — AIInsights tidak punya sesuatu yang berguna untuk
  dikatakan tentang DB tenant yang kosong. Ia selalu merupakan add-on untuk tenant yang
  sudah punya data nyata mengalir melalui modul lain.
- **Perjanjian Zero Data Retention (ZDR) dengan Anthropic adalah prasyarat keras untuk
  peluncuran produksi ke tenant mana pun dengan kewajiban kerahasiaan** — yang paling
  segera setiap tenant vertikal-Legal, karena hak istimewa attorney-client melekat pada
  data case yang jika tidak akan diproses modul ini melalui API model pihak ketiga. Modul
  ini tidak boleh diaktifkan untuk tenant berbayar sampai persyaratan ZDR dikonfirmasi ada
  di tempat — lihat §5. Spesifikasi lain yang mereferensikan kebutuhan ini
  (`PURCHASE_SPECS.md` §3L, `INVENTORY_SPECS.md` §2 tier Optimization) menunjuk kembali ke
  bagian ini sebagai sumber kebenaran untuk itu.
- Gating plan/entitlement (SKU add-on "AI Insights" sesuai konvensi plan/feature-flag
  `CLAUDE.md` §4) dengan budget token/query bulanan per-tenant yang ditegakkan di Core —
  baik gate fitur maupun tuas kontrol-biaya utama modul ini, bukan polesan opsional.
- Jejak audit lengkap dari setiap query yang dieksekusi (SQL, tenant, user, timestamp,
  jumlah baris, latency) — jaring pengaman yang membuat "sebuah LLM menyentuh data tenant
  tanpa pengawasan" dapat dipertahankan, dan hal pertama yang ditunjukkan kepada pembeli
  yang skeptis dalam sebuah demo.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first, dan — tidak seperti kebanyakan modul di platform ini —
> satu batasan keras dan permanen yang tidak pernah dilonggarkan bahkan di Future Version:
> **tanpa kapabilitas write, selamanya.**

**MVP**
- **Interaksi Utama (Chat Interface)** — interface bergaya chat tunggal per user tenant
  (§3A).
- **Query Execution Engine** — facade `AIInsightsService::ask()`, role DB read-only
  terlingkup-tenant, batas-baris keras dan statement timeout ditegakkan server-side, tidak
  pernah mempercayai model untuk membatasi diri sendiri (§3B).
- **Konteks Schema & Guardrail** — anotasi schema sehingga model tidak menebak-nebak makna
  kolom, jejak audit query lengkap, dan denylist schema/tabel per tenant (§3C).
- **Gating Plan / Entitlement** — budget token/query bulanan per-tenant, terhubung ke
  mekanisme plan/feature-flag yang sudah ada (§3D).
- **Titik Ekstensi Konteks-Prompt Vertikal** — seam yang memungkinkan Legal (dan vertikal
  mana pun di masa depan) memasok kosakata domain tanpa AIInsights punya kode
  khusus-vertikal (§3E).
- **Perjanjian ZDR ada di tempat sebelum go-live produksi untuk tenant mana pun yang
  sensitif-kerahasiaan** — sebuah gate peluncuran, bukan fitur untuk dibangun, tapi
  diperlakukan dengan keseriusan yang sama seperti item MVP mana pun di atas (§5).

**Future Version (secara eksplisit ditunda — jangan dibangun sekarang)**
- **Kapabilitas write apa pun.** Tidak ditunda karena sulit — ditunda *secara permanen*
  sebagai batas produk. Jika versi masa depan modul ini pernah butuh mengambil sebuah aksi
  (bukan hanya menjawab pertanyaan), aksi itu sebaiknya melalui facade/workflow modul
  terkait sendiri (misalnya WNE) dengan langkah approval manusia, tidak pernah write
  mentah yang diterbitkan oleh model.
- **Insight proaktif/terjadwal** ("email-kan saya scorecard ini setiap Senin") —
  perpanjangan alami begitu loop chat MVP terbukti; akan menggunakan ulang **WNE** untuk
  pengiriman dan **Schedule** untuk kadensi, bukan logika notifikasi/penjadwalan baru.
- **`schema_annotations` yang auto-refresh** via pass LLM terjadwal (v1 adalah auto-draft
  sekali-jalan dari `information_schema` + edit-tangan) — layak dibangun begitu drift
  schema `CUSTOMFIELDS` khusus-tenant cukup umum sehingga upkeep manual menjadi beban
  nyata.
- **Konektor analitik lintas-modul** yang sudah dideskripsikan sebagai *bergantung pada*
  AIInsights Core di bagian Future Version spesifikasi lain — pengadaan berbantuan-AI
  Purchase (`PURCHASE_SPECS.md` §3L), AI Forecasting/Slotting/Anomaly Detection Inventory
  (`INVENTORY_SPECS.md` §2 tier Optimization), HR Analytics HCM (`HCM_SPECS.md` §3O),
  drill-down BI Performance (`PERFORMANCE_SPECS.md` §2) — semuanya dibangun *di atas*
  modul ini begitu ia ada; tidak satu pun dari mereka menduplikasinya, dan tidak satu pun
  dibangun di sini.
- **Interface suara / tool chaining agentic multi-turn** di luar satu query per giliran
  percakapan.

# 3. Form / Engine

## 3A. Interaksi Utama (Chat Interface)

- Interface bergaya chat tunggal, terlingkup-tenant, menggunakan ulang primitif Card/Data
  Table `DESIGN.md` untuk merender hasil — sebuah hasil query menjadi Data Table dengan
  Status Rail jika ini data bersifat-status (misalnya "case yang menunggak"), jika tidak
  tabel biasa atau chart, sesuai component library bersama yang sudah dipakai setiap modul
  lain.
- **Riwayat percakapan dipersistensikan per user, bukan hanya per tenant**
  (`AIINSIGHT.conversations`, `AIINSIGHT.messages`) — thread pertanyaan seorang paralegal
  tidak boleh pernah bocor ke milik seorang partner, meskipun keduanya milik tenant yang
  sama.
- Setiap giliran asisten yang menjalankan query menyimpan SQL sebenarnya yang dieksekusi
  di samping jawabannya (`AIINSIGHT.query_audit`, §3C) — ini penting begitu sebuah LLM
  menyentuh data tenant tanpa pengawasan; jawaban tidak pernah dipercaya tanpa jejak yang
  dapat direkonstruksi tentang apa yang sebenarnya berjalan.
- Snapshot penggunaan/entitlement terlihat di samping chat (token/query tersisa bulan ini,
  per §3D) — sehingga seorang user tidak dikejutkan oleh pemutusan budget di tengah
  percakapan.

**Aturan / logika**
- Sebuah percakapan milik tepat satu user; tidak ada konsep percakapan
  bersama/tim di MVP — sesuai perbedaan "pekerjaan saya" vs. "pekerjaan tim" yang sudah
  ditetapkan di tempat lain (misalnya My Approvals milik WNE, §3H `WNE_SPECS.md`),
  diterapkan di sini pada bentuk paling sederhananya.

## 3B. Query Execution Engine

- `AIInsightsService::ask($tenantUser, $question)` — facade yang dipanggil UI mana pun;
  satu-satunya titik integrasi yang didukung untuk modul ini (tidak ada panggilan
  model-API langsung dari tempat lain di codebase).
- Secara internal: membangun system prompt dari konteks schema (§3C) ditambah konteks
  prompt vertikal yang terdaftar mana pun (§3E), memanggil endpoint Messages API Claude
  dengan satu tool `run_readonly_query` yang diekspos ke model.
- Tool tersebut dieksekusi terhadap **role Postgres read-only per-tenant**, dengan
  batas-baris keras (misalnya `LIMIT 500`) dan statement timeout yang disuntikkan
  server-side pada setiap panggilan — model tidak pernah dipercaya untuk membatasi diri
  sendiri; kedua batasan ditegakkan di level role-DB/koneksi, tidak hanya di prompt.
- **Tidak ada tool write yang diekspos, dalam konfigurasi apa pun, untuk tenant mana pun.**
  Ini diperiksa di level pendaftaran-tool, tidak dibiarkan sebagai instruksi prompt yang
  bisa dibujuk keluar oleh model.
- Pemilihan model: **Haiku secara default**, meningkat ke **Sonnet** untuk query yang
  ditandai model default sebagai terlalu kompleks untuk dijawab dengan percaya diri
  (misalnya join multi-step lintas beberapa schema) — keputusan kontrol-biaya,
  menggunakan prompt caching pada bagian konteks-schema dari system prompt sehingga
  pertanyaan berulang terhadap schema tenant yang sama tidak membayar ulang biaya itu
  setiap giliran.

**Aturan / logika**
- Setiap query terlingkup ke tepat satu batas DB-per-tenant milik satu tenant secara
  konstruksi — role read-only itu sendiri tidak punya visibilitas ke database tenant lain
  mana pun, sehingga tidak ada jalur query lintas-tenant yang perlu dipertahankan di
  lapisan aplikasi; ini secara struktural mustahil, jaminan DB-per-tenant yang sama yang
  diandalkan setiap modul lain di platform ini.
- Sebuah query yang akan melebihi batas-baris atau timeout dipotong/dibatalkan dengan
  pesan yang jelas ditampilkan kembali melalui chat (sesuai panduan suara `DESIGN.md`),
  tidak pernah diam-diam mengembalikan data parsial yang dibingkai sebagai lengkap.

## 3C. Konteks Schema & Guardrail

- `AIINSIGHT.schema_annotations` — deskripsi manusia tabel/kolom, auto-draft sekali dari
  `information_schema` ditambah satu kali pass LLM, lalu diedit tangan oleh admin tenant
  atau Simon. Inilah yang menjaga model dari menebak-nebak apa arti `amt_net`, masalah
  yang sama yang diselesaikan tool dokumentasi-schema seperti Contextflo — diselesaikan
  sekali, secara terpusat, alih-alih per query.
- `AIINSIGHT.query_audit` — setiap statement SQL yang dieksekusi, tenant, user, timestamp,
  jumlah baris, latency. Ini adalah baik jaring pengaman (§3A) maupun dataset
  biaya-penggunaan untuk menyetel aturan eskalasi Haiku/Sonnet (§3B).
- **Denylist/allowlist schema yang bisa disentuh tool, per tenant** — misalnya tenant yang
  belum pernah memvalidasi penyimpanan mentah `CUSTOMFIELDS` untuk konten sensitif bisa
  mengecualikannya sepenuhnya, dapat di-override per tenant, tidak pernah asumsi
  seluruh-platform.

**Aturan / logika**
- Sebuah schema/tabel tanpa entri `schema_annotations` tetap dapat di-query (model jatuh
  kembali ke nama kolom mentah) tapi ditandai dalam query audit sebagai "unannotated" —
  sinyal visibilitas bagi Simon untuk memprioritaskan pekerjaan dokumentasi, bukan blok
  keras.

## 3D. Gating Plan / Entitlement & Dashboard Penggunaan

- Terhubung ke mekanisme plan/feature-flag platform yang sudah ada (`CLAUDE.md` §4:
  `tenants.plan` + `config/tenant_modules.php` + `TenantFeatureService` + middleware
  `module:CODE`) — "AI Insights" adalah SKU add-on di atas plan dasar tenant, bukan
  mekanisme gating yang dibangun terpisah.
- `AIINSIGHT.usage_counters` — jumlah token/query bulanan berjalan per tenant, diperiksa
  sebelum setiap panggilan `ask()`; tenant yang melebihi budget mendapat pesan in-chat
  yang jelas (sesuai suara `DESIGN.md`: *"Anda sudah mencapai batas query AI Insights
  bulan ini. Batas ini akan reset pada [tanggal], atau Anda bisa meminta batas yang lebih
  tinggi dari admin Anda."*) alih-alih kegagalan diam-diam.
- **Tampilan penggunaan menghadap-admin**: penggunaan bulan-berjalan vs. budget, tren
  sederhana (query/token per minggu), dan cakupan `schema_annotations` milik tenant —
  ditampilkan ke admin tenant, bukan setiap user, mencerminkan postur "admin melihat lebih
  banyak daripada user umum" yang sudah ditetapkan Payroll (§3-Admin,
  `PAYROLL_SPECS.md`) dan HCM (§3H) untuk data yang sensitif/relevan-biaya.

**Aturan / logika**
- Penegakan budget terjadi sebelum panggilan API Claude, bukan sesudahnya — sebuah
  request yang akan melebihi budget ditolak di depan, tidak pernah dieksekusi sebagian
  dan tetap ditagih.

## 3E. Titik Ekstensi Konteks-Prompt Vertikal

**Tujuan:** memungkinkan modul vertikal (Legal hari ini; Property nanti) mengajarkan
AIInsights kosakata dan framing-nya — "sebuah 'matter' adalah keterlibatan klien," "sebuah
'deed' menjadi immutable begitu ditandatangani" — tanpa AIInsights pernah punya kode
khusus-Legal, pola driver-interface yang sama yang sudah ditetapkan di seluruh platform
(`ChannelDriverInterface` di WNE, `ConferenceDriverInterface` di Schedule,
`OcrDriverInterface` di DMS, `CostingStrategyInterface` di Inventory).

- `VerticalPromptContextInterface`: `getContextFragment(tenantId): string` — sebuah modul
  vertikal mendaftarkan implementasi (misalnya `LegalPromptContextProvider`) yang
  mengembalikan blok singkat teks kosakata/framing domain, ditambahkan ke system prompt
  (§3B) di samping konteks schema (§3C) setiap kali vertikal tersebut diaktifkan untuk
  tenant.
- Pendaftaran bersifat aditif dan opsional — tenant tanpa modul vertikal yang diaktifkan
  (hanya modul Core) mendapat AIInsights dengan nol framing vertikal, dan tetap berfungsi
  dengan benar terhadap schema Core (CRM, Accounting, HCM, ...).
- AIInsights punya nol dependensi compile-time pada class modul Vertical mana pun — ia
  hanya tahu tentang kontrak `VerticalPromptContextInterface`, diresolusi via modul yang
  diaktifkan tenant (`config/tenant_modules.php`), disiplin
  Core-tidak-punya-pengetahuan-tentang-Vertical yang sama yang dituntut `CLAUDE.md` §2 di
  tempat lain mana pun.

**Aturan / logika**
- Sebuah fragmen konteks adalah teks biasa, tidak dapat dieksekusi — ia tidak bisa
  memperluas akses tool, denylist, atau batas-baris model; ia hanya membentuk bagaimana
  model *menafsirkan* pertanyaan dan hasil, menjaga batas keamanan (§3B/§3C) sepenuhnya di
  luar kendali vertikal tersebut.

# 4. Penyimpanan

**Database (schema `AIINSIGHT`, DB tenant — konsisten dengan `CLAUDE.md` §7A):**

- `AIINSIGHT.conversations` — per user, per tenant.
- `AIINSIGHT.messages` — per percakapan; giliran asisten menyimpan referensi SQL yang
  dieksekusi (`query_audit_id`) di samping jawaban yang dirender.
- `AIINSIGHT.query_audit` — append-only; setiap statement SQL yang dieksekusi, tenant,
  user, timestamp, jumlah baris, latency. Tidak ada update/delete di lapisan aplikasi,
  aturan integritas-audit yang sama seperti `DMS.access_logs`.
- `AIINSIGHT.schema_annotations` — deskripsi manusia tabel/kolom, dapat diedit tenant.
- `AIINSIGHT.usage_counters` — jumlah token/query bulanan berjalan per tenant, untuk
  gating entitlement (§3D) dan pelacakan biaya.
- `AIINSIGHT.schema_access_rules` — denylist atau allowlist schema/tabel per-tenant (§3C).

**Penyimpanan file objek:** tidak dibutuhkan untuk MVP — modul ini menghasilkan jawaban
teks dan hasil tabular, bukan dokumen. Jika fitur "ekspor analisis ini sebagai PDF/Excel"
masa depan pernah dikirimkan, ia menggunakan ulang pola skill `pdf`/`xlsx` yang sudah ada
dan, jika ekspor perlu dipersistensikan, facade attachment milik **DMS** — tidak ada kode
penyimpanan paralel, disiplin penggunaan-ulang yang sama seperti setiap modul lain di
platform ini.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, postur monolitik-modular yang sama seperti WNE/DMS/CRM/
Schedule, di `app/Modules/AIInsight/`. Tidak ada ekstraksi microservice — ini adalah
lapisan orkestrasi tipis di sekitar endpoint Messages API Claude (tool use), bukan
runtime-berbeda atau beban kerja scaling-independen sesuai kriteria ekstraksi
`CLAUDE.md` §2. Panggilan API Claude itu sendiri adalah satu-satunya dependensi eksternal
"berat," dan itu sudah berupa panggilan HTTP, bukan beban kerja komputasi yang perlu
di-host platform ini.

- **Facade internal** — `AIInsightsService::ask($tenantUser, $question)` — satu-satunya
  titik integrasi yang didukung; tidak ada modul lain yang memanggil API Claude langsung
  untuk query data-tenant.
- **Mengonsumsi** mekanisme plan/feature-flag platform (`CLAUDE.md` §4) untuk gating
  entitlement (§3D), dan implementasi `VerticalPromptContextInterface` setiap vertikal
  yang diaktifkan (§3E) untuk framing domain — keduanya dependensi read-only, opsional;
  AIInsights berfungsi (terhadap schema Core saja) bahkan dengan nol vertikal terinstal.
- **Penggunaan model:** endpoint Messages API Claude + tool use (`run_readonly_query`);
  Haiku default, eskalasi Sonnet untuk query kompleks; prompt caching pada konteks schema
  untuk efisiensi biaya (§3B). Tidak ada fine-tuning, tidak ada state model
  per-tenant yang persisten — setiap panggilan bersifat stateless dari sudut pandang
  model, dengan semua konteks (schema, riwayat percakapan, framing vertikal) dirakit
  segar per request.

**Zero Data Retention — satu-satunya prasyarat tidak-dapat-dinegosiasikan untuk
peluncuran produksi.** Modul ini mengirim data tenant (konteks schema, dan apa pun yang
ditampilkan hasil query) ke API Claude pada setiap giliran. Untuk tenant mana pun dengan
kewajiban kerahasiaan — tenant vertikal-legal pertama dan terutama, mengingat hak
istimewa attorney-client — modul ini **tidak boleh diaktifkan dalam produksi** sampai
perjanjian Zero Data Retention dengan Anthropic dikonfirmasi ada di tempat untuk
penggunaan API tenant tersebut. Ini adalah gate go-live yang ditegakkan di level
feature-flag (`config/tenant_modules.php`), bukan konfigurasi yang bisa
di-self-service admin tenant, dan itulah alasan modul ini terdaftar sebagai "on the
horizon" alih-alih dapat dikirimkan hari ini (lihat catatan "on the horizon" proyek
sendiri). Spesifikasi lain yang mereferensikan "kebutuhan ZDR yang sama yang dicatat
[untuk] AIInsights" (`PURCHASE_SPECS.md` §3L, `INVENTORY_SPECS.md` §2 tier Optimization)
menunjuk kembali ke paragraf ini — inilah bagian yang benar-benar mendefinisikannya, dan
itu tidak boleh dianggap terpenuhi oleh apa pun yang kurang dari perjanjian yang
dikonfirmasi.

**Batas lingkup MVP (eksplisit tentang apa yang ditunda):**
- Tidak ada kapabilitas write sekarang atau selamanya (§2) — satu-satunya batas permanen
  di modul ini, tidak seperti setiap pembagian MVP-vs-Future-Version lain di platform
  ini.
- `schema_annotations` adalah auto-draft sekali-jalan + edit-tangan di v1, bukan job
  refresh terjadwal — dapat diterima karena perubahan schema jarang dan disengaja
  (migration), bukan sesuatu yang drift diam-diam.
- Tidak ada pengiriman proaktif/terjadwal (§2) — setiap insight di v1 ditarik oleh user
  yang bertanya, tidak pernah didorong.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3C (schema `schema_annotations`
+ `query_audit` — model data guardrail yang menjadi tempat bergantung semua hal lain) →
3B (Query Execution Engine terhadap satu tenant uji, dengan batas-baris/timeout ditegakkan
di level role-DB sejak hari pertama, tidak ditambah nanti) → 3A (chat interface) → 3D
(gating entitlement, dikawatkan ke mekanisme plan/feature-flag yang sudah ada) → 3E
(`VerticalPromptContextInterface`, dengan Legal sebagai implementasi nyata pertama) →
**konfirmasi perjanjian ZDR ada di tempat** → ship ke tenant pertama.

**Catatan kelayakan jual (marketability)**
- "Ask your data" adalah diferensiator kuat dan mudah-didemokan untuk pembeli konservatif
  begitu kepercayaan terbangun — jejak audit (§3A/§3C) dan postur ZDR di atas adalah yang
  membuatnya kredibel untuk dipimpin dalam percakapan penjualan vertikal-legal, bukan
  sekadar fitur novelty.
- Karena bagian Future Version modul lain mana pun (Purchase, Inventory, HCM, Performance)
  sudah menunjuk ke modul ini sebagai tempat fitur AI mereka akhirnya akan hidup,
  AIInsights adalah titik-leverage seluruh-platform — satu investasi di sini terbayar di
  seluruh modul yang saat ini punya item "AI-assisted X" yang ditunda ke Future Version.
- Batas read-only, tidak-pernah-write-secara-permanen (§2) itu sendiri adalah poin jual
  untuk pembeli yang menghindari-risiko, bukan sekadar tindakan keamanan engineering —
  layak dinyatakan secara eksplisit dalam percakapan penjualan ("AI bisa menjawab
  pertanyaan tentang data Anda; ia tidak pernah bisa mengubah apa pun").
