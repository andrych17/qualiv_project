# Modul HCM
## Human Resources / Human Capital Management — Modul Inti Bersama (dapat mandiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap tenant di platform ini — terlepas dari vertikal mana yang mereka sewa (Legal hari ini,
Property belakangan) — memiliki karyawan sendiri yang harus dikelola: sebuah firma hukum
memiliki partner, associate, paralegal, dan staf admin; seorang property manager memiliki agen
dan staf pemeliharaan. Ini adalah **data tenaga kerja internal**, berbeda dari `CRM.partners`
(klien/vendor/lead eksternal) meskipun kedua modul tersebut secara sepintas terlihat mirip
(orang, peran, info kontak) — lihat §5 untuk alasan mengapa keduanya secara sengaja **bukan**
tabel yang sama.

Jika dibiarkan tidak diselesaikan secara terpusat, ini mengulangi anti-pola persis yang
berusaha dihindari oleh WNE, DMS, CRM, dan Schedule masing-masing:

- Setiap tenant saat ini mengelola record karyawan, cuti, dan payroll di spreadsheet atau alat
  pihak ketiga yang terputus — tidak ada single source of truth, tidak ada audit trail, tidak
  ada self-service.
- **Payroll statutori Indonesia kompleks dan berubah setiap tahun** (tarif TER PPh 21, plafon
  kontribusi BPJS, upah minimum regional/UMP-UMK, waktu THR) — melakukan kesalahan di sini
  adalah risiko kepatuhan dan reputasi bagi tenant mana pun, dan membangunnya secara generik
  (tidak spesifik-Indonesia) akan membuat produk tidak dapat dijual di pasar ini.
- Tidak ada jalur approval bersama untuk permintaan cuti, payroll run, atau perubahan kontrak —
  setiap tenant sebaliknya akan menginginkan rantai persetujuan bespoke miliknya sendiri.
- Tidak ada kalender bersama untuk siapa yang sedang cuti, sedang shift, atau tidak tersedia —
  Schedule sudah menyelesaikan "apa yang terjadi kapan," HCM seharusnya memberi masukan
  padanya, bukan menduplikasinya.
- Tidak ada tempat terpusat untuk menyimpan kontrak kerja, hasil scan ID, dan sertifikasi dengan
  retensi yang tepat — DMS sudah menyelesaikan ini; HCM seharusnya memberi masukan padanya,
  bukan menduplikasinya.

**Kebutuhan klien:**
- Sadar multi-tenant, isolasi DB-per-tenant seperti setiap modul Core lain (tanpa kolom
  `tenant_id` — lihat `CLAUDE.md` §4/§7).
- Harus bekerja **mandiri** — dapat dijual sebagai item lini tersendiri kepada tenant yang belum
  membeli Schedule, DMS, atau modul vertikal — tetapi berintegrasi dengan bersih dengan WNE,
  DMS, dan Schedule ketika ada, pola seam terdekopel yang sama seperti setiap modul Core lain.
- **Harus mematuhi hukum ketenagakerjaan dan regulasi pajak Indonesia** sejak hari pertama untuk
  submodul yang menyentuh gaji dan status kepegawaian: tipe kontrak PKWT/PKWTT, PPh 21 (metode
  TER), BPJS Kesehatan, BPJS Ketenagakerjaan (JKK/JKM/JHT/JP), THR, dan lembur statutori —
  karena Payroll adalah modul di mana "cukup dekat" tidak dapat dijual; ini adalah item risiko
  hukum bagi tenant.
- Tarif statutori (tabel pajak, persentase/plafon BPJS, upah minimum regional) harus berupa
  data master yang **dapat dikonfigurasi dan bervarian (versioned)**, bukan di-hardcode —
  regulasi pemerintah Indonesia (PMK, Permenaker) mengubah angka-angka ini pada siklus kira-kira
  tahunan.
- **Implementasi cepat adalah prioritas.** Luncurkan inti yang ramping dan benar (data karyawan,
  cuti, absensi, payroll yang patuh, self-service) dengan cepat; tunda semua hal yang
  strategis-tapi-tidak-menghalangi (ATS, Performance, LMS, Talent, perencanaan Compensation
  terstruktur, enrollment Benefits, Analytics mendalam) ke Future Version, sesuai tabel
  submodul yang diberikan klien.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first, sesuai tabel submodul klien sendiri — setiap submodul
> mendapat setidaknya rumah data agar tidak ada yang butuh migration breaking belakangan, tapi
> hanya sebagian yang meluncurkan fungsionalitas penuh saat peluncuran.

**MVP (diluncurkan pertama — empat submodul dengan urgensi hukum/harian yang nyata, ditambah
ESS sebagai pintu depan ke semuanya)**

