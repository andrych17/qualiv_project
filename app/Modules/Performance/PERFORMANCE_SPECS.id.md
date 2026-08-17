# Modul Performance
## Engine Manajemen Kinerja — Modul Core Bersama (mampu berdiri sendiri/standalone)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap organisasi pada akhirnya perlu menjawab tiga pertanyaan: *di mana kita berencana berada,
di mana kita sebenarnya berada, dan ke mana kita menuju?* Hari ini itu tersebar di spreadsheet —
workbook anggaran, tracker KPI terpisah, deck slide OKR yang di-refresh manual tiap kuartal,
forecast yang tidak pernah direkonsiliasi terhadap aktual. Jika dibiarkan tidak diselesaikan
secara terpusat, ini mengulangi anti-pola yang sama yang berusaha dicegah oleh setiap modul Core
lain di platform ini:

- Setiap departemen/vertikal menciptakan tracking "bagaimana kinerja kita" versinya sendiri —
  tidak ada definisi metrik bersama, tidak ada rollup yang bisa dibandingkan lintas tim, tidak
  ada satu sumber kebenaran untuk "apa yang dianggap on-target."
- Target, anggaran, dan OKR berada di tempat berbeda-beda tanpa cara umum untuk melihat varians
  (rencana vs. aktual) sekilas.
- Tidak ada tempat untuk menggulung kinerja individu/tim menjadi scorecard perusahaan, atau
  meng-cascade objective tingkat perusahaan turun ke objective tim/individu.
- Tidak ada notion bersama tentang "metrik ini melanggar targetnya" yang bisa ditindaklanjuti
  modul lain (WNE) — setiap modul yang ingin punya alert kinerja menemukan-ulang pengecekan
  threshold sendiri.
- Pengakuan (mencapai target, menyelesaikan OKR) tidak punya system of record — itu tribal
  knowledge atau pesan Slack, bukan sesuatu yang bisa dilaporkan.

**Kebutuhan klien:**
- Sadar multi-tenant, postur yang sama seperti setiap modul Core lain.
- Harus bekerja **standalone** — seorang tenant bisa menjalankan Performance tanpa modul lain
  ter-install (entri manual untuk aktual), dan harus juga bekerja **melekat** ke record modul
  mana pun (KPI jam-tertagih sebuah kasus Legal, target revenue sebuah tim Sales, tingkat
  okupansi sebuah portofolio Property) via seam polimorfik yang sama yang dipakai di tempat lain
  di codebase ini.
- KPI dan OKR harus mendukung **banyak level** — perusahaan, divisi, departemen, tim, individu —
  dengan kemampuan melihat bagaimana metrik/objective tingkat lebih rendah menggulung ke dalam
  (atau selaras dengan) yang tingkat lebih tinggi.
- Budgeting, Target, dan Forecast semuanya harus bisa dibandingkan terhadap Aktual melalui satu
  engine **Analisis Varians** bersama — logika varians tidak boleh diimplementasikan ulang per
  fitur.
- **Budgeting milik Performance secara sengaja bukan duplikat dari Budgeting milik Accounting**
  (`ACCOUNTING_SPECS.md` §3J) — keduanya menjawab pertanyaan yang berbeda. Anggaran Accounting
  berskop akun-GL × cost-center dan memasok pelaporan statutori/finansial, dengan aktual
  bersumber langsung dari jurnal yang sudah diposting (kelas finance, presisi-audit). Anggaran
  Performance berbasis subjek (perusahaan/departemen/tim/individu, atau record vertikal mana
  pun) dan berbasis kategori (label bebas seperti "Marketing," tidak harus satu akun GL
  tunggal), dirancang untuk duduk berdampingan dengan KPI dan OKR dalam satu Scorecard — tampilan
  manajemen/board, bukan GL kedua. Saat Accounting ter-install, Variance Engine Performance secara
  opsional bisa meresolusi "aktual" kategori anggaran dari data GL Accounting (§3B/§3G) alih-alih
  mewajibkan entri manual — tapi Performance tidak pernah menjadi ledger kedua, disiplin "satu
  ledger, banyak pemohon" yang sama yang dipakai di tempat lain di platform ini.
