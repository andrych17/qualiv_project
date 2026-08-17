# Modul Payroll
## Engine Payroll & Kepatuhan Statutori Indonesia — Modul Core Bersama (dapat berdiri sendiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap tenant yang menjalankan orang (people) pada akhirnya butuh payroll, dan di Indonesia
payroll bukan sekadar aritmatika — ini adalah permukaan kepatuhan: PPh 21 pajak penghasilan
(kini metode TER — Tarif Efektif Rata-rata — sejak PP 58/2023 dan PMK 168/2023), dua program
BPJS (Kesehatan dan Ketenagakerjaan, masing-masing dengan pembagian kontribusi dan batas atas
upahnya sendiri), THR wajib (tunjangan hari raya keagamaan, PP 36/2021), dan pesangon/pesangon
pemutusan hubungan kerja yang diatur UU Cipta Kerja. Jika dibiarkan tidak terselesaikan, atau
ditempel begitu saja pada field "salary" generik:

- Tarif dan bracket statutori (PTKP, kategori TER, persentase/batas atas upah BPJS) berubah
  hampir setiap tahun (lihat misalnya peralihan TER 2024, penyesuaian batas atas upah BPJS
  2025/2026). Meng-hardcode ini berarti deploy kode setiap kali regulasi berubah — tidak dapat
  diterima bagi solo dev yang mendukung run payroll yang sedang berjalan (live).
- PPh 21 punya **dua rezim perhitungan berbeda dalam tahun pajak yang sama**: TER (bulanan,
  Jan–Nov, menggunakan tarif efektif flat per kategori PTKP) dan rekalkulasi progresif Pasal 17
  tahunan (Desember) — salah menangani ini berarti karyawan klien yang diaudit, bukan sekadar
  kesal.
- THR dan pesangon diwajibkan secara hukum, digerakkan formula, dan sensitif waktu (THR harus
  dibayar H-7 sebelum hari raya keagamaan; formula pesangon berbeda menurut alasan pemutusan
  dan masa kerja) — ini persis jenis hal yang diharapkan klien pasar Indonesia agar platform
  menanganinya dengan benar tanpa akrobat spreadsheet manual.
- Setiap modul di ERP yang menyentuh biaya orang (timesheet/pembagian fee Legal, staf gedung
  Property, modul masa depan) pada akhirnya akan menginginkan payroll — membangunnya secara
  generik dan terpisah (decoupled) sekarang menghindari penulisan ulang nanti.
- Tidak ada satu tempat untuk melihat "berapa biaya run payroll ini," "apa yang harus dibayar
  ke kantor pajak dan BPJS bulan ini," atau "apakah payslip ini benar-benar sudah dibayar" —
  rekonsiliasi saat ini manual/berbasis-spreadsheet untuk sebagian besar klien SME, yang
  merupakan titik masalah langsung yang harus dihilangkan modul ini.

**Kebutuhan klien:**
- **Bergantung pada HCM untuk identitas karyawan** — Payroll mengonsumsi `HCM.employees` (FK
  lintas-schema, Core-ke-Core, arah yang sama yang sudah didokumentasikan CRM/DMS/WNE satu sama
  lain) via tabel ekstensi 1:1 `PAYROLL.employee_payroll_profiles`, pola "extend, don't
  duplicate" yang sama yang sudah ditetapkan untuk `PURCHASE.vendor_profiles` dan
  `SALES.customer_sales_profiles`/`customer_credit_profiles` terhadap `CRM.partners`. Sebuah
  tenant tidak bisa mengaktifkan Payroll tanpa HCM aktif — ini adalah dependensi **keras**
  (postur Sales↔CRM), bukan integrasi lunak/opsional yang dipakai setiap modul Core lain
  terhadap WNE/DMS/Schedule. Ini adalah pembalikan yang disengaja dari cakupan standalone
  Payroll yang asli: dua kalkulator PPh21/BPJS yang dipelihara secara independen (satu di HCM,
  satu di sini) adalah risiko kepatuhan yang nyata, bukan sekadar kode duplikat — lihat
  `HCM_SPECS.md` §3G/§5.
- Harus mengikuti hukum payroll Indonesia yang berlaku: PPh 21 (metode TER + annualisasi
  Desember), BPJS Kesehatan, BPJS Ketenagakerjaan (JHT, JP, JKK, JKM, JKP), THR, dan pesangon/
  pemutusan hubungan kerja yang sesuai UU Cipta Kerja.
- Tarif, bracket, dan batas atas statutori harus berupa **konfigurasi yang tenant-independen,
  ter-versi, dan dapat diedit admin** — tidak pernah di-hardcode — karena ini ditetapkan oleh
  regulasi pemerintah, bukan oleh tenant, dan berubah pada jadwal yang tidak dikontrol platform.
- Harus mendukung siklus hidup payroll penuh: run bulanan reguler, run off-cycle, run THR, run
  bonus, gaji terakhir/pemutusan, dan penyesuaian/koreksi pasca-run.
- Setiap run harus bisa diaudit, terkunci (lockable) begitu dibayar, dan digerbang oleh
  approval sebelum pencairan — payroll adalah angka paling sensitif kepercayaan tunggal di
  seluruh ERP.
- Menggunakan ulang WNE untuk approval dan notifikasi, dan DMS untuk penyimpanan dokumen
  (payslip, kwitansi reimbursement, perjanjian pinjaman) — tidak ada kode workflow/notifikasi/
  penyimpanan paralel, aturan yang sama yang sudah diterapkan setiap modul Core lain di proyek
  ini.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — kirim sesuatu yang benar dan dapat dijual dengan cepat;
> tunda integrasi berat (ekspor GL/accounting, API bank langsung, multi-negara) ke Future
> Version.

**MVP (kirim pertama — implementasi cepat, kebenaran statutori tidak dapat ditawar)**
- **Profil Payroll Karyawan** (memperluas `HCM.employees`, tidak menduplikasinya): status pajak
  (PTKP), nomor pendaftaran BPJS, rekening bank, struktur gaji yang ditugaskan, payroll group,
  dan kategori risiko JKK. Identitas, status kepegawaian, dan masa kerja semuanya dibaca
  langsung dari HCM — lihat §5.
- **Input yang digerakkan absensi dan cuti (kini MVP, bukan Future Version — lihat §5).** Jam
  lembur dihitung dari `HCM.attendance_logs` via event `hcm.attendance_logged`, menggunakan
  formula Kepmenaker; cuti tidak berbayar yang disetujui (`hcm.leave_approved`) secara otomatis
  menghasilkan baris pengurangan cuti-tidak-berbayar. Entri manual tetap tersedia sebagai
  fallback, tetapi konsumsi otomatis dari HCM adalah default kapan pun HCM diaktifkan (selalu
  aktif, sesuai catatan dependensi keras §1).