| Submodul | Cakupan MVP |
|---|---|
| **HR / Core HCM** | Penuh — ini adalah fondasi yang menjadi tempat bergantung setiap submodul lain (dan setiap submodul masa depan). |
| **Time & Attendance** | Clock in/out sederhana (web + mobile-responsive), penugasan shift, flag terlambat/absen dasar. |
| **Leave Management** | Setup kebijakan, entitlement/balance, permintaan → approval WNE → pengurangan balance. Tipe cuti statutori Indonesia sudah pre-seeded (tahunan, sakit, melahirkan, menikah, duka, Haji). |
| **Payroll** | Tidak dimiliki HCM — hard-dependency, modul **Payroll** mandiri (`PAYROLL_SPECS.md`) mengonsumsi `HCM.employees`, `HCM.attendance_logs`, dan `HCM.leave_requests` via facade/event. Layar HCM sendiri menampilkan status run/slip gaji secara read-only melalui `PayrollService`, sesuai §3G di bawah. |
| **Employee Self-Service** | Portal karyawan + manajer: profil, download slip gaji, permintaan/approval cuti, tampilan absensi, akses dokumen (via DMS). |

**Future Version (pasca-peluncuran — model data sudah di-stub sekarang agar tidak ada
migration breaking belakangan)**

| Submodul | Cakupan/alasan yang ditunda |
|---|---|
| **Recruitment / ATS** | Pipeline kandidat penuh. MVP hanya meluncurkan titik masuk "hire → membuat Employee" minimal (lihat §3D) karena Payroll/Core HR butuh *suatu* cara untuk onboarding seseorang; pipeline sourcing/interview itu sendiri adalah add-on sellable terpisah, urgensi lebih rendah daripada memastikan karyawan yang sudah ada dibayar dengan benar. |
| **Performance** | Siklus goal/KPI/appraisal — berharga tapi tidak menghalangi; toh butuh Core HR + struktur organisasi ada lebih dulu. |
| **Learning / LMS** | Training/skill/sertifikasi — secara genuine bentuk produk yang berbeda (content delivery); paling baik dipisah begitu ada permintaan nyata. |
| **Talent Management** | Career/succession planning — bergantung pada data Performance sudah ada lebih dulu; prematur sebelum itu. |
| **Compensation** | Salary band/grade terstruktur dan siklus perencanaan comp — Payroll MVP menggunakan field base salary sederhana per-karyawan; banding formal adalah upsell v2. |
| **Benefits** | Enrollment di luar BPJS statutori yang sudah ada di Payroll MVP (misalnya asuransi swasta, katalog tunjangan) — fitur nyata, tidak mendesak. |
| **HR Analytics** | Pelaporan/dashboard tenaga kerja mendalam di luar angka headline Main Dashboard MVP (lihat 3A) — butuh beberapa siklus payroll dengan data nyata agar layak dibangun. |

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> database.

## 3A. Dashboard Utama HCM

**Fungsi / fitur**
- Kartu headline: Karyawan Aktif, Sedang Cuti Hari Ini, Approval Cuti Tertunda, Status Payroll
  Run (periode ini).
- Antrean "pekerjaan saya" (mencerminkan pola "My Approvals" WNE / "My work" CRM): permintaan
  cuti menunggu approval saya, kontrak yang kedaluwarsa dalam 30/60/90 hari ke depan, periode
  probation yang segera berakhir.
- Snapshot org chart (collapsed secara default, dapat diperluas) digerakkan oleh reporting line
  `hcm.positions`.

**Layout**
- Atas: 4 kartu ringkasan, sesuai `DESIGN.md`.
- Utama: tabel bertab — "Approval Tertunda" | "Kontrak Kedaluwarsa" | "Pengecualian Absensi Hari
  Ini" | "Perekrutan Terbaru" — komponen Data Table bersama, Status Rail berwarna sesuai
  urgensi.
- Klik baris membuka drawer/halaman detail record terkait.

**Aturan / logika**
- Setiap list otomatis terlingkup ke DB tenant saat ini (DB-per-tenant, tanpa filter level-app
  yang dibutuhkan — sama seperti Schedule dan DMS).
- Visibilitas menghormati peran: seorang karyawan hanya melihat datanya sendiri; seorang
  manajer melihat direct report-nya (diresolusi via `hcm.positions.reports_to_position_id`);
  admin HR melihat semuanya.

## 3B. Master Karyawan (Core HR)

**Tujuan:** single source of truth untuk "siapa yang bekerja di sini" — segala sesuatu yang
lain di HCM, dan pada akhirnya Payroll/Time/Leave/ESS, bergantung pada record ini.

- **Field identitas & statutori:** nama lengkap, tanggal lahir, jenis kelamin, `nik` (nomor
  KTP/identitas nasional, 16 digit), `npwp` (ID pajak, opsional — memengaruhi kategori tarif
  PPh 21 jika tidak ada, lihat 3G), `bpjs_kesehatan_no`, `bpjs_ketenagakerjaan_no`, alamat,
  status pernikahan, jumlah tanggungan (menggerakkan `ptkp_status` — lihat 3G), agama
  (digunakan hanya untuk menghitung tanggal hari libur keagamaan yang benar untuk waktu THR,
  tidak pernah ditampilkan di tempat lain sebagai field filter/report).
- **Field kepegawaian:** nomor karyawan (format dapat dikonfigurasi tenant), tanggal masuk,
  status kepegawaian (`active` / `on_leave` / `suspended` / `terminated`), posisi (FK →
  `hcm.positions`), departemen/unit organisasi, manajer langsung (diturunkan dari reporting
  line posisi), rekening bank (untuk pencairan payroll, 3G).