- Harus terintegrasi dengan WNE untuk notifikasi pelanggaran threshold dan workflow approval
  opsional (misalnya approval anggaran) — Performance tidak membangun logika notifikasi atau
  approval sendiri.
- Harus terintegrasi dengan Schedule untuk kadensi review periodik (misalnya "check-in OKR jatuh
  tempo mingguan") — Performance tidak membangun logika penjadwalan sendiri.
- Harus dapat dijual sebagai add-on standalone (cerita "Executive Dashboard") maupun sebagai
  upsell alami begitu tenant sudah punya data mengalir melalui modul Core lain.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — kirim sesuatu yang bisa didemokan/dijual dengan cepat,
> tunda pekerjaan BI/statistik/gamifikasi berat.

**MVP (implementasi cepat)**
- **Pustaka KPI & Penugasan Multi-Level.** Pustaka metrik KPI yang didefinisikan tenant
  (`perf.kpi_definitions` — name, unit, direction "higher is better" / "lower is better",
  perspective). KPI mana pun bisa *ditugaskan* ke subjek mana pun di level mana pun (perusahaan,
  departemen, tim, individu, atau record vertikal seperti kasus Legal) via pola polimorfik
  standar `subject_type` / `subject_id` yang sudah dipakai WNE/DMS/CRM/Schedule — "multi-level"
  dicapai dengan menandai subjek, bukan dengan membangun engine hierarki terpisah.
- **Target.** Form penetapan target yang ringan: pilih KPI + subjek + periode, set nilai target
  (dan opsional nilai stretch). Menggunakan ulang model periode yang sama seperti
  Budgeting/Forecast.
- **Penangkapan Aktual KPI.** MVP = entri manual (form sederhana "masukkan angka periode ini"),
  satu baris per kombinasi KPI/subjek/periode. Dirancang agar feed otomatis di masa depan
  (misalnya menarik angka nyata dari CRM/DMS/Legal) hanyalah writer lain ke tabel `kpi_values`
  yang sama — tidak perlu perubahan skema nanti.
- **OKR (multi-level, selaras).** Objective dengan Key Result di bawahnya (setiap KR punya nilai
  start/current/target atau flag boolean/milestone). Sebuah Objective bisa opsional menunjuk ke
  `parent_okr_id` — pola self-referencing yang sama yang dipakai CRM untuk `parent_partner_id` —
  sehingga OKR Tim bisa selaras di bawah OKR Departemen di bawah OKR Perusahaan, tanpa tabel
  hierarki kaku yang dipaksakan.
- **Budgeting.** Anggaran header + baris sederhana (per kategori, per potongan periode —
  biasanya bulanan dalam satu tahun fiskal), alur status `draft → submitted → approved → locked`.
  Approval bisa opsional dialihkan melalui Workflow WNE
  (`workflow_code = performance.budget_approval`) — Performance tidak mengimplementasikan logika
  approval itu sendiri. Kategori anggaran bisa opsional dipetakan ke satu atau lebih akun GL
  Accounting (§3B) sehingga angka "aktual"-nya bersumber dari transaksi yang benar-benar
  diposting saat Accounting ter-install, alih-alih selalu mewajibkan entri manual — lihat §3B/§3G
  dan pemisahan yang dijelaskan di §1.
- **Forecast.** Forecast bergulir hanyalah seri baris "projected" kedua yang bisa diedit per
  potongan periode, di-versi agar riwayat tidak hilang saat forecast direvisi — bentuk yang sama
  seperti baris Budget, secara sengaja, sehingga Analisis Varians bisa memperlakukan
  Budget/Target/Forecast secara seragam terhadap Aktual.
- **Engine Analisis Varians.** Satu service yang bisa digunakan ulang — diberi subjek +
  baris KPI/anggaran + periode — mengembalikan `aktual vs. rencana` (rencana adalah Target,
  Budget, atau Forecast, mana pun yang berlaku), varians absolut, varians persen, dan
  klasifikasi status (`on-track` / `warning` / `breach`) menggunakan arah "lebih tinggi/lebih
  rendah lebih baik" milik KPI. Engine tunggal ini menggerakkan Dashboard, Scorecard, dan
  notifikasi pelanggaran WNE — tidak pernah diimplementasikan ulang per fitur.
- **Scorecard.** Set KPI/OKR yang dapat dikonfigurasi, dikelompokkan berdasarkan **perspective**
  (Financial, Customer, Process, Learning & Growth — dapat diedit tenant, gaya
  Balanced-Scorecard klasik) untuk subjek + periode tertentu, setiap item diberi bobot,
  menghasilkan skor terbobot sederhana dan Status Rail per item dan per perspective.
- **Achievements.** Log pengakuan yang ringan — sebuah badge/definisi (misalnya "Target Hit,"
  "OKR Completed," "3 Quarters On-Track Streak") secara otomatis dicatat saat Variance Engine
  atau penyelesaian OKR melewati aturan yang ditentukan, atau diberikan manual oleh manajer.
  Tidak ada leaderboard/UI gamifikasi di MVP — hanya record achievement faktual yang bisa
  diaudit dan dibaca modul lain (atau modul HR di masa depan).
- **Dashboard.** Satu layar rollup: scorecard, hitungan status KPI/OKR, ringkasan anggaran-vs-
  aktual, dan "item yang perlu perhatian" (apa pun yang ditandai `warning` atau `breach` oleh
  Variance Engine), dapat difilter berdasarkan periode dan subjek/level.

**Future Version (pasca-peluncuran, begitu ada volume penggunaan/revenue nyata yang menjustifikasi
pembangunannya)**
- **Konektor data KPI otomatis** — penarikan terjadwal dari modul lain/sistem eksternal
  (misalnya otomatis menghitung KPI "utilisasi tertagih" dari entri waktu DMS/Legal) alih-alih
  entri manual. Setiap konektor bersifat aditif per KPI, tidak mengubah skema inti.
- **Forecasting statistik/prediktif** — pembuatan forecast trend-line, seasonality, atau
  berbantuan-ML, melampaui entri forecast manual/bergulir MVP.
- **Budgeting multi-mata-uang** dengan konversi FX untuk rollup terkonsolidasi.
- **Matematika auto-rollup OKR bertingkat** — otomatis menghitung progress Key Result induk
  sebagai fungsi terbobot dari progress anak-anaknya (MVP mewajibkan setiap level diperbarui
  secara independen, yang lebih sederhana dan menghindari satu kelas kasus tepi agregasi
  seluruhnya).
- **Perencanaan what-if / skenario** — mengklon forecast/anggaran menjadi skenario alternatif
  untuk perbandingan.
- **Lapisan gamifikasi** — leaderboard, poin, badge streak dengan sentuhan visual (Achievements
  MVP tetap log faktual biasa, bukan lapisan game).
- **BI drill-down / pembuat laporan kustom** — pivoting ad hoc melampaui tampilan Dashboard dan
  Scorecard yang tetap. (Catatan: ini juga cocok alami untuk **AIInsights Core**, fitur "ask
  your data" menghadap-tenant yang sudah dirancang — tabel terstruktur Performance adalah
  konteks skema kandidat yang baik untuknya begitu keduanya rilis.)
- **Impor benchmark eksternal** (benchmark KPI industri untuk perbandingan).

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Tampilan rollup: scorecard perspective, hitungan status KPI/OKR (on-track / warning /
  breach), strip ringkasan anggaran-vs-aktual, daftar "perlu perhatian" (semua yang ditandai
  non-`on-track` oleh Variance Engine), feed Achievement terbaru.
- Filter: periode, subjek/level (perusahaan / departemen / tim / individu / record vertikal
  tertentu), perspective.

**Layout**
- Atas: kartu ringkasan — Overall Scorecard %, Budget Variance %, OKR On-Track (jumlah/total),
  Open Breaches.
- Utama: bertab — "Scorecards" | "KPIs" | "OKRs" | "Budget vs Actual" | "Needs Attention".
- Setiap baris/kartu menggunakan **Status Rail** bersama (sesuai `DESIGN.md`) yang diwarnai
  berdasarkan klasifikasi Variance Engine — motif visual yang sama yang sudah dipakai di
  Scheduler/Workflows/Notifications/CRM, sehingga Performance terbaca sebagai bagian dari
  platform yang sama.
- Klik baris membuka drawer: detail, tren (periode-demi-periode), Achievement terkait.

**Aturan / logika**
- Otomatis terlingkup-tenant (batas DB-per-tenant, tanpa kolom `tenant_id`).
- "Needs attention" selalu menampilkan breach lebih dulu terlepas dari urutan yang dipilih,
  mencerminkan aturan SLA-breach-lebih-dulu yang sudah ditetapkan di dashboard CRM.

## 3B. Budgeting (Entry)

- **Header anggaran** (`perf.budget_hdrs`): subjek (`subject_type`/`subject_id`), name, periode
  fiskal (year, atau year+quarter), status (`draft → submitted → approved → locked`), owner.
- **Baris anggaran** (`perf.budget_lines`): `budget_id`, category (lookup bebas, misalnya
  "Payroll," "Marketing"), potongan periode (biasanya bulan), `amount_planned`.
- **Sumber aktual (bagaimana "aktual" ditentukan untuk baris anggaran):**
  - **Jika Accounting ter-install dan kategori dipetakan**
    (`perf.budget_category_accounts` — dapat diedit tenant, category → satu atau lebih
    `ACCOUNTING.accounts.id`, opsional berskop company): Variance Engine (§3G) membaca
    pengeluaran aktual untuk periode baris tersebut langsung dari Accounting via
    `AccountingService::getAccountBalance(...)` (dijumlahkan di seluruh akun yang dipetakan) —
    tanpa entri manual, tanpa drift antara "apa yang ditampilkan Performance" dan "apa yang
    dikatakan buku."
  - **Jika tidak** (Accounting tidak ada, atau kategori tidak dipetakan): aktual dimasukkan
    manual per baris/periode (`perf.budget_actuals` — bentuk yang sama seperti Penangkapan
    Aktual KPI, §3D, menggunakan ulang pola itu alih-alih menciptakan pola kedua), sama seperti
    setiap engine manual-MVP lain di modul ini.
  - Mode sumber-aktual sebuah baris anggaran terlihat pada baris itu sendiri (mapped/GL-sourced
    vs. manual) sehingga viewer tidak pernah salah mengira angka yang dimasukkan manual sebagai
    angka yang sudah direkonsiliasi.
- **Saat submit:** opsional memicu `WorkflowRequested` (`workflow_code =
  performance.budget_approval`) ke WNE jika tenant menginginkan sign-off manajer — Performance
  tidak mengimplementasikan logika approval itu sendiri, pola reuse yang sama seperti integrasi
  WNE modul lain mana pun.
- **Locking:** anggaran yang `approved` hanya bisa diedit dengan membuat versi baru (riwayat
  append-only untuk audit — prinsip "jangan pernah menimpa secara diam-diam" yang sama yang
  dipakai DMS untuk versi dokumen), bukan dengan memutasi baris yang terkunci.

**Aturan / logika**
- Memetakan kategori ke akun GL bersifat opsional dan aditif — tenant bisa menjalankan
  Budgeting Performance sepenuhnya pada aktual manual (bentuk MVP aslinya) dan mengadopsi aktual
  bersumber-GL nanti, kategori demi kategori, tanpa perubahan skema dan tanpa gangguan pada
  anggaran yang sudah dimasukkan.

## 3C. Setup Target & KPI (Entry)

- **Definisi KPI** (`perf.kpi_definitions`): name, unit (number/percent/currency/ratio),
  direction (`higher_is_better` / `lower_is_better`), perspective (FK ke `perf.perspectives`),
  description, active flag. Daftar master yang dapat diedit tenant, pola yang sama seperti
  `partner_role_types` milik CRM.
- **Penugasan target** (`perf.targets`): `kpi_id`, `subject_type`/`subject_id`, period,
  `target_value`, `stretch_value` (opsional), notes. Satu KPI bisa punya banyak target di
  banyak subjek/periode — inilah *sebenarnya* mekanisme "multi-level": menugaskan KPI
  "Revenue" yang sama ke subjek Company dan, secara terpisah, ke setiap subjek Department,
  masing-masing dengan targetnya sendiri.

**Aturan / logika**
- Sebuah KPI harus `active` untuk menerima penugasan target baru, tapi target/nilai historis
  pada KPI yang dinonaktifkan tetap terlihat untuk pelaporan.

## 3D. Penangkapan Aktual KPI (Entry — MVP manual; Future: otomatis)

- Form sederhana: pilih KPI + subjek + periode → masukkan `actual_value`. Satu baris per
  kombinasi di `perf.kpi_values`, dengan `source` (`manual` di MVP; nilai cadangan untuk
  konektor masa depan), entered_by, entered_at.
- **Saat disimpan:** memicu event `KpiValueRecorded` → Variance Engine (3G) mengevaluasi ulang
  status untuk KPI/subjek/periode itu → jika melintasi ke `warning`/`breach`, memicu
  `NotificationRequested` ke WNE sesuai aturan routing tenant — Performance tidak pernah
  mengirim notifikasi secara langsung.

## 3E. Manajemen OKR (multi-level, selaras)

- **Objective** (`perf.okr_objectives`): `cycle_id` (FK `perf.okr_cycles` — misalnya "2026 Q3"),
  `subject_type`/`subject_id` (siapa pemiliknya — perusahaan/departemen/tim/individu), teks
  objective, `parent_okr_id` (nullable, self-referencing — keselarasan ke Objective tingkat
  lebih tinggi, mekanisme yang sama seperti `parent_partner_id` milik CRM), status (`on_track` /
  `at_risk` / `off_track` / `completed`).
- **Key Result** (`perf.okr_key_results`): `okr_id`, description, `metric_type`
  (numeric/percent/boolean/milestone), `start_value`, `current_value`, `target_value`, weight
  (untuk progress % keseluruhan Objective).
- **Progress:** Progress % Objective = rata-rata terbobot progress Key Result-nya — dihitung
  saat dibaca, tidak disimpan (menghindari kelas bug stale-cache; query murah pada volume data
  MVP).
- Tampilan Board (Kanban per status) dan tampilan List, keduanya menggunakan komponen bersama
  sesuai `DESIGN.md`, sama seperti board Lead milik CRM.
- **Tampilan Alignment:** pohon berindentasi sederhana (Company → Department → Team →
  Individual) dibangun dari rantai `parent_okr_id` — visualisasi read-only di MVP, tanpa
  matematika auto-rollup (ditunda ke Future Version sesuai §2).

**Aturan / logika**
- Level `subject_type`/`subject_id` sebuah Objective anak secara logis harus berada di bawah
  level induknya, tapi ini adalah petunjuk UI/validasi, bukan constraint DB yang keras — menjaga
  skema tetap sederhana dan menghindari hardcoding apa arti "level" per tenant/vertikal.

## 3F. Pembuat & Penampil Scorecard

- **Header scorecard** (`perf.scorecard_hdrs`): name, subject, period, set perspective yang
  dipakai.
- **Item scorecard** (`perf.scorecard_items`): `scorecard_id`, referensi ke baik penugasan KPI
  (KPI + subjek + periode) atau OKR, `perspective_id`, weight, aktual terhitung, target
  terhitung, skor terhitung (via Variance Engine, 3G), warna Status Rail.
- Builder: pilih perspective → drag-in KPI/OKR relevan untuk subjek → tetapkan bobot (harus
  berjumlah 100% per perspective, divalidasi saat disimpan).
- Viewer: grid Balanced-Scorecard klasik (baris = perspective, kolom = weight/actual/target/
  score/status), plus satu skor terbobot keseluruhan untuk subjek+periode.

**Aturan / logika**
- Scorecard adalah *tampilan/komposisi* atas data KPI dan OKR yang sudah ada — tidak
  menduplikasi nilai, hanya weight dan layout, sehingga aktual KPI yang diperbarui di 3D
  otomatis tercermin di sini.

## 3G. Engine Analisis Varians

**Tujuan:** satu service yang bisa digunakan ulang yang dipanggil setiap Form/Engine lain untuk
membandingkan aktual vs. rencana.

- `VarianceService::evaluate(subjectType, subjectId, metricRef, period): VarianceResult` di
  mana `metricRef` bisa berupa KPI+Target, baris Budget, atau baris Forecast. Untuk baris
  Budget, `actual_value` diresolusi sesuai aturan sumber-aktual §3B — dari GL Accounting (saat
  dipetakan dan ter-install) atau dari `perf.budget_actuals` (fallback manual) —
  `VarianceService` sendiri tidak peduli sumber mana yang menghasilkan angka itu, ia hanya
  membandingkan rencana vs. aktual, sama seperti untuk tipe `metricRef` lain mana pun.
- Mengembalikan: `plan_value`, `actual_value`, `variance_abs`, `variance_pct`, dan `status`
  (`on_track` / `warning` / `breach`), menggunakan direction KPI (higher/lower-is-better) atau,
  untuk Budget, threshold over/under-spend sederhana yang dapat dikonfigurasi per tenant
  (default: dalam 5% = on-track, 5–15% = warning, >15% = breach).
- Dipanggil secara sinkron untuk rendering Dashboard/Scorecard (query agregat cepat, tidak
  butuh queue pada volume data MVP) dan secara asinkron pada event `KpiValueRecorded` /
  pembaruan-aktual-anggaran untuk memutuskan apakah notifikasi WNE harus dipicu.

## 3H. Forecast (Entry)

- **Header forecast** (`perf.forecast_hdrs`): subject, `budget_id` terkait (opsional — forecast
  juga bisa berdiri sendiri terhadap target KPI alih-alih anggaran), period, nomor versi, method
  (`manual` di MVP; nilai cadangan untuk metode statistik Future Version).
- **Baris forecast** (`perf.forecast_lines`): `forecast_id`, potongan periode, `forecast_value`.
- **Versioning:** merevisi forecast membuat baris versi baru alih-alih menimpa — prinsip
  riwayat non-destruktif yang sama seperti versi dokumen DMS dan locking Budget (3B).
- Variance Engine (3G) bisa membandingkan Aktual terhadap versi forecast **terbaru** secara
  default, dengan versi lama tersedia untuk tampilan tren "bagaimana forecast kami untuk periode
  ini berubah seiring waktu."

## 3I. Engine Achievements

- **Definisi badge** (`perf.badge_definitions`, dapat diedit tenant): name, tipe aturan trigger
  (`target_hit` / `okr_completed` / `streak_on_track`), parameter trigger (misalnya panjang
  streak), icon.
- **Log achievement** (`perf.achievements`): `subject_type`/`subject_id`, `badge_id`, earned_at,
  referensi ke KPI/OKR/periode yang memicunya, `awarded_by` (nullable — null berarti
  otomatis-diberikan-sistem, jika tidak berarti pemberian manual manajer).
- **Auto-award:** mendengarkan hasil Variance yang digerakkan `KpiValueRecorded` dan event
  status OKR berubah-menjadi-`completed`; saat aturan trigger sebuah badge cocok, menulis baris
  Achievement dan opsional memicu `NotificationRequested` ke WNE (gaya "congratulations") —
  pola decoupled yang sama seperti di tempat lain.
- MVP hanya log faktual — tidak ada poin, tidak ada UI leaderboard (Future Version, §2).

# 4. Penyimpanan

**Database (schema `PERF`, DB tenant — konsisten dengan `CLAUDE.md` §7A):**

Tabel master / lookup
- `PERF.periods` — definisi periode fiskal (year, breakdown quarter/month opsional) dibagi oleh
  Budgeting/Targets/Forecast/OKR cycles.
- `PERF.perspectives` — kategori Balanced-Scorecard yang dapat diedit tenant (Financial,
  Customer, Process, Learning & Growth, ...).
- `PERF.kpi_definitions` — pustaka metrik (name, unit, direction, perspective_id, active flag).
- `PERF.okr_cycles` — periode OKR bernama (misalnya "2026 Q3").
- `PERF.badge_definitions` — pustaka aturan/badge Achievement.

Tabel transaksi / log
- `PERF.budget_hdrs` — header: subject, period, status, owner, version.
- `PERF.budget_lines` — `budget_id`, category, potongan periode, `amount_planned`.
- `PERF.budget_category_accounts` — pemetaan yang dapat diedit tenant: category → satu atau
  lebih `ACCOUNTING.accounts.id` (referensi informasional, bukan FK yang ditegakkan, karena
  Accounting adalah install opsional), skop company opsional.
- `PERF.budget_actuals` — fallback entri manual: `budget_line_id`, period, `actual_value`,
  entered_by, entered_at — bentuk yang sama seperti `PERF.kpi_values`, dipakai hanya saat
  kategori tidak dipetakan-GL atau Accounting tidak ter-install.
- `PERF.targets` — `kpi_id`, subject, period, `target_value`, `stretch_value`.
- `PERF.kpi_values` — `kpi_id`, subject, period, `actual_value`, source, entered_by, entered_at.
- `PERF.okr_objectives` — `cycle_id`, subject, teks objective, `parent_okr_id`, status.
- `PERF.okr_key_results` — `okr_id`, description, metric_type, nilai start/current/target,
  weight.
- `PERF.scorecard_hdrs` — name, subject, period, set perspective.
- `PERF.scorecard_items` — `scorecard_id`, referensi metrik (KPI atau OKR), `perspective_id`,
  weight, aktual/target/skor terhitung.
- `PERF.forecast_hdrs` — subject, `budget_id` (nullable), period, version, method.
- `PERF.forecast_lines` — `forecast_id`, potongan periode, `forecast_value`.
- `PERF.achievements` — subject, `badge_id`, earned_at, referensi trigger, awarded_by.

**Penyimpanan file objek:** tidak diperlukan untuk MVP. Jika kebutuhan masa depan muncul
(misalnya melampirkan PDF justifikasi anggaran), gunakan ulang **DMS** via facade attachment
`subject_type`/`subject_id` standarnya alih-alih membangun penyimpanan paralel di dalam
Performance — aturan reuse yang sama yang sudah diterapkan setiap modul lain di platform ini.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, postur modular-monolitik yang sama seperti WNE/DMS/CRM/
Schedule. Mengekspos:
- **Facade/service internal** — `PerformanceService::setTarget()`, `::recordKpiValue()`,
  `::createBudget()`, `::createOkr()`, `::buildScorecard()` — plus `VarianceService::evaluate()`
  (3G) berdiri sendiri sebagai primitive perbandingan bersama yang dipanggil secara internal
  oleh setiap bagian modul lainnya.
- **Event bus internal** — `KpiValueRecorded`, `BudgetSubmitted`, `BudgetApproved`,
  `OkrStatusChanged`, `AchievementAwarded`, `VarianceBreachDetected` — memisahkan Performance
  dari WNE/DMS/Schedule; tidak satu pun modul itu adalah compile-time dependency.

**Reuse lintas-modul (jangan pernah membangun jalur paralel):**
- **WNE** — semua notifikasi pelanggaran threshold, notice achievement "congratulations", dan
  workflow approval-anggaran opsional dialihkan melalui facade/event WNE. Performance tidak
  mengirim mail/SMS atau mengimplementasikan state machine approval sendiri.
- **Accounting** — soft dependency, berskop hanya pada sourcing aktual-anggaran (§3B/§3G). Saat
  ter-install dan kategori anggaran dipetakan ke satu atau lebih akun GL, Performance membaca
  pengeluaran aktual via `AccountingService::getAccountBalance(...)` alih-alih mewajibkan entri
  manual. Performance tidak pernah memposting ke Accounting, tidak pernah menjadi GL kedua, dan
  berfungsi penuh pada aktual manual jika Accounting tidak ada — bentuk soft-dependency-berskop
  yang sama yang dipakai Purchase dan Sales terhadap Inventory (`PURCHASE_SPECS.md` §5,
  `SALES_SPECS.md` §5).
- **Schedule** — kadensi review periodik ("check-in OKR mingguan jatuh tempo," "review anggaran
  bulanan") adalah Schedule Task dengan `subject_type = 'performance.okr_objectives'` (atau
  budget_hdrs), dibuat oleh Performance tapi *dimiliki/dirender* oleh Schedule — Performance
  tidak membangun logika reminder/kalender sendiri.
- **DMS** — lampiran dokumen pendukung masa depan mana pun (justifikasi anggaran, dokumen asumsi
  forecast) menggunakan facade polimorfik `attach()` DMS, bukan jalur upload khusus Performance.
- **CRM** — opsional, tidak wajib untuk MVP: subjek sebuah Scorecard bisa berupa record
  `partners` CRM (misalnya scorecard kinerja-akun untuk klien kunci), menggunakan seam
  `subject_type`/`subject_id` yang sama — Performance tetap tidak pernah foreign-key langsung
  ke CRM.

**Keputusan desain multi-level:** secara sengaja *tidak* membangun tabel hierarki organisasi
khusus. Baik "KPI multi-level" maupun "OKR multi-level" dicapai dengan mekanisme yang sudah
terbukti di tempat lain di codebase ini — penandaan polimorfik `subject_type`/`subject_id`
(KPI/Target/Budget) dan keselarasan self-referencing `parent_id` (OKR, pola yang sama seperti
`parent_partner_id` milik CRM) — alih-alih menciptakan konsep hierarki baru. Ini lebih cepat
dikirim dan menghindari memaksa setiap tenant ke satu bentuk org-chart yang kaku.

**Varians sebagai primitive bersama:** `VarianceService::evaluate()` secara sengaja adalah
*satu-satunya* tempat matematika rencana-vs-aktual ditulis. Dashboard, Scorecard, dan notifikasi
pelanggaran WNE semuanya memanggilnya alih-alih masing-masing menghitung logika variansnya
sendiri — menjaga aturan "apa yang dianggap on-track" tetap konsisten dan bisa diubah di satu
tempat.

**Catatan isolasi tenant:** Tabel Performance tidak membawa kolom `tenant_id` **sama sekali**,
konsisten dengan aturan DB-per-tenant `CLAUDE.md` §4/§7.

**Versioning/riwayat non-destruktif:** Budget (saat approval-lock) dan Forecast keduanya
di-versi ke depan alih-alih ditimpa — prinsip yang sama yang dipakai DMS untuk versi dokumen —
sehingga "apa yang awalnya kami rencanakan" selalu bisa dijawab untuk tujuan audit/pelaporan-
board.

**Queue:** Penangkapan aktual KPI, CRUD anggaran, dan pembaruan OKR bersifat sinkron (cepat,
menghadap-user). Hanya kaki "event → WNE mengevaluasi aturan routing → dispatch notifikasi"
yang asinkron, dan itu menggunakan ulang queue `notifications` WNE yang sudah ada — Performance
tidak butuh queue sendiri untuk MVP.

**Catatan kelayakan jual (marketability)**
- Performance dapat dijual **standalone** sebagai produk "Executive Dashboard" (budgeting +
  tracking KPI/OKR + scorecard) bahkan sebelum tenant membeli modul vertikal mana pun —
  memperluas pasar yang dapat dijangkau melampaui Legal.
- Begitu tenant punya WNE + Schedule + CRM ter-install, alert pelanggaran dan reminder review
  Performance "langsung berfungsi" tanpa setup ekstra — cerita upsell yang kuat ("Anda sudah
  punya plumbing-nya, nyalakan Performance dan dapatkan dashboard manajemen sungguhan").
- Kombinasi Variance Engine + Scorecard adalah pitch "rapat board dalam satu layar" yang
  konkret dan bisa didemokan — fitur bernilai tinggi dan mudah ditampilkan untuk audiens
  pembeli legal yang konservatif yang sudah ditargetkan brief `DESIGN.md` (kepercayaan,
  presisi, status-sekilas-pandang).
- Achievements, yang dijaga faktual alih-alih di-game-kan, cocok dengan tone tenang/profesional
  di `DESIGN.md` §5 (tanpa keramahan yang dipaksakan) sambil tetap memberikan talking-point
  "kenali kinerja baik" yang positif dan bisa dijual.
- Begitu tenant juga punya Accounting ter-install, angka anggaran-vs-aktual Performance bisa
  direkonsiliasi ke data GL yang benar-benar diposting alih-alih angka yang diketik manual —
  cerita kepercayaan "satu angka benar, bukan dua laporan yang tidak sepakat" yang sama yang
  sudah dipakai untuk AR Sales/Accounting dan AP Purchase/Accounting.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3C (pustaka KPI + Target) → 3D
(penangkapan aktual, manual) → 3G (Variance Engine — leverage tertinggi, semua yang lain
memanggilnya) → 3B (Budgeting) → 3H (Forecast, menggunakan ulang bentuk baris-Budget) → 3E
(OKR) → 3F (Scorecard, menggabungkan 3C/3D/3E) → 3A (Dashboard, mengikat semuanya) → **kirim**
— lalu 3I (Achievements) dan tinjau ulang item Future Version (konektor otomatis, forecasting
prediktif, matematika rollup OKR bertingkat) begitu ada volume penggunaan nyata.