- **Setup Payroll**: Payroll Groups, Payroll Calendars, Salary Structures, Payroll Components
  (definisi earning/deduction), Deduction Rules (logika amortisasi pinjaman/advance), dan —
  krusial — **Tax Rules, BPJS Rules, dan Regulatory Rules yang ter-versi** sehingga perubahan
  tarif tahun depan adalah entri data, bukan deploy.
- **Pemrosesan Payroll**: Payroll Periods, Regular Payroll, Off-Cycle Payroll, THR Payroll,
  Bonus Payroll, Final (termination) Payroll, dan Payroll Adjustment — semuanya dibangun di
  atas satu engine kalkulasi bersama (§3, Engine 3J) sehingga keenam *tipe* run hanya berbeda
  pada konfigurasi/input, bukan enam basis kode terpisah.
- **Input Payroll**: Variable Earnings, Overtime (formula Kepmenaker 1/173), Bonus, Commission,
  Reimbursement, Loans, Salary Advances — semuanya masuk ke engine run yang sama sebagai baris
  item.
- **Engine statutori**: PPh 21 (TER bulanan + rekonsiliasi annualized Desember), BPJS
  Kesehatan, BPJS Ketenagakerjaan (JHT/JP/JKK/JKM/JKP), THR (formula PP 36/2021 termasuk
  pro-rata untuk masa kerja <12 bulan), Severance (tabel pesangon/UPMK/UPH UU Cipta Kerja),
  Regulatory Updates (permukaan admin untuk tabel aturan ter-versi di atas).
- **Payment**: Bank Payment (ekspor file transfer bank — format CSV/Excel per-bank, MVP
  berbasis file, bukan API bank langsung), Payment Batch, Payment Reconciliation (tandai
  dibayar, tangkap kegagalan untuk dijalankan ulang).
- **Reports**: Payslips (PDF, disimpan via DMS), Payroll Reports (ringkasan run, rincian
  biaya), Tax Reports (rekap PPh 21 bulanan + data tahunan diformat untuk Bukti Potong
  1721-A1 / impor Coretax — lihat §5), BPJS Reports (rekap kontribusi diformat untuk unggah
  portal BPJS), Audit Reports.
- **Administration**: Payroll Approval (via workflow WNE, bukan logika kustom), Payroll Lock
  (periode yang sudah dibayar menjadi immutable — koreksi melalui Payroll Adjustment, tidak
  pernah edit langsung), Audit Trail (append-only, mencerminkan pola `access_logs` milik DMS),
  Security (visibilitas data payroll adalah tier permission yang berbeda dan lebih ketat
  dibanding akses ERP umum — lihat §5).

**Future Version (pasca-peluncuran, begitu ada penggunaan/revenue nyata yang menjustifikasi
pembangunannya)**
- ~~**Ekspor Accounting/GL** — entri siap-jurnal per run payroll, begitu modul Core
  Finance/Accounting ada untuk menerimanya.~~ **Terselesaikan** — Accounting kini ada dan
  memiliki ini via engine Payroll GL Posting-nya (`ACCOUNTING_SPECS.md` §3S), sebuah consumer
  tipis dari event `payroll.run_paid` milik Payroll sendiri (§3-Admin di bawah), mencerminkan
  pola yang sudah dipakai untuk GL posting Inventory (`ACCOUNTING_SPECS.md` §3H). Payroll tetap
  tidak memegang logika GL apa pun — ia mempublikasikan angka yang sudah dihitung, pembagian
  tanggung jawab yang sama yang sudah dimiliki Inventory terhadap Accounting.
- Integrasi **API pencairan bank langsung** (per bank), menggantikan alur ekspor-file MVP.
  Secara alami kandidat ekstraksi yang terjustifikasi nanti (integrasi API eksternal, variasi
  per-bank) — ekspor file adalah MVP yang tepat karena berfungsi dengan bank *mana pun* hari
  ini.
- **Pengajuan API langsung e-SPT / Coretax** — MVP menghasilkan data ekspor yang diformat
  dengan benar; pengajuan API langsung adalah fast-follow begitu API integrasi Coretax stabil
  untuk sistem payroll pihak ketiga.
- **Portal self-service karyawan** (melihat payslip sendiri, mengajukan reimbursement/cuti) —
  v1 dioperasikan admin/HR; self-service adalah lapisan UX di atas data yang sama, bukan
  perubahan skema.
- **Payroll multi-negara/multi-mata-uang** — di luar cakupan; modul ini Indonesia-first secara
  desain (PTKP/TER/BPJS/THR/pesangon semuanya spesifik-Indonesia), tetapi arsitektur
  rule-versioning (§5) adalah yang memungkinkan aturan negara kedua ditambahkan tanpa penulisan
  ulang.
- **Administrasi benefit** (asuransi di luar BPJS, tunjangan-in-kind) di luar yang tercakup di
  Payroll Components hari ini.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Kesehatan payroll sekilas: status periode saat ini (open/processing/approved/paid/locked),
  headcount di run saat ini, total gross/net/biaya-employer untuk periode tersebut, ringkasan
  liabilitas statutori (PPh 21 yang dipotong, kontribusi BPJS employer + employee yang harus
  dibayar).
- Antrean "perlu perhatian": run yang menunggu approval, THR jatuh tempo dalam N hari, cicilan
  pinjaman yang gagal terpotong (net pay tidak cukup), kegagalan batch pembayaran.
- Aksi cepat: mulai run baru, buka periode saat ini, lompat ke Regulatory Updates jika tanggal
  efektif sebuah aturan mendekati/sudah kedaluwarsa.

**Layout**
- Atas: 4 kartu ringkasan — Employees in Current Run, Total Net Pay (periode saat ini),
  Pending Approvals, Upcoming THR/Statutory Due Dates.
- Utama: tabel bertab — "Active Runs" | "Pending Approval" | "Payment Batches" | "Alerts".
- Setiap baris menggunakan **Status Rail** bersama (sesuai `DESIGN.md`) — `info` =
  draft/processing, `warning` = menunggu approval atau jatuh tempo segera, `success` =
  dibayar/selesai, `danger` = pembayaran gagal, aturan kedaluwarsa, atau net pay negatif.

**Aturan / logika**
- Otomatis terlingkup-tenant (DB-per-tenant — tanpa kolom `tenant_id`, sesuai `CLAUDE.md` §7,
  konvensi yang sama dengan Schedule/DMS/CRM).
- Visibilitas dashboard payroll sendiri digerbang oleh tier security Payroll yang lebih ketat
  (§5) — dashboard ini tidak terlihat oleh user ERP umum secara default.