- **Tab ringkasan kontrak:** `hcm.employment_contracts` saat ini + historis (lihat 3D).
- **Tab dokumen:** dilampirkan via `DocumentService::attach()` ke DMS
  (`subject_type = 'hcm.employees'`) — PDF kontrak, hasil scan ID, sertifikat. HCM tidak
  menyimpan file apa pun sendiri, pola yang sama yang sudah ditetapkan DMS untuk setiap modul
  lain.
- **Tab balance cuti, tab ringkasan absensi, tab riwayat slip gaji** — read-through ke
  3F/3E/3G.
- List view: Data Table bersama, filter berdasarkan departemen/posisi/status, Status Rail
  berwarna sesuai status kepegawaian (`active` = success/neutral, `on_leave` = info,
  `suspended` = warning, `terminated` = border neutral/archived).

**Aturan / logika**
- Mengakhiri kepegawaian seorang karyawan tidak pernah hard-delete record — mengatur
  `employment_status = terminated` + `termination_date` + `termination_reason`, menjaga
  riwayat untuk pencatatan statutori (hukum ketenagakerjaan Indonesia mengharapkan pemberi
  kerja menyimpan record kepegawaian) dan untuk perhitungan pesangon payroll.
- Mengubah posisi/departemen dicatat (`hcm.employee_position_history`) — dibutuhkan untuk
  perhitungan statutori berbasis masa kerja (entitlement cuti, pesangon, prorata THR) yang
  bergantung pada "kapan orang ini memegang peran apa."

## 3C. Struktur Organisasi & Pekerjaan (Core HR)

**Tujuan:** org chart dan katalog pekerjaan yang menjadi tempat bergantung Positions, Payroll,
dan resolusi reporting-line.

- `hcm.org_units` — pohon (departemen/divisi/cabang), `parent_org_unit_id` yang
  self-referencing, `accounting_cost_center_id` nullable opsional (referensi informasional ke
  `ACCOUNTING.cost_centers.id`, bukan FK yang ditegakkan, karena Accounting adalah instalasi
  opsional) sehingga biaya payroll/HR untuk unit organisasi ini dapat diatribusikan ke cost
  center finansial ketika Accounting terpasang — lihat §5. `hcm.org_units` sendiri tetap murni
  konsep organisasional/reporting-line (siapa lapor ke siapa) — ia bukan, dan tidak perlu
  menjadi, cerminan 1:1 dari dimensi cost-center Accounting; pemetaan bersifat opsional per
  unit, tidak diasumsikan.
- `hcm.jobs` — judul/katalog pekerjaan (master), independen dari orang tertentu yang mengisinya.
- `hcm.positions` — sebuah kursi tertentu: `job_id`, `org_unit_id`,
  `reports_to_position_id` (self-referencing — inilah yang menggerakkan resolusi manajer di
  seluruh modul, pola self-referencing yang sama yang dipakai CRM untuk `parent_partner_id`),
  plafon headcount (opsional, informasional di MVP — belum ada engine
  enforcement/budget).
- Layar CRUD tree-view sederhana untuk Org Unit maupun Positions, konsisten dengan pola UX
  Folder Management DMS (3D).

**Aturan / logika**
- Sebuah Position dapat kosong (tanpa karyawan saat ini) — Payroll/Attendance tidak pernah
  merujuk Positions secara langsung untuk pembayaran, hanya melalui Employee yang sedang
  mengisinya, sehingga kekosongan tidak pernah merusak apa pun di hilir.

## 3D. Kepegawaian & Kontrak (Core HR)

**Tujuan:** melacak dasar hukum kepegawaian — dibutuhkan untuk kepatuhan Indonesia, karena
tipe kontrak mengatur aturan probation, periode notice, dan kelayakan pesangon.

- `hcm.employment_contracts`: `employee_id`, tipe kontrak (`PKWT` waktu-tertentu / `PKWTT`
  permanen), tanggal mulai, tanggal berakhir (wajib untuk PKWT, null untuk PKWTT), base
  salary, lokasi kerja, `probation_end_date` (hanya PKWTT — hukum Indonesia melarang probation
  pada PKWT), status (`active` / `expired` / `terminated` / `renewed`), referensi dokumen
  (DMS).
- Perpanjangan/ekstensi PKWT dilacak sebagai baris baru yang ditautkan via
  `renewed_from_contract_id` — dibutuhkan karena regulasi Indonesia (PP 35/2021) membatasi
  total durasi PKWT termasuk ekstensi maksimal 5 tahun; sistem harus bisa menjawab "berapa
  lama orang ini sudah berada di PKWT secara total" tanpa perhitungan ulang yang ambigu.
- **Titik masuk perekrutan minimal (menggantikan ATS penuh sampai Future Version):** satu aksi
  "New Hire" membuat Employee (3B) + Contract pertama (3D) + penugasan Position awal (3C)
  dalam satu form — submodul Recruitment/ATS belakangan menggantikan langkah *sourcing* di
  depan ini, bukan langkah ini sendiri.

**Aturan / logika**
- Kontrak yang kedaluwarsa dalam jendela yang dapat dikonfigurasi (default 60 hari) muncul di
  Dashboard (3A) dan memicu event `hcm.contract_expiring` → WNE, sehingga keputusan
  perpanjangan/non-perpanjangan tidak terlewat — gunakan ulang WNE, tanpa logika pengingat
  paralel di HCM.