## 3B. Setup Payroll — Payroll Groups

**Tujuan:** Payroll Group adalah unit yang diproses sebuah Payroll Run — biasanya satu badan
hukum atau satu kohort frekuensi-bayar (misalnya "Monthly Staff — PT ABC", "Daily Workers — PT
ABC").

- Field: name, referensi badan hukum (free-text/lookup, belum ada modul Legal-Entity
  terpisah), Payroll Calendar default, Salary Structure default (opsional), kategori risiko
  JKK default (lihat 3-BPJS), flag active.
- Setiap Employee termasuk dalam tepat satu Payroll Group pada satu waktu (riwayat disimpan
  saat penugasan ulang).

## 3B. Setup Payroll — Payroll Calendars

- Field: name, frekuensi bayar (bulanan / semi-bulanan / mingguan / harian), aturan
  cutoff-day (misalnya "tanggal 26 bulan sebelumnya sampai tanggal 25 bulan berjalan"), aturan
  pay date (misalnya "hari kerja terakhir bulan tersebut", dengan flag "geser lebih awal" yang
  sadar-hari-libur-nasional — membaca kalender Schedule jika terpasang, jika tidak pemeriksaan
  hari kerja sederhana).
- Menghasilkan baris `payroll_periods` di muka (misalnya 12 bulan ke depan) sehingga setup
  terjadi sekali setahun, bukan sekali per periode.

## 3B. Setup Payroll — Salary Structures

- Salary Structure adalah template bernama dari Payroll Components dengan jumlah/formula
  default (misalnya "Staff Grade 2": Basic Salary tetap + Position Allowance tetap + Transport
  Allowance tetap + BPJS/PPh21 otomatis termasuk berdasarkan tipe component-nya sendiri).
- Ditugaskan ke Employee (atau Payroll Group sebagai default); override per-employee
  diperbolehkan di level penugasan-component individual tanpa forking seluruh structure.

## 3B. Setup Payroll — Payroll Components

**Tujuan:** blok bangunan atomik dari setiap payslip — setiap earning dan setiap deduction,
statutori atau bukan, adalah sebuah Payroll Component.

- Field: code, name, type (`earning` / `deduction`), category (`fixed` / `formula` /
  `statutory` / `variable-input`), basis kalkulasi (jumlah flat, % dari component dasar
  bernama misalnya "% dari Basic Salary", atau referensi ke engine statutori — PPh21 / BPJS
  Kesehatan / BPJS Ketenagakerjaan), flag taxable (apakah component ini dihitung ke gross PPh
  21?), flag BPJS-basis (apakah dihitung ke basis kontribusi BPJS?), placeholder GL-account
  (nullable, untuk ekspor accounting Future Version), is_active.
- Component statutori (PPh 21, BPJS Kesehatan, BPJS Ketenagakerjaan) adalah baris **system-
  defined, read-only** yang mendelegasikan angka aktualnya ke engine statutori terkait (§3
  Statutory) alih-alih menyimpan formula di sini — inilah yang menjaga perubahan tarif tetap
  menjadi perubahan data di tabel Regulatory Rules, bukan edit Payroll Component.

## 3B. Setup Payroll — Deduction Rules

- Konfigurasi untuk deduction non-statutori dengan bentuk berulang/mengamortisasi: Loans
  (jangka bulan, metode bunga — flat atau tanpa bunga, MVP tidak butuh bunga majemuk), Salary
  Advances (cicilan tunggal atau split jangka pendek), dan deduction berulang mana pun yang
  didefinisikan tenant (misalnya iuran koperasi).
- Mendefinisikan **perilaku cicilan (installment)** yang dikonsumsi 3-Loans/3-Advances di
  bawah: bagaimana cicilan bulanan dihitung dan apa yang terjadi jika net pay tidak cukup
  (lewati dan geser ke depan vs. deduction parsial — dapat dikonfigurasi tenant, default ke
  lewati-dan-geser-ke-depan dengan flag yang diangkat di dashboard).

## 3B. Setup Payroll — Tax Rules (PPh 21)

**Tujuan:** sumber kebenaran ter-versi dan dapat diedit admin untuk PPh 21 — inilah yang
memungkinkan platform menyerap perubahan regulasi (tabel tarif, jumlah PTKP, bracket) sebagai
baris baru dengan `effective_date`, tidak pernah deploy kode.

- `ptkp_statuses`: lookup (`TK/0`, `TK/1`, `TK/2`, `TK/3`, `K/0`, `K/1`, `K/2`, `K/3`, ...) —
  jumlah PTKP tahunan per status, ter-versi berdasarkan `effective_date` (PTKP sudah berubah
  karena regulasi pemerintah sebelumnya dan akan berubah lagi).
- `ter_categories`: TER Category A / B / C, masing-masing dipetakan ke status PTKP yang masuk
  ke dalamnya sesuai PMK 168/2023 (Category A: TK/0, TK/1, K/0; Category B: TK/2, TK/3, K/1,
  K/2; Category C: K/3), ter-versi.
- `ter_rate_brackets`: per kategori, bracket penghasilan bruto bulanan → tarif efektif (%),
  ter-versi berdasarkan `effective_date` — ini tabel yang benar-benar dipakai untuk pemotongan
  bulanan Jan–Nov.
- `ter_daily_rates`: bracket tarif-efektif-harian (0% sampai Rp450.000/hari, 0,5% di atasnya,
  sesuai aturan saat ini) untuk pekerja tidak-tetap/harian, ter-versi.
- `pph21_progressive_brackets`: bracket progresif Pasal 17 tahunan (5/15/25/30/35% sesuai hukum
  saat ini), ter-versi — dipakai untuk rekalkulasi annualized Desember dan untuk item
  penghasilan tidak teratur (bonus, THR, pesangon) yang menggunakan metode annualisasi alih-
  alih TER bulanan.
- Setiap tabel di atas membawa `effective_date` + `is_active`; engine kalkulasi (§3-PPh21)
  selalu meresolusi "versi mana yang berlaku untuk periode ini" alih-alih mengasumsikan baris
  terbaru.

## 3B. Setup Payroll — BPJS Rules

**Tujuan:** pola ter-versi yang sama dengan Tax Rules, untuk kedua program BPJS.

- `bpjs_kesehatan_rules`: % employer, % employee, batas atas upah (Rp), ter-versi berdasarkan
  `effective_date`.
- `bpjs_ketenagakerjaan_rules`: satu baris per sub-program (JHT, JP, JKK, JKM, JKP) ×
  `effective_date` ter-versi, masing-masing dengan % employer dan % employee (JKK/JKM/JKP
  hanya-employer sesuai aturan saat ini, tetapi skemanya tidak mengasumsikan itu — itu data,
  bukan kode) dan batas atas upah jika berlaku (khususnya JP).
- `jkk_risk_categories`: lookup tier risiko JKK (sangat-rendah sampai sangat-tinggi) dengan %
  kontribusi employer terkaitnya, ditugaskan per Payroll Group atau per Employee (sebagian
  tenant mencampur staf kantor dan staf lapangan di bawah kategori risiko berbeda).

## 3B. Setup Payroll — Regulatory Rules (permukaan admin Regulatory Updates)

- Satu layar admin yang menampilkan setiap tabel aturan ter-versi di atas (Tax Rules + BPJS
  Rules) dalam satu tempat, dengan workflow "regulasi baru": masukkan versi baru + tanggal
  efektif, pratinjau dampaknya pada payroll contoh sebelum berlaku, dan lihat diff terhadap
  versi aktif saat ini.
- Opsional: alirkan versi regulasi baru melalui workflow approval WNE sebelum diaktifkan —
  untuk tenant yang menginginkan pasang mata kedua sebelum perubahan tarif berlaku pada payroll
  nyata (`workflow_code = payroll.regulatory_rule_activation`).

## 3C. Payroll Periods

- Satu baris per siklus Payroll Group × Payroll Calendar: start/end periode, tanggal cutoff,
  tanggal bayar terjadwal, status (`open` → `processing` → `approved` → `paid` → `locked`).
- Periode hanya menerima run Regular Payroll baru selagi `open`; begitu `locked` (lihat
  3-Admin Payroll Lock), satu-satunya jalur untuk mengubah apa pun adalah run Payroll
  Adjustment yang merujuk periode yang terkunci.

## 3D. Regular Payroll (Run Engine)

**Fungsi / fitur**
- Pilih Payroll Group + Payroll Period → engine run menarik: Salary Structure setiap karyawan
  aktif, Payroll Inputs apa pun yang dicatat terhadap periode ini (overtime, variable earnings,
  bonus, commission, reimbursement), cicilan pinjaman/advance yang jatuh tempo, lalu menghitung
  deduction statutori via engine PPh 21 / BPJS, menghasilkan satu `payroll_run_line` per
  employee (= payslip draft).
- Dapat ditinjau sebelum submission: tabel per-item per employee, dapat diedit di level input
  (bukan meng-override angka statutori yang sudah dihitung secara manual) sebelum submit-untuk-
  approval.

**Aturan / logika**
- Setiap run berstatus `draft` sampai disubmit; submission memicu `WorkflowRequested` WNE
  (`workflow_code = payroll.run_approval`) alih-alih Payroll mengimplementasikan chain approval
  sendiri — pola reuse yang sama dengan setiap modul lain.
- Net pay < 0 (deduction melebihi earning) memblokir submission dengan error yang jelas per
  employee, sesuai panduan suara `DESIGN.md`, alih-alih diam-diam menghasilkan payslip negatif.

## 3E. Off-Cycle Payroll

- Engine run yang sama dengan Regular Payroll, tetapi tidak terikat periode kalender normal —
  untuk koreksi yang dibutuhkan *sebelum* run normal berikutnya, pembayaran sekali-jalan, atau
  subset karyawan (misalnya karyawan baru yang butuh advance pay segera). Membutuhkan kode
  alasan.

## 3F. THR Payroll (Tunjangan Hari Raya)

**Tujuan:** tunjangan hari raya keagamaan statutori (PP 36/2021) — wajib, digerakkan formula,
sensitif waktu (jatuh tempo paling lambat H-7 sebelum hari raya keagamaan terkait).

- Formula: karyawan dengan masa kerja berkelanjutan ≥12 bulan mendapat 1× gaji bulanan (Basic +
  tunjangan tetap sesuai flag basis-THR component tenant); karyawan dengan masa kerja <12 bulan
  mendapat jumlah pro-rata (`bulan kerja / 12 × gaji bulanan`).
- `thr_calculations` menyimpan snapshot masa kerja, input formula, dan hasil per employee —
  dapat diaudit terlepas dari run itu sendiri, karena kepatuhan THR sering diperiksa.
- THR dikenakan pajak dengan **metode annualisasi** (penghasilan tidak teratur), bukan TER
  bulanan — engine PPh 21 (§3-PPh21) mengekspos mode kalkulasi terpisah untuk ini.
- Pengingat terjadwal (via WNE) dipicu menjelang setiap tanggal hari raya keagamaan yang
  dikonfigurasi sehingga run THR tidak dimulai terlalu terlambat untuk memenuhi deadline H-7.

## 3G. Bonus Payroll

- Engine run yang sama, untuk bonus diskresioner/kinerja. Seperti THR, dikenakan pajak via
  metode annualisasi alih-alih TER bulanan (perlakuan penghasilan tidak teratur).
- Mendukung entri jumlah per-employee sederhana atau impor massal (CSV) untuk pool bonus yang
  lebih besar — impor massal adalah jalur MVP untuk menghindari membangun modul manajemen
  kinerja penuh.

## 3H. Final Payroll (Termination)

**Tujuan:** payslip terakhir untuk karyawan yang diberhentikan — gaji reguler terakhir,
pencairan cuti tidak terpakai jika dikonfigurasi, dan pesangon statutori jika berlaku.

- Field: tanggal pemutusan, alasan pemutusan (pengunduran diri / pemutusan-dengan-sebab /
  redundansi / pensiun / akhir-kontrak / kematian — alasan ini menentukan formula pesangon mana
  yang berlaku sesuai UU Cipta Kerja / PP 35/2021).
- `severance_calculations`: menghitung Uang Pesangon, Uang Penghargaan Masa Kerja (UPMK), dan
  Uang Penggantian Hak (UPH) sesuai tabel pengali berbasis-masa-kerja di PP 35/2021, digerakkan
  dari `severance_rule_tables` yang ter-versi (rasional yang sama dengan Tax/BPJS Rules —
  tabel-tabel ini ditetapkan oleh regulasi, bukan tenant).
- PPh 21 final atas pesangon menggunakan perlakuan statutorinya sendiri (band tarif
  final/terpisah sesuai regulasi saat ini) alih-alih TER reguler atau metode annualisasi —
  engine PPh21 mengekspos mode kalkulasi ketiga untuk ini.
- Saat selesai: status employee berpindah ke `terminated`, pengingat de-registrasi BPJS dipicu
  via WNE, dan employee berhenti muncul di run Payroll Group masa depan.

## 3I. Payroll Adjustment

- Satu-satunya jalur sah untuk mengubah angka periode `paid`/`locked`: membuat run Adjustment
  yang merujuk run asli + employee(s) yang terdampak + alasan, menghasilkan payslip delta
  (positif atau negatif) alih-alih memutasi riwayat — mencerminkan prinsip "jangan pernah
  overwrite, selalu versi" milik DMS.
- Adjustment masuk ke engine statutori yang sama (penyesuaian penghasilan kena pajak memicu
  ulang kalkulasi delta PPh 21, bukan override manual) sehingga rekap pajak akhir tahun tetap
  benar.

## 3J. Payroll Run Calculation Engine (dipakai bersama 3D–3I)

**Tujuan:** satu engine yang dipanggil keenam tipe run — menjaga keenam *proses* tetap tipis
sebagai titik konfigurasi/entri alih-alih enam kalkulator paralel.

`PayrollRunEngine::calculate(runId)`:
1. Resolusi kumpulan aturan ter-versi yang berlaku (Tax Rules, BPJS Rules, Severance Rules
   jika berlaku) per tanggal periode run — tidak pernah "yang terbaru," selalu "yang berlaku
   pada tanggal ini," sehingga perubahan regulasi di tengah tahun tidak pernah secara
   retroaktif mengubah periode yang sudah dibayar.
2. Kumpulkan component earning (default struktur + Payroll Inputs untuk periode tersebut).
3. Kumpulkan component deduction (default struktur + cicilan pinjaman/advance yang jatuh
   tempo + Deduction Rules).
4. Hitung kontribusi BPJS Kesehatan + BPJS Ketenagakerjaan (pembagian employee + employer)
   terhadap aturan batas-atas-upah masing-masing program.
5. Hitung PPh 21 dalam mode yang sesuai dengan tipe run (TER bulanan untuk Regular/Off-Cycle;
   annualized untuk THR/Bonus; tarif final/terpisah untuk Final Payroll/pesangon; rekonsiliasi
   annualized Desember untuk run Regular terakhir tahun kalender).
6. Hasilkan `payroll_run_lines` + `payroll_run_line_components` (rincian per-item lengkap,
   data sumber untuk PDF Payslip).

## 3K. Payroll Inputs — Variable Earnings, Overtime, Bonus, Commission, Reimbursement

- **Variable Earnings**: entri earning bebas-bentuk berulang/sekali-jalan yang tidak tercakup
  oleh component Salary Structure tetap (misalnya shift differential), ditandai ke sebuah
  periode.
- **Overtime**: jam × tarif, dihitung sesuai basis formula Kepmenaker (tarif per jam = 1/173 ×
  upah bulanan, dengan pengali statutori untuk overtime hari-kerja/akhir-pekan/hari-libur) —
  tabel pengali itu sendiri hidup dalam lookup ter-versi kecil, disiplin rule-versioning yang
  sama dengan tabel statutori lain.
- **Bonus / Commission**: dimasukkan di sini sebagai *input* yang dikonsumsi Bonus Payroll (3G)
  atau ditambahkan ke run Regular jika tenant memperlakukannya sebagai penghasilan bulanan
  biasa sebagai gantinya.
- **Reimbursement**: request → approve (via WNE) → pencairan sebagai baris earning non-kena-
  pajak; kwitansi/bukti dilampirkan via **DMS** (`DocumentService::attach()`), bukan upload
  paralel — pola integrasi yang sama yang sudah diekspos DMS ke setiap modul lain.
- **Loans / Salary Advances**: lihat 3B Deduction Rules untuk konfigurasi; `employee_loans` +
  `loan_installments` melacak pokok, saldo sisa, dan auto-deduction per periode;
  `salary_advances` adalah varian tunggal/jangka-pendek yang lebih ringan. Dokumen perjanjian
  pinjaman juga dilampirkan via DMS.

## 3-PPh21. Engine PPh 21 (Statutory)

- `calculateMonthlyTER(employee, grossIncome, period)`: meresolusi status PTKP employee →
  kategori TER (A/B/C) → bracket berlaku dari `ter_rate_brackets` per tanggal periode →
  pemotongan = grossIncome × rate. Ini mode bulanan Jan–Nov.
- `calculateAnnualizedReconciliation(employee, taxYear)`: dijalankan otomatis sebagai bagian
  run Regular Payroll Desember — menjumlahkan penghasilan bruto aktual tahun tersebut,
  menerapkan `pph21_progressive_brackets` + PTKP tahunan employee untuk mendapatkan pajak
  tahunan *sebenarnya*, membandingkan terhadap TER yang dipotong Jan–Nov, dan mem-posting
  selisihnya (kurang/lebih-potong) sebagai baris PPh 21 Desember. Ini mekanisme yang benar-
  benar diwajibkan regulasi (TER menyederhanakan pemotongan bulanan; ia tidak menggantikan
  liabilitas tahunan).
- `calculateIrregularIncome(employee, amount, incomeType)`: kalkulasi metode-annualisasi yang
  dipakai run THR (3F) dan Bonus (3G).
- `calculateFinalSeverance(employee, severanceAmount)`: kalkulasi tarif terpisah/final yang
  dipakai Final Payroll (3H).
- `pph21_calculations`: satu baris per employee per periode per mode kalkulasi — jejak audit
  dan sumber untuk Tax Reports (§3 Reports) dan ekspor Bukti Potong 1721-A1 tahunan.

## 3-BPJS. Engine BPJS (Statutory)

- `BpjsKesehatanEngine::calculate(employee, wageBase, asOfDate)`: menerapkan % employer/
  employee ter-versi terhadap `min(wageBase, wageCap)` dari `bpjs_kesehatan_rules`.
- `BpjsKetenagakerjaanEngine::calculate(employee, wageBase, asOfDate)`: menerapkan setiap
  sub-program (JHT, JP, JKK, JKM, JKP) dari `bpjs_ketenagakerjaan_rules`, menggunakan kategori
  risiko JKK yang ditugaskan ke employee, masing-masing terhadap batas atas upahnya sendiri
  jika ada (khususnya JP).
- Kedua engine menulis ke `bpjs_kesehatan_contributions` / `bpjs_ketenagakerjaan_contributions`
  (per employee, per periode, jumlah employer + employee dipisah) — ini sumber langsung untuk
  ekspor BPJS Reports (§3 Reports).

## 3-Payment. Bank Payment / Payment Batch / Payment Reconciliation

- **Payment Batch**: mengelompokkan satu atau lebih `payroll_run_lines` yang approved/paid ke
  dalam batch pencairan (biasanya satu per Payroll Group per periode, tetapi mendukung batch
  parsial/split untuk skenario multi-bank).
- **Bank Payment**: mengekspor batch ke format file spesifik-bank (CSV/Excel, per template
  `bank_master`) — MVP berbasis file (unggah ke internet banking secara manual atau via portal
  bulk-transfer bank itu sendiri), bukan integrasi API langsung (lihat §2 Future Version).
- **Payment Reconciliation**: menandai setiap baris batch `paid` / `failed` (entri manual MVP,
  atau impor massal file status-return bank jika bank menyediakannya), dengan baris `failed`
  ditampilkan di Dashboard dan dapat di-batch-ulang tanpa menjalankan ulang kalkulasi payroll.

## 3-Reports. Reports

- **Payslips**: PDF per-employee, dihasilkan dari `payroll_run_lines` +
  `payroll_run_line_components`, disimpan via **DMS** (`subject_type = 'payroll.run_line'`)
  sehingga riwayat versi/audit/retensi diwarisi secara gratis alih-alih dibangun ulang —
  disiplin reuse yang sama seperti di tempat lain di proyek ini.
- **Payroll Reports**: ringkasan run, biaya-per-Payroll-Group, tren biaya seiring waktu.
- **Tax Reports**: rekap pemotongan PPh 21 bulanan (untuk pengajuan e-Bupot/Coretax) dan data
  pajak karyawan tahunan diformat sesuai layout Bukti Potong 1721-A1 — MVP menghasilkan data
  ekspor yang terstruktur dengan benar; pengajuan API Coretax langsung adalah Future Version
  (§2).
- **BPJS Reports**: rekap kontribusi bulanan per program, diformat untuk unggah ke portal
  employer BPJS Kesehatan/Ketenagakerjaan.
- **Accounting Reports**: placeholder di MVP (ekspor ringkasan biaya/liabilitas per-run, CSV)
  — ekspor jurnal GL penuh adalah Future Version, menunggu modul Core Finance/Accounting untuk
  menerimanya (lihat §2).
- **Audit Reports**: me-render `payroll_access_logs` (3-Admin) dengan filter per employee, run,
  aktor, aksi — laporan yang menghadap kepatuhan untuk "siapa melihat/mengubah apa."

## 3-Admin. Administration — Payroll Approval, Payroll Lock, Audit Trail, Security

- **Payroll Approval**: bukan engine kustom — setiap submission run adalah `WorkflowRequested`
  WNE (`workflow_code = payroll.run_approval`, dengan kode berbeda per tipe run jika tenant
  menginginkan chain approval berbeda misalnya untuk Final Payroll vs. Regular). Payroll tidak
  pernah mengimplementasikan state machine approval sendiri, sesuai aturan reuse-WNE di seluruh
  proyek.
- **Payroll Lock**: begitu run ditandai `paid`, `payroll_period`-nya (dan setiap
  `payroll_run_line` di dalamnya) berpindah `locked` — tidak ada edit langsung yang mungkin di
  lapisan aplikasi; satu-satunya jalur ke depan adalah Payroll Adjustment (3I) yang merujuk
  periode yang terkunci. Berpindah ke `paid` juga memicu `payroll.run_paid`
  (`subject_type = 'payroll.payroll_runs'`, membawa total per-component run berdasarkan
  kategori relevan-GL — gross, net, PPh 21 yang dipotong, BPJS employee, BPJS employer,
  deduction lain) — **Accounting**, jika terpasang, mengonsumsi ini untuk mem-posting jurnal GL
  run tersebut (`ACCOUNTING_SPECS.md` §3S). Payroll memicu event ini tanpa syarat; jika
  Accounting tidak terpasang untuk tenant tersebut, event ini sekadar tidak punya listener,
  postur "tidak boleh throw jika X tidak ada" yang sama yang dipakai setiap dependensi
  lintas-modul lunak lain di platform ini.
- **Audit Trail**: `payroll_access_logs` — append-only, satu baris per aksi (`view_payslip`,
  `run_created`, `run_submitted`, `run_approved`, `run_paid`, `run_locked`,
  `adjustment_created`, `regulatory_rule_changed`, `employee_salary_changed`, ...), aktor,
  timestamp, referensi subjek — postur audit immutable yang sama seperti `dms.access_logs`.
- **Security**: Payroll memperkenalkan **tier permission yang lebih ketat** dibanding RBAC ERP
  umum — "bisa melihat modul ini ada" ≠ "bisa melihat gaji employee mana pun" ≠ "bisa melihat
  gaji semua employee" ≠ "bisa approve run" ≠ "bisa edit Regulatory Rules." MVP
  mengimplementasikan ini sebagai set peran khusus-Payroll kecil yang tetap (Payroll Viewer /
  Payroll Operator / Payroll Approver / Payroll Admin) dilapisi di atas auth umum apa pun yang
  dipakai platform, alih-alih engine ACL kustom penuh — sesuai bias MVP yang sudah dipakai di
  tempat lain (flag level-folder DMS alih-alih ACL per-dokumen).

---

# 4. Penyimpanan

**Database (schema `PAYROLL`, DB tenant — konsisten dengan `CLAUDE.md` §7A; tanpa kolom
`tenant_id`, isolasi adalah batas database):**

**Tabel master / setup**
- `PAYROLL.employee_payroll_profiles` — ekstensi 1:1 dari `HCM.employees.id` (FK lintas-
  schema): ref status PTKP, nomor BPJS, ref payroll group, ref salary structure, ref kategori
  risiko JKK. Tidak ada field nama/status-kepegawaian/masa-kerja di sini — itu dibaca dari
  `HCM.employees` saat kalkulasi, tidak pernah disalin ke dalam.
- `PAYROLL.employee_bank_accounts` — bank, nomor rekening, nama pemegang rekening, flag
  primary.
- `PAYROLL.payroll_groups`
- `PAYROLL.payroll_calendars`
- `PAYROLL.salary_structures`
- `PAYROLL.salary_structure_components` — pivot: structure × component × jumlah/formula
  default.
- `PAYROLL.payroll_components` — definisi earning/deduction (lihat 3B).
- `PAYROLL.grades` — lookup job-grade sederhana opsional, dirujuk oleh Salary Structures. Ini
  adalah field tagging ringan untuk penugasan salary-structure, bukan sistem
  banding/grading terstruktur yang dijelaskan submodul Compensation HCM sebagai Future Version
  (`HCM_SPECS.md` §3M) — item itu mencakup band gaji formal *per pekerjaan* dengan siklus
  comp-review; `PAYROLL.grades` hanyalah label opsional yang dipakai Payroll hari ini untuk
  mengelompokkan employee ke sebuah Salary Structure. Ketika submodul Compensation HCM
  akhirnya dibangun, ia bisa menggantikan atau memetakan ke field ini tanpa Payroll perlu
  berubah lebih dulu.
- `PAYROLL.deduction_rule_configs` — konfigurasi perilaku Loans/Advances/deduction-berulang.
- `PAYROLL.loan_types`
- `PAYROLL.reimbursement_categories`
- `PAYROLL.bank_master` — template format file pembayaran bank.
- `PAYROLL.jkk_risk_categories`

**Tabel aturan statutori ter-versi (§3B Tax/BPJS/Regulatory Rules)**
- `PAYROLL.ptkp_statuses`
- `PAYROLL.ter_categories`
- `PAYROLL.ter_rate_brackets`
- `PAYROLL.ter_daily_rates`
- `PAYROLL.pph21_progressive_brackets`
- `PAYROLL.overtime_multiplier_rules`
- `PAYROLL.bpjs_kesehatan_rules`
- `PAYROLL.bpjs_ketenagakerjaan_rules`
- `PAYROLL.severance_rule_tables` — pengali pesangon/UPMK/UPH UU Cipta Kerja / PP 35/2021.
- Semua tabel di atas membawa `effective_date` + `is_active`; tidak ada yang pernah di-hard-
  delete (versi yang digantikan tetap ada untuk rekalkulasi/audit historis run).

**Tabel transaksi / run**
- `PAYROLL.payroll_periods`
- `PAYROLL.payroll_runs` — header: type (`regular`/`off_cycle`/`thr`/`bonus`/`final`/
  `adjustment`), payroll group, period, status, `workflow_instance_id` (nullable, referensi
  informasional ke WNE, sesuai pola seam `subject_type`/`subject_id` yang sudah dipakai di
  tempat lain — Payroll tidak foreign-key ke schema WNE).
- `PAYROLL.payroll_run_lines` — header payslip per-employee: gross, total deduction, net,
  biaya employer.
- `PAYROLL.payroll_run_line_components` — rincian per-item per component.
- `PAYROLL.pph21_calculations` — detail audit per employee/periode/mode (TER / annualized /
  irregular / final-severance).
- `PAYROLL.bpjs_kesehatan_contributions`
- `PAYROLL.bpjs_ketenagakerjaan_contributions` — satu baris per sub-program (JHT/JP/JKK/JKM/
  JKP) per employee per periode.
- `PAYROLL.thr_calculations`
- `PAYROLL.severance_calculations`
- `PAYROLL.overtime_entries`
- `PAYROLL.variable_earning_entries`
- `PAYROLL.commission_entries`
- `PAYROLL.reimbursement_requests`
- `PAYROLL.employee_loans`
- `PAYROLL.loan_installments`
- `PAYROLL.salary_advances`
- `PAYROLL.payment_batches`
- `PAYROLL.payment_batch_lines`
- `PAYROLL.payment_reconciliations`
- `PAYROLL.payroll_access_logs` — jejak audit, append-only (tanpa update/delete di lapisan
  aplikasi, aturan yang sama dengan `dms.access_logs`).

**Object File (sesuai `CLAUDE.md` §7B):**
```text
tenant_001/PAYROLL/
├── payslips/{payroll_run_line_id}/v{n}.pdf          # via DMS, subject_type = payroll.run_line
├── reimbursements/{reimbursement_request_id}/       # kwitansi, via DMS
└── loans/{employee_loan_id}/                        # perjanjian pinjaman, via DMS
```
- Payroll tidak mengimplementasikan jalur penyimpanan objek sendiri — setiap file berada di
  bawah DMS dengan `subject_type`/`subject_id` yang sesuai, mewarisi versioning/retensi/audit
  secara gratis.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, postur monolith-modular yang sama dan bentuk internal yang
sama dengan WNE/DMS/CRM/Schedule (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`,
`Enums/`, `Routes/` di bawah `app/Modules/Payroll/`). Tidak ada ekstraksi microservice di MVP —
kalkulasi payroll adalah pekerjaan batch yang ringan-CPU, cukup-sinkron, bukan kandidat di
bawah kriteria ekstraksi `CLAUDE.md` §2. Tinjau ulang hanya jika/ketika generasi PDF payslip
pada volume sangat tinggi menjadi masalah throughput (rasional yang sama yang sudah diterapkan
pada OCR DMS).

**Kenapa Payroll tidak lagi memiliki tabel Employee sendiri:** Payroll awalnya dispesifikasikan
dengan tabel `employees` minimal miliknya sendiri dengan asumsi belum ada modul HR di urutan
pembangunan. HCM (`HCM_SPECS.md`) kini memiliki peran itu. `employee_payroll_profiles` milik
Payroll memperluas `HCM.employees` persis seperti `PURCHASE.vendor_profiles` memperluas
`CRM.partners` — atribut statutori/pemrosesan hidup di Payroll, segala sesuatu tentang siapa
orang itu (nama, NIK, tipe kontrak, masa kerja, posisi organisasi, status pemutusan) hidup di
HCM dan dibaca via FK lintas-schema, tidak pernah disalin. Ini menghilangkan risiko dua-tempat-
untuk-dipelihara yang seharusnya diciptakan sepasang tabel employee independen untuk data
berisiko-kepatuhan-tertinggi di platform ini.

**Kenapa tarif statutori adalah data, bukan kode — keputusan arsitektural inti modul ini:**
PTKP, kategori/bracket TER, persentase/batas-atas-upah BPJS, pengali overtime, dan pengali
pesangon semuanya ditetapkan oleh regulasi pemerintah Indonesia pada jadwal di luar kontrol
platform (PTKP dan batas atas BPJS keduanya sudah berubah dalam dua tahun terakhir). Setiap
satu dari ini hidup dalam tabel ter-versi (`effective_date` + `is_active`), diresolusi oleh
engine kalkulasi "per" tanggal yang relevan — tidak pernah di-hardcode dalam kelas Service, dan
tidak pernah dimutasi di tempat (regulasi baru adalah baris baru, baris lama tetap ada untuk
rekalkulasi historis). Inilah yang membuat "kepatuhan regulasi Indonesia" menjadi fitur yang
berkelanjutan bagi solo dev alih-alih deploy pemadam-kebakaran tahunan — dan ini poin
kelayakan-jual yang genuin: "tarif diperbarui tanpa menunggu rilis software" adalah jawaban
nyata untuk pertanyaan pembeli "apa yang terjadi ketika aturan pajak berubah."

**PPh 21 — tiga mode kalkulasi berbeda, satu engine:** TER bulanan (Jan–Nov reguler),
rekonsiliasi Pasal 17 annualized Desember, dan annualisasi penghasilan tidak teratur (THR/
bonus) — plus mode keempat, tarif final/terpisah untuk pesangon. Pastikan *pemilihan mode*
benar per tipe run; ini bagian dengan risiko-kepatuhan-tertinggi tunggal dari modul ini dan
tempat yang paling layak diberi cakupan test.

**Batas atas upah BPJS berlaku per-program, bukan sekali:** JP punya batas atasnya sendiri
yang berbeda dari (dan biasanya lebih rendah dari) batas atas apa pun yang diterapkan BPJS
Kesehatan; JHT/JKK/JKM dalam aturan saat ini tidak punya batas atas. Modelkan batas atas pada
`bpjs_*_rules` per sub-program, bukan sebagai satu field "batas atas upah BPJS" tunggal.

**Integrasi lintas-modul (HCM kini dependensi keras; semua yang lain tetap terpisah/digerakkan-
event, seam yang sama dengan setiap modul Core lain):**
- **HCM** — dependensi keras. Payroll membaca `HCM.employees` langsung (FK lintas-schema)
  untuk identitas/status-kepegawaian/masa-kerja, dan berlangganan `hcm.employee_hired`,
  `hcm.employee_terminated`, `hcm.employee_position_changed`, `hcm.attendance_logged`, dan
  `hcm.leave_approved` untuk menggerakkan onboarding karyawan baru ke payroll group masing-
  masing pemicu Final Payroll, kalkulasi overtime, dan deduction cuti-tidak-berbayar. Berbeda
  dari setiap hubungan lintas-modul lain di platform ini, Payroll tidak diharapkan menurun
  dengan anggun jika HCM tidak ada — HCM adalah prasyarat instalasi, bukan peningkatan
  opsional.
- **WNE**: `WorkflowRequested` untuk setiap approval run dan aktivasi Regulatory Rule mana pun
  yang ingin digerbang tenant; `NotificationRequested` untuk notice payslip-siap, pengingat
  tanggal-jatuh-tempo THR, dan alert cicilan-pinjaman-gagal. Payroll mengimplementasikan nol
  logika approval atau notifikasi sendiri.
- **DMS**: `DocumentService::attach()` untuk payslip, kwitansi reimbursement, dan perjanjian
  pinjaman. Payroll mengimplementasikan nol logika penyimpanan/versioning file sendiri.
- **Schedule** (opsional, jika terpasang): Payroll Calendars bisa membaca data hari-libur-
  nasional Schedule untuk menggeser tanggal bayar lebih awal ketika jatuh pada hari libur;
  Payroll tidak boleh throw jika Schedule tidak ada untuk sebuah tenant — postur aman-terhadap-
  feature-flag yang sama yang dipakai Schedule sendiri terhadap WNE.
- **Accounting** (opsional, jika terpasang): Payroll mempublikasikan `payroll.run_paid` ketika
  run terkunci (§3-Admin); Accounting, jika terpasang, mengonsumsinya untuk mem-posting jurnal
  GL run tersebut (`ACCOUNTING_SPECS.md` §3S) — Payroll memegang nol logika posting-GL sendiri,
  peran thin-publisher yang sama yang sudah dimiliki Inventory terhadap Accounting
  (`INVENTORY_SPECS.md` §5). Jika Accounting tidak terpasang, event ini tidak punya listener
  dan Payroll berfungsi identik sebaliknya — postur consumer-hilir-opsional yang sama dengan
  setiap dependensi lunak lain di platform ini.
- **CRM**: tidak terintegrasi — employee bukan Partner; jaga konsep-konsep ini tetap terpisah
  alih-alih memaksakannya ke satu tabel, sesuai panduan CRM sendiri tentang kapan *tidak*
  menggabungkan dua entitas berbeda.

**Queues:** Kalkulasi run payroll untuk Payroll Group besar bisa di-dispatch ke job berantrean
(queue `payroll`) untuk menghindari request sinkron yang panjang, tetapi kalkulasi itu sendiri
deterministik/idempoten (aman dijalankan ulang terhadap input yang sama) — ini penting karena
perubahan aturan-regulatori di tengah kalkulasi tidak boleh pernah berlaku parsial pada run
yang sudah berjalan (resolusi versi aturan sekali, di awal run, dan pertahankan untuk seluruh
run).

**IDs:** `BIGSERIAL` untuk semua PK/FK internal sesuai `CLAUDE.md` §7; tambahkan UUID pada
`employees` dan `payroll_run_lines` untuk penggunaan menghadap-eksternal masa depan (portal
self-service karyawan, sesuai §2 Future Version), mencerminkan rasional `uuid` milik CRM.

**Custom fields:** `payroll_components` dan `payroll_runs` mendaftar terhadap schema
`CUSTOMFIELDS` yang sudah ada (sesuai `CLAUDE.md` §7A) — field spesifik-tenant (misalnya "Cost
Center") tidak pernah membutuhkan migration Payroll. Custom field level-employee didaftarkan
oleh **HCM** terhadap `HCM.employees` (`HCM_SPECS.md` §4, `entity_type = 'hcm_employee'`),
bukan di sini — Payroll membaca `HCM.employees` via FK lintas-schema (§5 di atas) dan tidak
memiliki tabel itu, jadi ia tidak mendaftarkan custom field-nya di sana.

**Batas cakupan MVP (bersikap eksplisit tentang apa yang ditunda, sesuai §2):**
- Posting GL tidak lagi ditunda — Accounting kini ada dan mengonsumsi `payroll.run_paid` via
  engine Payroll GL Posting-nya sendiri (`ACCOUNTING_SPECS.md` §3S); ringkasan biaya CSV flat
  (§3-Reports) tetap laporan pendamping yang bisa dibaca manusia, bukan sumber kebenaran GL.
- Tanpa API pencairan bank langsung — hanya ekspor file.
- Tanpa UI self-service karyawan — hanya layar yang dioperasikan admin/HR; model data sudah
  mendukung self-service sebagai lapisan UI Future Version tanpa perubahan skema.

**Urutan pembangunan yang disarankan untuk Claude Code:** konfirmasi HCM sudah dibangun dan
event `hcm.employee_hired` / `hcm.attendance_logged` / `hcm.leave_approved` sudah hidup lebih
dulu (dependensi keras) → 3B (Setup: Groups, Calendars, Components, Salary Structures,
`employee_payroll_profiles`) → tabel Tax/BPJS Rule ter-versi + seed data tahun-berjalan →
engine 3-PPh21 + 3-BPJS secara terisolasi dengan unit test terhadap contoh kalkulasi yang
diketahui → 3C/3D (Periods + engine run Regular Payroll, 3J) → 3-Payment (batch + ekspor file)
→ 3-Reports (Payslips via DMS) → 3-Admin (Approval via WNE, Lock, Audit Trail) → 3F/3G/3H
(THR, Bonus, Final — gunakan ulang 3J) → 3I (Adjustment) → 3K input sisanya (Loans, Advances,
Reimbursement via DMS) — kirim di titik ini — lalu tinjau ulang item Future Version (ekspor GL,
API bank, self-service) begitu ada penggunaan tenant nyata yang menjustifikasinya.