- Berakhirnya probation yang mendekat juga muncul via WNE dengan cara serupa.

## 3E. Time & Attendance

**Tujuan:** mengetahui siapa bekerja kapan, baik untuk visibilitas operasional maupun sebagai
input bagi perhitungan lembur Payroll.

- **Shift:** `hcm.shifts` (nama, waktu mulai/selesai, durasi istirahat) dapat ditugaskan per
  karyawan per hari via `hcm.shift_assignments`. Model shift tetap sederhana untuk MVP —
  auto-generation roster rotating yang kompleks adalah Future Version.
- **Clock in/out:** tombol web (mobile-responsive, sesuai `DESIGN.md`) mencatat
  `hcm.attendance_logs` (`employee_id`, `clock_in_at`, `clock_out_at`, source = `web` untuk
  MVP). Clock-in terverifikasi geo/foto dan integrasi perangkat biometrik adalah Future
  Version — tidak dibutuhkan untuk dapat dijual saat peluncuran bagi tenant vertikal-legal
  berbasis kantor.
- **Pengecualian:** keterlambatan / pulang cepat / tidak-hadir dihitung terhadap shift yang
  ditugaskan, ditampilkan dengan Status Rail (`danger` = absen tanpa keterangan, `warning` =
  terlambat, `success` = tepat waktu) pada list absensi harian.
- **Permintaan koreksi:** karyawan dapat mengajukan koreksi (misalnya lupa clock out) yang
  melalui WNE untuk approval manajer — gunakan ulang WNE, sama seperti Leave (3F).

**Aturan / logika**
- Data absensi memberi masukan langsung ke engine lembur Payroll (3G) — jam melampaui waktu
  akhir shift, tunduk pada formula lembur statutori, bukan tarif flat.
- Jika modul Schedule aktif untuk tenant, penugasan shift secara opsional dicerminkan sebagai
  entri kalender read-only di Schedule untuk visibilitas kalender terpadu — HCM menerbitkan
  `hcm.shift_assigned`; Schedule tidak memiliki dependensi compile-time apa pun terhadap HCM,
  sama seperti setiap integrasi lintas-modul lain di codebase ini.

## 3F. Leave Management

**Tujuan:** entitlement, permintaan, dan approval cuti berbasis kebijakan — sudah pre-seeded
dengan tipe cuti statutori Indonesia sehingga tenant patuh sejak awal.

- `hcm.leave_types` (dapat diedit tenant, pre-seeded): Tahunan (`cuti tahunan`, minimum
  statutori 12 hari/tahun setelah 12 bulan masa kerja), Sakit (`cuti sakit`, surat dokter
  dibutuhkan melewati ambang yang dapat dikonfigurasi), Melahirkan (`cuti melahirkan`, 3 bulan
  sesuai hukum Indonesia), Paternity (2 hari), Menikah (3 hari), Duka (2 hari, keluarga inti),
  Haji/ziarah keagamaan (hingga 3 bulan, tidak dibayar, satu kali per masa kerja sesuai
  undang-undang), plus tipe custom yang didefinisikan tenant.
- `hcm.leave_policies`: per tenant × tipe cuti × status kepegawaian, hari entitlement/tahun,
  metode akrual (grant tahunan vs akrual bulanan), aturan carry-over (hari maksimum,
  kedaluwarsa), dibayar vs tidak dibayar.
- `hcm.leave_balances`: per karyawan × tipe cuti × periode, balance berjalan.
- `hcm.leave_requests`: karyawan, tipe, rentang tanggal, alasan, status
  (`pending`/`approved`/`rejected`/`cancelled`), lampiran (surat dokter dll., via DMS).

**Aturan / logika**
- Mengirim permintaan memicu `WorkflowRequested` ke dalam WNE (`workflow_code =
  hcm.leave_approval`) — HCM tidak mengimplementasikan logika rantai approval sendiri, pola
  yang sama dipakai kualifikasi lead CRM. Callback approval/penolakan memperbarui
  `leave_requests.status` dan, saat disetujui, mengurangi `leave_balances`.
- Pemeriksaan balance berjalan saat pengiriman (peringatan lunak, dapat dikonfigurasi tenant
  apakah balance negatif memblokir pengiriman atau hanya menandainya — beberapa tenant
  mengizinkan cuti advance/negatif).
- Cuti yang disetujui dan tumpang tindih dengan sebuah shift secara otomatis memaafkan hari
  itu dari pengecualian Absensi (3E) — tidak menandai ganda karyawan yang sah absen sebagai
  "no-show".

## 3G. Payroll — Dikonsumsi dari Modul Payroll (tidak dimiliki HCM)

**Tujuan:** HCM adalah system of record identitas/kepegawaian; semua perhitungan statutori,
pemrosesan run, dan pencairan berada di modul **Payroll** mandiri (`PAYROLL_SPECS.md`). Ini
mencerminkan seam "extend, don't duplicate" yang sama yang sudah dipakai untuk
`vendor_profiles` Purchase dan `customer_sales_profiles`/`customer_credit_profiles` Sales atas
`CRM.partners` — HCM memiliki orangnya, Payroll memiliki run pembayarannya.

- `PAYROLL.employee_payroll_profiles` adalah ekstensi 1:1 dari `HCM.employees.id` (FK
  lintas-schema, Core-ke-Core, arah yang sama yang sudah didokumentasikan CRM/DMS/WNE untuk
  satu sama lain) — payroll group, struktur gaji, kategori risiko JKK, dan status PTKP berada
  di sana, bukan pada `HCM.employees`.
- HCM menerbitkan `hcm.employee_hired`, `hcm.employee_terminated`,
  `hcm.employee_position_changed`, `hcm.attendance_logged`, `hcm.leave_approved` — Payroll
  berlangganan ini untuk menggerakkan input lembur (dari absensi) dan pengurangan cuti tidak
  dibayar (dari cuti yang disetujui) secara otomatis, alih-alih keduanya menjadi input
  manual/impor seperti yang awalnya dicakup dalam MVP Payroll. HCM tidak memiliki dependensi
  compile-time apa pun terhadap kelas Payroll — postur aman-feature-flag yang sama yang sudah
  dipakai Schedule terhadap WNE.
- Layar HCM sendiri (kartu Status Payroll Run Dashboard 3A, tab slip gaji ESS 3H) membaca
  data run/slip gaji melalui `PayrollService::getRunStatus()` /
  `PayrollService::getPayslips()` — HCM tidak menyimpan baris run/slip gaji apa pun sendiri.
- **Mengakhiri kepegawaian seorang karyawan di HCM** (§3B) memicu `hcm.employee_terminated`;
  Payroll mendengarkan dan menawarkan Final Payroll (pesangon) terhadap tabel tarifnya
  sendiri — HCM memasok fakta masa kerja/alasan-pemutusan, engine Payroll melakukan
  perhitungannya.

**Aturan / logika**
- Sebuah tenant tidak dapat mengaktifkan Payroll tanpa HCM aktif — hard dependency (postur
  yang sama dimiliki Sales terhadap CRM), bukan pola integrasi soft/opsional yang dipakai HCM
  terhadap Schedule/DMS.
- Status PTKP, begitu diatur pada `employee_payroll_profiles`, adalah field milik Payroll
  untuk dikelola — record karyawan HCM tidak membawa field status-pajak miliknya sendiri,
  menutup risiko persis dua-tempat-untuk-diperbarui yang menjadi alasan restrukturisasi ini
  ada.

## 3H. Portal Employee Self-Service (ESS)

**Tujuan:** pintu depan untuk setiap karyawan/manajer — mengurangi beban admin HR, yang
merupakan argumen "kenapa saya harus bayar untuk ini" terbesar tunggal untuk produk HCM yang
dibangun solo dev.

- **Tampilan karyawan:** profil saya (lihat/edit field non-statutori, field statutori
  request-only via HR), slip gaji saya (list + download, dari 3G), balance cuti saya + form
  permintaan (3F), log absensi saya + clock in/out (3E), dokumen saya (berbasis DMS).
- **Tampilan manajer:** semua di atas untuk diri sendiri, ditambah absensi tim hari ini,
  inbox approval tertunda (koreksi cuti/absensi), roster tim.
- Dibangun di atas pustaka komponen bersama yang sama (`DESIGN.md`) seperti setiap modul lain
  — tidak ada bahasa desain terpisah untuk ESS.

**Aturan / logika**
- ESS adalah lensa permission atas tabel dasar yang sama (3B–3G), bukan penyimpanan data
  terpisah — "data saya" vs "data tim saya" vs "semua data" adalah aturan scoping di lapisan
  Service, sesuai konvensi "logika bisnis di Service" `CLAUDE.md` §6.

## 3I. Recruitment / ATS — **Future Version**

- Pipeline kandidat (source → screen → interview → offer → hire), requisition/posting
  pekerjaan, penjadwalan interview (akan menggunakan ulang Schedule untuk slot interview, pola
  yang sama seperti semua yang lain), pembuatan offer letter (via templating DMS).
- MVP hanya meluncurkan titik masuk "New Hire" di 3D; pipeline sourcing di depannya dibangun
  di sini begitu ada permintaan yang menjustifikasinya.

## 3J. Performance Management — **Future Version**

- Penetapan goal (individu/tim), pelacakan KPI, siklus review (self/manajer/360), skala
  rating, kalibrasi. Bergantung pada Struktur Organisasi (3C) yang sudah ada — ini adalah
  masalah "kapan," bukan "bagaimana," ditunda murni untuk prioritas waktu pembangunan.

## 3K. Learning / LMS — **Future Version**

- Katalog kursus, penugasan, pelacakan penyelesaian, pelacakan kedaluwarsa sertifikasi (akan
  menggunakan ulang WNE untuk pengingat kedaluwarsa, pola yang sama seperti retensi DMS).
  Secara genuine bentuk produk berbeda (authoring/delivery konten) — kandidat kuat untuk batas
  modul sendiri atau bahkan ekstraksi jika berkembang besar, tinjau ulang sesuai kriteria
  ekstraksi `CLAUDE.md` §2 saat dibangun.

## 3L. Talent Management — **Future Version**

- Career pathing, grid succession 9-box, tagging talent pool. Bergantung pada data Performance
  (3J) yang sudah ada lebih dulu — alasan sequencing, bukan celah desain.

## 3M. Compensation — **Future Version**

- Salary band/grade per pekerjaan (3C), siklus review comp terstruktur, workflow kenaikan
  merit (akan melalui WNE). Field `base_salary` flat Payroll MVP pada kontrak (3D), dan lookup
  `PAYROLL.grades` yang ringan milik Payroll sendiri (`PAYROLL_SPECS.md` §4 — label opsional
  untuk mengelompokkan karyawan ke Salary Structure, bukan sistem banding), keduanya
  forward-compatible — item Future Version ini memformalkan banding/grading di atas keduanya,
  bukan perombakan skema keduanya.

## 3N. Benefits — **Future Version**

- Enrollment untuk benefit non-statutori (top-up asuransi kesehatan swasta, katalog tunjangan
  makan/transport, asuransi jiwa) di luar BPJS statutori yang sudah ditangani di Payroll MVP
  (3G). Fitur nyata, hanya tidak mendesak relatif terhadap memastikan gaji inti patuh lebih
  dulu.

## 3O. HR Analytics

- **MVP:** angka headline pada Main Dashboard (3A) saja — headcount, turnover periode ini,
  approval tertunda, status payroll run.
- **Future Version:** engine pelaporan khusus — tren turnover, cost-per-hire (begitu ATS ada),
  analisis tren absensi/ketidakhadiran, breakdown biaya payroll per departemen, laporan yang
  dapat diekspor. Cocok secara alami untuk pola analitik AI "ask your data" yang sama yang
  dipakai di tempat lain pada platform (terlingkup-tenant, koneksi DB read-only,
  ter-annotasi-skema) begitu ada beberapa siklus payroll dengan data nyata yang membuatnya
  layak dibangun.

---

# 4. Penyimpanan

**Database (schema `HCM`, DB tenant):**

**Tabel master / lookup**
- `HCM.org_units` (termasuk `accounting_cost_center_id` opsional, lihat §3C/§5), `HCM.jobs`,
  `HCM.positions`
- `HCM.leave_types`, `HCM.leave_policies`
- `HCM.shifts`
- `HCM.regional_minimum_wages` (versioned; satu-satunya tabel tarif statutori yang tetap
  berada di HCM, karena ia adalah pemeriksaan kepatuhan pembuatan-kontrak — lihat §3D — bukan
  input payroll-run)

**Tabel karyawan & kepegawaian**
- `HCM.employees` (identitas, ID statutori, status kepegawaian, referensi posisi, rekening
  bank)
- `HCM.employee_position_history`
- `HCM.employment_contracts`

**Tabel transaksi waktu, cuti**
- `HCM.shift_assignments`, `HCM.attendance_logs`, `HCM.attendance_corrections`
- `HCM.leave_balances`, `HCM.leave_requests`

Tabel payroll run/slip gaji/THR seluruhnya berpindah ke `PAYROLL.payroll_periods` /
`PAYROLL.payroll_run_lines` / `PAYROLL.thr_calculations` — lihat `PAYROLL_SPECS.md` §4.

**Tabel stub Future-Version (kosong/minimal saat peluncuran, migration aditif saja):**
- `HCM.candidates`, `HCM.job_requisitions` (ATS)
- `HCM.performance_cycles`, `HCM.goals`, `HCM.reviews` (Performance)
- `HCM.courses`, `HCM.enrollments`, `HCM.certifications` (LMS)
- `HCM.salary_bands` (Compensation)
- `HCM.benefit_plans`, `HCM.benefit_enrollments` (Benefits)

**Custom fields:** `HCM.employees` didaftarkan sebagai entitas yang dapat diperluas
(`entity_type = 'hcm_employee'`, key yang sama yang sudah dipakai `CUSTOMFIELDS_SPECS.md` §3G
sebagai contoh kerjanya sendiri) terhadap schema `CUSTOMFIELDS` yang sudah ada (sesuai
`CLAUDE.md` §7A) — field spesifik-tenant pada record karyawan (misalnya skema ID staf lokal,
nomor keanggotaan serikat) tidak pernah membutuhkan migration HCM. Pendaftaran ini milik HCM,
bukan Payroll: Payroll membaca `HCM.employees` via FK lintas-schema (§5) tapi tidak memiliki
tabel tersebut, sehingga ia tidak mendaftarkannya — pendaftaran custom-fields milik Payroll
sendiri (`PAYROLL_SPECS.md` §5) terbatas pada tabel yang benar-benar dimiliki Payroll.

**Penyimpanan file objek:** tidak ada yang dimiliki HCM secara langsung — semua dokumen
(kontrak, hasil scan ID, sertifikat, slip gaji, surat dokter) mengalir melalui
`DocumentService` ke path `tenant_{id}/DMS/HCM/...` milik DMS yang sudah ada, sesuai
`CLAUDE.md` §7B dan pola facade DMS — tidak ada kode penyimpanan paralel di HCM, aturan yang
sama yang diterapkan DMS sendiri untuk setiap modul lain.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu AI Coding.

**Pola arsitektur:** Modul Core, postur monolitik-modular yang sama seperti WNE/DMS/CRM/
Schedule, di `app/Modules/HCM/`. Mengekspos:
- **Facade/service internal** — `EmployeeService::hire(...)`, `LeaveService::request(...)`,
  `AttendanceService::clockIn(...)` — titik integrasi yang disukai untuk modul lain dan untuk
  ESS itu sendiri (ESS adalah lapisan UI/permission, bukan lapisan service terpisah).
  Perhitungan Payroll (`PayrollService::runPeriod(...)`,
  `PayrollService::calculatePph21(...)`) dimiliki modul Payroll, bukan HCM — HCM memanggil
  *ke dalam* facade Payroll untuk status run/slip gaji read-only saja, tidak pernah
  sebaliknya.
- **Event bus internal** — menerbitkan `hcm.employee_hired`, `hcm.employee_terminated`,
  `hcm.employee_position_changed`, `hcm.contract_expiring`, `hcm.leave_requested`,
  `hcm.leave_approved`, `hcm.attendance_logged`, `hcm.shift_assigned` — daftar ini sekarang
  cocok dengan apa yang benar-benar dijelaskan §3B/§3D/§3E/§3F/§3G bahwa HCM menerbitkannya
  (versi sebelumnya dari daftar ini menghilangkan
  `employee_terminated`/`employee_position_changed`/`attendance_logged`, ketiganya dibutuhkan
  oleh hard dependency Payroll sendiri terhadap HCM, sesuai `PAYROLL_SPECS.md` §5). Modul
  vertikal boleh berlangganan (misalnya Legal ingin tahu kapan paralegal yang ditugaskannya
  sedang cuti); HCM tidak pernah berlangganan atau memanggil ke dalam modul vertikal — aturan
  Core→tidak-pernah→Vertical satu-arah yang sama seperti di tempat lain. (`hcm.payroll_run_completed`
  telah dihapus dari daftar ini — kartu Status Payroll Run milik HCM sendiri (§3A) dan tab
  slip gaji (§3H) membaca data itu secara sinkron via
  `PayrollService::getRunStatus()`/`::getPayslips()` sesuai §3G, bukan via langganan event;
  Payroll memiliki event status-run miliknya sendiri, jika pernah dibutuhkan, di bawah
  namespace `payroll.*` miliknya sendiri, bukan milik HCM.)

**Kenapa HCM tidak menggunakan ulang `CRM.partners` untuk karyawan, meskipun kemiripan
permukaannya:** Karyawan membawa field statutori (NIK, NPWP, nomor BPJS, status PTKP, tipe
kontrak kerja) yang tidak punya makna bagi partner CRM, dan diatur oleh aturan lifecycle yang
berbeda (pemutusan ≠ merge/deactivate, perhitungan masa kerja memberi masukan pada perhitungan
yang diwajibkan secara hukum yang tidak dikenal CRM). Menggabungkan keduanya akan menjadi
kesalahan pemodelan yang sama yang ditandai spesifikasi CRM sendiri untuk mencampuradukkan
`type` dan `role` — perbedaan struktural yang menyamar sebagai overlap. Jika seorang tenant
belakangan ingin cross-linking "apakah partner ini juga karyawan" (misalnya seorang partner
Legal yang juga sumber referral), itu adalah `hcm.employees.linked_partner_id` yang longgar
(nullable, informasional, bukan merge yang ditegakkan-FK) — bukan tabel bersama.

**Kenapa Payroll adalah modul terpisah, bukan submodul HCM:** Payroll awalnya dispesifikasikan
sebagai modul Core mandiri miliknya sendiri dengan tabel `employees` minimal miliknya sendiri,
dengan asumsi belum ada modul HR yang ada. Sekarang HCM sudah ada, mempertahankan dua kalkulator
PPh21/TER/BPJS yang dikelola secara independen akan menjadi risiko kepatuhan tertinggi tunggal
di platform ini — pembaruan tarif yang diterapkan di satu tempat dan terlewat di tempat lain
menghasilkan slip gaji yang salah secara diam-diam. Payroll mempertahankan skema dan folder
modulnya sendiri (`app/Modules/Payroll/`) karena engine perhitungan, tabel tarif statutori, dan
lifecycle run cukup besar untuk tetap menjadi modul terpisah — tetapi identitas karyawan
sepenuhnya dihapus dari skema Payroll dan diambil dari HCM via `employee_payroll_profiles`.
Ini adalah **restrukturisasi**, bukan penggabungan: dua modul, satu sumber kebenaran untuk
"siapa karyawan ini."

**Penggunaan ulang lintas-modul (terdekopel, berbasis event — seam yang sama seperti setiap
modul Core lain):**
- **WNE** — semua approval (cuti, koreksi absensi, payroll run) dan semua pengingat (kontrak
  kedaluwarsa, probation berakhir, THR jatuh tempo) melalui WNE. HCM mengimplementasikan nol
  logika approval atau notifikasi paralel.
- **DMS** — semua dokumen (kontrak, scan ID/sertifikat, slip gaji, lampiran cuti) mengalir
  melalui `DocumentService`. HCM mengimplementasikan nol logika penyimpanan/versioning
  paralel.
- **Accounting** — soft dependency, terlingkup hanya pada atribusi cost-center.
  `HCM.org_units` dapat secara opsional dipetakan ke `ACCOUNTING.cost_centers` (§3C) sehingga
  seorang tenant yang menjalankan kedua modul melihat biaya payroll/HR diatribusikan ke
  dimensi cost-center finansial yang sama yang sudah dipakai bersama Accounting dan Purchase
  (`ACCOUNTING_SPECS.md` §3B/§3I, `PURCHASE_SPECS.md` §4/§5) — org chart dan resolusi
  reporting-line HCM berfungsi identik terlepas apakah pemetaan ini, atau Accounting itu
  sendiri, ada.
- **Schedule** — penugasan shift dan cuti yang disetujui dapat secara opsional dicerminkan ke
  Schedule sebagai entri kalender read-only untuk visibilitas kalender terpadu, jika tenant
  mengaktifkan Schedule. HCM tidak memiliki dependensi compile-time apa pun terhadap kelas
  Schedule — pola aman-feature-flag yang sama yang sudah dipakai Schedule sendiri untuk
  dependensi opsionalnya terhadap WNE.

**Kepatuhan Indonesia — prinsip desain inti:** setiap angka statutori (ambang PTKP, tarif TER,
persentase/plafon BPJS, upah minimum regional) berada dalam tabel tarif **effective-dated,
dapat diedit tenant**, tidak pernah konstanta hardcoded atau formula yang dipanggang ke dalam
kode aplikasi. Regulasi (PMK, Permenaker, dekrit UMP/UMK tahunan) berubah pada siklus kira-kira
tahunan; respons rekayasa yang benar adalah "muat baris tabel tarif baru," bukan "kirim deploy
kode" — ini adalah keputusan teknis paling penting tunggal di modul ini dan tidak boleh
dikompromikan demi kecepatan MVP.

**Kebenaran Payroll di atas kecepatan:** tidak seperti setiap keputusan cakupan MVP lain di
platform ini (di mana "luncurkan versi sellable yang lebih sederhana" adalah default),
perhitungan TER/BPJS/THR Payroll harus dibangun dan diuji dengan hati-hati bahkan di MVP,
karena payroll yang salah adalah risiko hukum dan reputasi bagi tenant, bukan sekadar fitur
nice-to-have yang hilang. Kesederhanaan seharusnya datang dari pemangkasan *cakupan* (satu
payroll run per bulan, tanpa engine koreksi multi-periode retroaktif, ekspor CSV bank generik
alih-alih template format per-bank) alih-alih dari memotong sudut pada perhitungan statutori
itu sendiri.

**Queue:** Perhitungan payroll run untuk roster karyawan penuh berjalan sebagai job batch yang
diantre (pola async berdekatan-`notifications` yang sama yang sudah ditetapkan untuk event
lintas-modul), sehingga menghitung payroll tenant besar tidak pernah memblokir UI. Clock-in/out
absensi dan pemeriksaan balance cuti bersifat sinkron — operasi cepat dan menghadap-user,
alasan yang sama yang diterapkan Schedule pada Availability Check miliknya sendiri (3E di
`SCHEDULE_SPECS.md`).

**Ekstensibilitas:** tipe cuti baru, tipe unit organisasi, dan versi tabel tarif statutori
semuanya adalah data yang dapat diedit tenant/dimuat admin — tidak butuh deploy kode,
tuas yang sama yang sudah ditetapkan lookup Role/Lead-source/Ticket-category yang dapat
diedit tenant milik CRM untuk reusabilitas lintas-vertikal.

**Catatan kelayakan jual (marketability)**
- Payroll yang patuh-Indonesia (PPh 21 metode TER + BPJS + THR), *via modul Payroll*, adalah
  diferensiator genuine dibanding software HR internasional generik — layak dijadikan andalan
  dalam demo penjualan untuk pasar SMB/firma-hukum Indonesia. Peran HCM dalam cerita itu
  adalah memasok data karyawan/absensi/cuti yang bersih bagi engine Payroll untuk dijalankan,
  bukan menghitung angkanya sendiri.
- ESS (slip gaji self-service, permintaan cuti, absensi) adalah fitur yang *dilihat* karyawan
  setiap hari bahkan jika HR/finance yang membeli produk — pendorong engagement harian yang
  kuat, yang penting untuk retensi langganan.
- Menjaga ATS/Performance/LMS/Talent/Compensation/Benefits sebagai add-on Future Version yang
  jelas cakupannya (alih-alih pembangunan sekaligus yang terburu-buru) mencerminkan strategi
  upsell "lisensi modul secara terpisah" yang sama yang sudah dipakai untuk pemisahan
  Helpdesk vs After Sales Service milik CRM — masing-masing bisa menjadi tier harga sendiri
  belakangan tanpa perombakan skema, karena tabel stub sudah ada sekarang.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B/3C (inti Employee + Org) → 3D
(Kontrak, termasuk titik masuk hire minimal) → 3F (Leave, disambungkan ke WNE) → 3E (Attendance)
→ **modul Payroll dibangun dan diintegrasikan di sini** (lihat `PAYROLL_SPECS.md` — tabel
tarif statutori, lalu engine run; item pembangunan tunggal terbesar di platform ini,
perlakukan sebagai fase pembangunan tersendiri terhadap event employee/attendance/leave HCM
yang sudah diterbitkan) → 3H (ESS, sebagian besar UI berlingkup-permission atas HCM + sebuah
read-through ke Payroll) → 3A (Dashboard) → lalu revisit 3I/3J/3K/3L/3M/3N/3O sebagai Future
Version begitu MVP tervalidasi dengan tenant nyata.
