# Modul Legal
## Manajemen Praktik Notaris & PPAT — Modul Vertikal (dapat berdiri sendiri/standalone)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Ini adalah **modul vertikal pertama** (`CLAUDE.md` §5) — produk berbayar dan disewakan pertama,
dibangun di atas empat modul Core yang sudah dispesifikasikan (WNE, DMS, CRM, Schedule). Di
Indonesia, sebagian besar Notaris juga memegang rangkap jabatan sebagai **PPAT** (Pejabat
Pembuat Akta Tanah), sehingga satu praktisi menjalankan dua praktik yang saling tumpang tindih
namun secara hukum berbeda secara bersamaan: akta notariil umum (diatur oleh UU No. 30/2004 jo.
UU No. 2/2014 tentang Jabatan Notaris) dan akta peralihan tanah (diatur oleh PP No. 24/1997 dan
peraturan PPAT di bawah Kementerian ATR/BPN). Jika dibiarkan pada alat manajemen praktik atau
dokumen generik, sifat ganda inilah yang persis akan hilang:

- Alat manajemen kasus generik tidak punya konsep **Protokol Notaris** — record arsip-negara
  yang diwajibkan hukum, append-only (minuta akta, repertorium, buku daftar legalisasi, buku
  daftar waarmerking, buku daftar wasiat, buku daftar protes) yang secara pribadi menjadi
  tanggung jawab Notaris dan harus diserahterimakan utuh kepada penerus atau Majelis Pengawas
  Daerah (MPD) saat pensiun. Salah dalam hal ini adalah risiko etik-profesi, bukan sekadar
  ketidaknyamanan UX.
- Transaksi tanah PPAT memiliki **urutan regulasi yang keras** — Due Diligence Tanah (cek
  sertifikat di Kantor Pertanahan) → Pajak (PPh Final 2,5% penjual + BPHTB 5% pembeli, keduanya
  harus clear di DJP/Bapenda **sebelum** akta dapat ditandatangani) → Penandatanganan Akta →
  Pendaftaran BPN (balik nama) — dan sejak 2016 validasi pajak DJP↔BPN adalah **pengecekan
  elektronik host-to-host**: seorang PPAT bahkan tidak dapat menyelesaikan akta jika sistem
  menandai pajak belum dibayar. Alat generik tidak punya notion tentang gate ini, sehingga
  sebuah firma baik membangun checklist manual (rawan kesalahan) atau tidak melakukan apa pun.
- **Wasiat** membawa kewajiban statutori terpisah: setiap wasiat harus dilaporkan ke **Daftar
  Pusat Wasiat (DPW)** via sistem AHU Kemenkumham — langkah yang mudah terlupakan dan tidak
  punya tempat alami di alat dokumen generik.
- **Legalisasi** dan **Waarmerking** secara hukum adalah layanan yang berbeda (yang pertama:
  notaris menyaksikan penandatanganan dan mengonfirmasi identitas/isi; yang kedua: notaris
  hanya mendaftarkan dokumen yang sudah ditandatangani) tapi keduanya membutuhkan buku ledger
  bernomor urut sendiri — alat e-signature atau dokumen generik mencampuradukkan keduanya atau
  mengabaikan sepenuhnya persyaratan ledger.
- Pekerjaan lapangan tidak terelakkan dalam praktik ini — seorang PPAT (atau, lebih sering,
  petugas lapangan/*asisten lapangan*) harus secara fisik mengunjungi Kantor Pertanahan untuk
  mengecek sertifikat, mengambil hasil pendaftaran yang selesai, atau memverifikasi lokasi
  sebelum sign-off due-diligence — dan pekerjaan itu saat ini hidup di kertas atau catatan
  pribadi seseorang, terputus dari file matter.
- Data klien/pihak (penghadap, para pihak dalam sebuah akta) saat ini dimasukkan ulang per akta
  tanpa link ke registry kontak firma-wide, tanpa dedupe, dan tanpa histori "akta apa saja yang
  pernah melibatkan orang ini."

**Kebutuhan klien:**
- Harus dapat berjalan sebagai item lini tersendiri yang dijual — praktik Notaris atau PPAT
  yang standalone dapat mengadopsi Legal tanpa membeli apa pun yang lain — tapi terintegrasi
  secara bersih dengan CRM (registry klien/pihak), DMS (setiap akta dan dokumen pendukung),
  Schedule (janji temu penandatanganan, kunjungan lapangan, tenggat pajak/pendaftaran), dan WNE
  (pengingat tenggat, tinjauan/persetujuan internal sebelum penandatanganan) ketika modul-modul
  itu hadir, persis seperti yang sudah dilakukan Schedule dan DMS untuk WNE.
- Harus mencerminkan urutan regulasi nyata untuk akta PPAT (due diligence → tax clearance →
  penandatanganan → pendaftaran BPN), bukan sekadar repositori dokumen datar.
- Harus mendukung pencatatan statutori Protokol Notaris (sekuensial, append-only, dapat
  diserahterimakan) sebagai konsep kelas satu, bukan renungan belakangan.
- Harus mendukung mekanika pajak Indonesia (PPh Final Pasal 4(2), BPHTB net dari NPOPTKP,
  pelacakan kode billing Coretax/SSPD) secara akurat cukup untuk menggerakkan checklist dan
  memblokir penandatanganan atas pajak yang belum dibayar — **tanpa** berusaha menjadi sistem
  pelaporan pajak (portal DJP/Bapenda tetap menjadi system of record; Legal melacak status dan
  bukti, tidak melapor atas nama praktisi).
- Harus mendukung operator lapangan di mobile — benar-benar berguna jauh dari meja (kunjungan
  lokasi, perjalanan kantor BPN), termasuk penangkapan foto dan log kunjungan ber-tag GPS.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first, sesuai panduan "paling dekat dengan revenue, utamakan
> correctness dan kehalusan UX" di `CLAUDE.md` §5.

**MVP (dikirim sebagai vertikal berbayar pertama)**
- **Model Deed terpadu** yang mencakup kedua praktik (`LEGAL.deeds`), dengan lookup
  `deed_type` yang membedakan akta Notaris vs. PPAT dan menggerakkan engine hilir mana yang
  berlaku (pajak + pendaftaran BPN hanya untuk akta tanah PPAT; penomoran ledger protokol
  untuk semua).
- **Wrapper Matter/engagement** (`LEGAL.matters`) yang mengelompokkan akta-akta terkait untuk
  satu transaksi klien (misalnya, pembelian properti = due diligence + AJB + pajak +
  pendaftaran BPN dalam satu matter) — ini adalah unit yang benar-benar dipikirkan klien, dan
  unit tempat kunjungan lapangan dan tenggat melekat.
- **Akta Notariil** (akta umum — Akta Perjanjian, Akta Kuasa, Akta Pendirian Badan Usaha, dll.)
  sebagai keluarga tipe-deed di bawah model terpadu, dengan field spesifik-tipe via
  `CUSTOMFIELDS` (sesuai pola yang sama yang sudah digunakan DMS/CRM) alih-alih satu tabel per
  tipe akta.
- **Wasiat (Wills)** — keluarga tipe-deed ditambah record pendaftaran DPW khusus dan status
  (dibuat → didaftarkan ke DPW → aktif → dibuka/dilaksanakan → dicabut).
- **Legalisasi & Waarmerking** — lebih ringan dibanding akta penuh: melampirkan dokumen privat
  (via DMS), mencatat akta, dan menetapkan nomor ledger sekuensial yang benar dalam buku
  protokol terkait — buku yang secara hukum berbeda per tipe akta.
- **Protokol Notaris** — ledger-of-ledgers: `protocol_books` (repertorium, legalisasi,
  waarmerking, protes, daftar wasiat, lain-lain) dengan `protocol_entries` append-only,
  pelacakan volume tahunan, dan workflow serah-terima (ke notaris penerus atau MPD) saat
  pensiun/penutupan.
- **AJB, Hibah, Akta PPAT Lainnya** — dipersatukan di bawah model Deed yang sama dengan
  `category = ppat` dan `deed_type` yang mencakup delapan tipe akta PPAT statutori (Jual Beli,
  Hibah, Tukar Menukar, Pemasukan ke Perusahaan/Inbreng, Pembagian Hak Bersama, Pemberian Hak
  Tanggungan/APHT, Pemberian HGB/Hak Pakai atas Tanah Hak Milik, Pelepasan Hak).
- **Registry Land Object** (`LEGAL.land_objects`) — dapat digunakan ulang lintas due diligence,
  akta, dan matter masa depan pada parcel yang sama, sehingga sebuah firma membangun histori
  aset per sertifikat seiring waktu alih-alih memasukkan ulang per transaksi.
- **Land Due Diligence** — checklist terstruktur per parcel: validitas sertifikat (cek SKPT),
  status pembayaran PBB, cek blokir/sengketa (encumbrance/dispute), referensi Zona Nilai Tanah —
  setiap item dicatat dengan bukti (lampiran DMS) dan hasil.
- **Engine pelacakan pajak** — PPh Final (penjual, 2,5% dari nilai transfer bruto atau NJOP,
  mana yang lebih tinggi) dan BPHTB (pembeli, 5% net dari NPOPTKP, yang dapat dikonfigurasi
  tenant karena ditetapkan per pemerintah daerah) sebagai kewajiban yang dapat dilacak per akta:
  jumlah dasar, jumlah terhitung, kode billing (Kode Billing/Coretax), bukti NTPN, status.
  **Penandatanganan diblokir di aplikasi** sampai keduanya ditandai `paid_and_validated` untuk
  akta tanah PPAT yang membutuhkannya — mencerminkan gate host-to-host DJP↔BPN yang
  sesungguhnya, sebagai pengaman workflow, bukan pelaporan pajak.
- **Pelacakan Pendaftaran BPN** — log pengajuan pasca-penandatanganan (balik nama, pendaftaran
  APHT/HT-el, split/merge, dll.) dengan nomor tracking, biaya PNBP, status, dan dokumen hasil
  (sertifikat baru) yang dilampirkan via DMS. Karena BPN tidak punya API integrasi publik pada
  skala firma solo-dev, ini adalah **checklist/log status yang dilacak**, bukan integrasi sistem
  langsung — batas cakupan MVP eksplisit (lihat §5).
- **Field Operations (mobile)** — penjadwalan kunjungan (via Schedule), alur mobile-first yang
  ringan untuk operator lapangan: check-in dengan lokasi ber-tag GPS, menangkap foto/scan
  langsung ke DMS, memperbarui status due-diligence atau pengajuan-BPN dari lapangan, semuanya
  pada UI checklist sederhana. Ini adalah satu-satunya tempat di Legal yang benar-benar
  membutuhkan klien mobile berbasis API alih-alih halaman web responsif — lihat §5 untuk alasan
  mengapa ini diperlakukan sebagai pengecualian yang terjustifikasi.
- **Manajemen pihak/penghadap** — setiap pihak dalam akta (`penghadap`, `pihak`, `saksi`,
  `kuasa`, `ahli_waris`) terhubung ke `CRM.partners` (cross-schema FK, Vertical→Core, arah
  aturan yang sama seperti setiap modul lain), dengan **snapshot identitas** pada waktu
  penandatanganan (lihat §5 — isi akta yang ditandatangani, termasuk detail identitas pihak,
  tidak boleh pernah berubah secara diam-diam bahkan jika record CRM yang mendasarinya kemudian
  diedit).

**Future Version (pasca-peluncuran)**
- Integrasi e-meterai (materai elektronik) saat penandatanganan.
- Integrasi API langsung dengan Kantor Pertanahan / Sistem host-to-host DJP-BPN, jika/ketika
  BPN mengekspos sesuatu di luar akses portal pada skala yang layak dibangun.
- Integrasi API AHU Kemenkumham untuk pendaftaran wasiat DPW dan pengajuan akta korporat (saat
  ini entri portal manual, dilacak hanya sebagai item checklist).
- Portal self-service klien (status matter mereka, permintaan dokumen) — menggunakan ulang
  DMS/CRM, bukan stack baru.
- Billing/invoicing terkait matter dan tipe akta — saat sebuah matter/case siap ditagih, Legal
  memanggil entry point permintaan-billable generik milik **Sales**,
  `SalesOrderService::createFromExternalRequest(...)` (lebih disukai) atau event
  `SalesOrderRequested` (`SALES_SPECS.md` §3I/§5), dengan referensi matter/deed, item baris
  waktu-billable/disbursement, dan jumlah yang sudah dihitung Legal. Sales membuat Sales Order
  (`subject_type = 'legal.matters'` atau `'legal.deeds'`, menggunakan ulang seam polimorfik yang
  sama yang sudah didefinisikan `SALES_SPECS.md` §3F) dan, begitu dikonfirmasi, memicu
  `InvoiceRequested` biasa ke **Accounting** (`ACCOUNTING_SPECS.md` §3D/§3R) melalui jalur
  Billing Engine normalnya. Legal tidak pernah memanggil Accounting secara langsung dan tidak
  pernah memiliki logika billing sendiri — Sales adalah satu-satunya modul pengorkestrasi-AR
  milik platform (sesuai `ACCOUNTING_SPECS.md` §3R), dan ini adalah jalur konkret yang akan
  digunakan Legal begitu dibangun. (Menggantikan catatan sebelumnya yang mengarah ke item open
  `CLAUDE.md` §11 — item itu sudah diselesaikan.)
- Penangkapan tanda tangan digital/e-signature untuk workflow legalisasi di mana regulasi
  mengizinkan.
- OCR sertifikat dan KTP yang discan untuk pre-fill data land object / pihak (bergantung pada
  Future Version OCR milik DMS sendiri, §3G dari `DMS_SPECS.md`).

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Function / features**
- Ringkasan kesehatan praktik: matter terbuka berdasarkan tipe (Notary/PPAT), akta menunggu
  penandatanganan, kewajiban pajak menunggu clearance, pengajuan BPN dalam proses, penutupan
  buku protokol yang akan datang.
- Antrean "my work": matter/akta yang ditugaskan kepada saya, kunjungan lapangan yang
  ditugaskan kepada saya — dipersatukan dengan pola "My work" milik CRM (3A dari
  `CRM_SPECS.md`).
- Permukaan risiko-tenggat: tanggal jatuh tempo pembayaran pajak, lag pendaftaran DPW, usia
  pengajuan BPN — masing-masing menggunakan **Status Rail** bersama (`DESIGN.md`) yang diwarnai
  berdasarkan urgensi, bahasa visual yang sama seperti Scheduler/Workflows/CRM.

**Layout**
- Atas: kartu ringkasan — Matter Terbuka, Akta Menunggu Penandatanganan, Pajak Menunggu
  Clearance, BPN Dalam Proses.
- Utama: tabel bertab — "Matter Saya" | "Akta Saya" | "Kunjungan Lapangan" | "Buku Protokol".
- Klik baris membuka drawer dengan detail matter/deed, timeline, dan dokumen ter-link (DMS).

**Rules / logic**
- Di-scope tenant melalui isolasi database (tanpa kolom `tenant_id`, sesuai `CLAUDE.md` §4/§7).
- Akta PPAT dengan pajak belum dibayar/belum divalidasi muncul dengan rail `danger` terlepas
  dari urutan sort — pola "pelanggaran muncul lebih dulu" yang sama seperti pelanggaran SLA CRM.

## 3B. Matters (Engagements)

**Tujuan:** unit pekerjaan yang menghadap klien; mengelompokkan satu atau lebih akta di bawah
satu transaksi.

- Field: judul, tipe matter (lookup bebas — "Pembelian Properti," "Pendirian Perusahaan,"
  "Estate Planning," ...), klien utama (`partner_id`, FK `CRM.partners`), pihak terkait
  tambahan, notaris/PPAT yang ditugaskan (user internal), status
  (open/in_progress/on_hold/closed), tanggal dibuka, target tanggal selesai, catatan.
- Link asal opsional kembali ke CRM Lead (`converted_from_lead_id`) — konsultasi yang menjadi
  engagement, menggunakan ulang alur konversi Lead milik CRM (3D dari `CRM_SPECS.md`) alih-alih
  membangun pipeline intake kedua.
- Detail view: tab — Overview, Deeds (semua akta di bawah matter ini), Documents (DMS, di-scope
  ke matter ini via `subject_type`/`subject_id`), Field Visits, Activity Timeline.

**Rules / logic**
- Menutup matter tidak mewajibkan setiap akta berada dalam state terminal (sebuah matter bisa
  ditutup dengan task tindak lanjut yang dilacak di Schedule sebagai gantinya) — menghindari
  memaksakan kelengkapan artifisial.

## 3C. Akta Notariil (Entry)

**Tujuan:** akta notariil umum — keluarga "Akta Umum" yang luas (perjanjian, surat kuasa, akta
korporat, pengakuan hutang, dll.).

- Field: matter (opsional — beberapa akta berdiri sendiri, bukan bagian dari matter yang lebih
  besar), `deed_type_id` (lookup, dapat diperluas — lihat §4), nomor akta (ditetapkan saat
  penandatanganan, sesuai volume `protocol_books` aktif untuk tahun tersebut), tanggal
  penandatanganan, para pihak (lihat 3J), subjek/ringkasan, referensi minuta, custom field per
  `deed_type` via `CUSTOMFIELDS`.
- Lifecycle: `draft → ready_for_signing → signed → archived`. Penandatanganan adalah titik
  akta menjadi immutable (isi dan snapshot identitas pihak terkunci; perubahan lebih lanjut
  membutuhkan akta amandemen baru, tidak pernah edit pada yang asli — sesuai praktik notariil
  nyata).
- Saat `signed`: menetapkan nomor urut berikutnya dalam buku protokol `repertorium` yang aktif,
  memicu event `LegalDeedSigned` (WNE dapat merutekan ini ke "notify client," "notify field ops
  for next step," dll., pola decoupled yang sama seperti integrasi setiap modul lain → WNE).

## 3D. Wasiat (Wills)

**Tujuan:** wasiat, dengan kewajiban statutori Daftar Pusat Wasiat di garis depan.

- Memperluas model Deed (`category = notary`, `deed_type = wasiat`) dengan record
  `LEGAL.wills` khusus: pewaris (`partner_id`), nomor pendaftaran DPW, tanggal terdaftar DPW,
  status (`drafted → dpw_registered → active → opened / revoked`).
- Flag dashboard: wasiat mana pun yang sudah ditandatangani tapi belum ditandai
  `dpw_registered` melewati periode tenggang yang dapat dikonfigurasi muncul dengan rail
  `warning`/`danger` — ini adalah celah liabilitas tertinggi tunggal dalam praktik wasiat
  (wasiat yang belum terdaftar secara efektif tidak terlihat oleh sistem negara), sehingga
  diberi visibilitas eksplisit dan persisten alih-alih terkubur dalam daftar tugas generik.
- Mencabut atau "membuka" (melaksanakan) wasiat dicatat, tidak pernah pembalikan status yang
  diam-diam — memasok jejak audit immutable akta.

## 3E. Legalisasi & Waarmerking (Entry)

**Tujuan:** dua layanan notariil yang lebih ringan dan volume-tinggi — secara hukum berbeda,
keduanya membutuhkan ledger sekuensialnya sendiri.

- Field: tipe akta (`legalisasi` / `waarmerking`), dokumen privat yang mendasarinya (dilampirkan
  via DMS, tidak ditulis ulang — notaris mensertifikasi/mendaftarkan dokumen orang lain, bukan
  menyusun satu), pihak/para pihak, tanggal, catatan.
- **Legalisasi**: notaris mengonfirmasi identitas penandatangan dan menyaksikan penandatanganan
  (atau mengakui tanda tangan yang sudah dibuat di hadapan notaris) — membutuhkan penangkapan
  identitas pihak, aturan snapshot yang sama seperti akta penuh.
- **Waarmerking**: notaris mendaftarkan dokumen yang sudah ditandatangani di tempat lain, hanya
  mencatat bahwa itu sudah ada pada tanggal tertentu — persyaratan pihak lebih ringan (hanya
  pendaftar, bukan verifikasi identitas penuh setiap penandatangan).
- Saat selesai, menetapkan nomor urut berikutnya dalam buku protokol terkait
  (`buku_daftar_legalisasi` atau `buku_daftar_waarmerking`) — mekanisme penomoran yang sama
  seperti 3C/3F, hanya buku yang berbeda.

## 3F. Protokol Notaris (Engine)

**Tujuan:** record-of-records statutori yang secara pribadi menjadi tanggung jawab Notaris.

- `protocol_books`: satu baris per tipe buku × tahun × volume (`repertorium`, `legalisasi`,
  `waarmerking`, `protes`, `daftar_wasiat`, `lain_lain`), status (`active`/`closed`/
  `handed_over`), notaris (user internal), tanggal dibuka, tanggal ditutup.
- `protocol_entries`: baris ledger append-only — `book_id`, `deed_id` (atau referensi
  will/legalization/waarmerking), nomor urut (ditetapkan secara atomik, tanpa celah dalam
  book+year), tanggal entri. **Tidak ada update atau delete yang diizinkan di lapisan
  aplikasi** — aturan integritas-audit yang sama seperti `access_logs` milik DMS (3I dari
  `DMS_SPECS.md`).
- **Workflow serah-terima**: pada penutupan tahun, pensiun, atau transfer, sebuah buku pindah
  ke `handed_over` dengan penerima (notaris penerus atau MPD), tanggal, dan manifest
  serah-terima yang dihasilkan (PDF, via pendekatan pembuatan-dokumen yang sama yang digunakan
  untuk ekspor akta) — ini adalah persyaratan etik-profesi nyata (UU 2/2014), bukan
  nice-to-have, sehingga dimodelkan secara eksplisit alih-alih dibiarkan sebagai "cukup ekspor
  laporan."

## 3G. AJB, Hibah & Akta PPAT Lainnya (Entry)

**Tujuan:** delapan tipe akta PPAT statutori, dipersatukan di bawah model Deed dengan
`category = ppat`.

- Field: matter, `deed_type_id` (AJB / Hibah / Tukar Menukar / Pemasukan ke Perusahaan /
  Pembagian Hak Bersama / APHT / Pemberian HGB-Hak Pakai / Pelepasan Hak), land object
  (`land_object_id`, lihat 3H), para pihak (transferor/transferee atau setara per tipe, lihat
  3J), nilai transaksi, tanggal penandatanganan.
- **Hard gate**: sebuah akta PPAT tidak dapat pindah ke `signed` sampai (a) Land Due Diligence
  (3I) untuk land object ter-link menunjukkan tidak ada isu blocking yang belum terselesaikan,
  dan (b) kedua kewajiban pajak yang diperlukan (3K) berstatus `paid_and_validated` —
  mencerminkan cek host-to-host DJP↔BPN yang sesungguhnya, ditegakkan di sini sebagai gate
  workflow tingkat-aplikasi alih-alih integrasi sesungguhnya (lihat catatan cakupan §5).
- Saat `signed`: penomoran protokol + event `LegalDeedSigned` yang sama seperti 3C, ditambah
  otomatis membuat baris `bpn_submissions` yang pending (3L) karena setiap tipe akta ini
  membutuhkan aksi tindak lanjut BPN.

## 3H. Registry Land Object

**Tujuan:** record yang dapat digunakan ulang per parcel/sertifikat, sehingga due diligence,
akta, dan transaksi masa depan pada tanah yang sama membangun histori alih-alih memasukkan
ulang data.

- Field: tipe sertifikat (`SHM`/`HGB`/`HGU`/`Hak Pakai`/lainnya), nomor sertifikat, NIB (Nomor
  Identifikasi Bidang), alamat/lokasi, luas (m²), referensi NJOP, pemilik terdaftar saat ini
  (`partner_id`, informasional — sertifikat adalah source of truth, bukan field ini), status
  (`active`/`in_transaction`/`transferred`/`disputed`).
- Detail view: akta ter-link (akta mana pun yang mereferensikan object ini), histori due
  diligence, status saat ini.

## 3I. Land Due Diligence (Engine)

**Tujuan:** checklist pra-transaksi terstruktur yang menjadi dasar setiap akta PPAT.

- `due_diligence_checks`: `land_object_id`, tipe check (`sertifikat_validity` /
  `pbb_payment_status` / `blokir_sengketa` / `zona_nilai_tanah`), status
  (`pending`/`clear`/`flagged`), checked_by, checked_at, catatan hasil, bukti (lampiran DMS —
  misalnya scan SKPT).
- Hasil `flagged` pada check mana pun memblokir akta terkait dari penandatanganan sampai
  terselesaikan atau di-override secara eksplisit oleh notaris/PPAT yang bertanggung jawab
  dengan justifikasi yang tercatat — jalur override ini ada karena praktik nyata terkadang
  melanjutkan dengan penerimaan risiko yang terdokumentasi, tapi tidak boleh pernah diam-diam.
- Check lapangan (misalnya kunjungan lokasi fisik) adalah pemicu alami untuk Field Visit (3M).

## 3J. Manajemen Pihak / Penghadap

**Tujuan:** setiap pihak dalam akta, terhubung ke registry kontak firma-wide tanpa kehilangan
akurasi point-in-time akta.

- `deed_parties`: `deed_id`, `partner_id` (FK `CRM.partners`), role (`penghadap`/
  `pihak_pertama`/`pihak_kedua`/`saksi`/`kuasa`/`ahli_waris`/lainnya, lookup yang dapat diedit
  tenant — pola yang sama seperti tipe role CRM), dan **snapshot identitas** (nama, nomor
  ID/NIK, alamat, dan field identitas lain apa pun sebagaimana ada pada waktu penandatanganan).
- **Kenapa snapshot, bukan referensi live**: akta yang ditandatangani adalah record hukum
  siapa yang hadir dan apa identitas yang mereka nyatakan *pada momen itu*. Jika record
  `CRM.partners` yang mendasarinya kemudian dikoreksi (misalnya pembaruan alamat), akta tidak
  boleh secara diam-diam mencerminkan data baru — snapshot adalah yang otoritatif untuk
  instrumen itu, selamanya. Ini adalah disiplin yang sama yang diterapkan DMS pada versioning
  (3C dari `DMS_SPECS.md`: tidak pernah menimpa, selalu versi baru) diterapkan pada identitas
  alih-alih file.
- Sebuah pihak tanpa match `CRM.partners` yang sudah ada dapat ditambahkan cepat inline (membuat
  Contact minimal) — mencerminkan filosofi "convert without re-entering data" milik CRM sendiri
  (3D dari `CRM_SPECS.md`).

## 3K. Engine Pelacakan Pajak

**Tujuan:** melacak dua pajak wajib pada peralihan tanah PPAT secara cukup akurat untuk
menggerbang penandatanganan, tanpa menjadi sistem pelaporan pajak.

- `deed_taxes`: `deed_id`, tipe pajak (`pph_final` / `bphtb`), role wajib pajak (penjual untuk
  PPh, pembeli untuk BPHTB — otomatis default dari role pihak akta, sesuai PP No. 34/2016 dan
  UU No. 28/2009), jumlah dasar (nilai transaksi atau NJOP, mana yang lebih tinggi — kedua
  field ditangkap sehingga yang lebih tinggi transparan, bukan sekadar diasumsikan), rate
  (2,5% untuk PPh Final; 5% untuk BPHTB, masing-masing dapat dikonfigurasi tenant seandainya
  regulasi masa depan mengubah rate), NPOPTKP yang diterapkan (dapat dikonfigurasi tenant —
  ini adalah angka pemerintah daerah, bervariasi per Kabupaten/Kota Bapenda), jumlah terhitung,
  kode billing (referensi Kode Billing / Coretax untuk PPh, referensi SSPD untuk BPHTB), NTPN
  (Nomor Transaksi Penerimaan Negara — bukti pembayaran), bukti pembayaran (lampiran DMS),
  status (`pending`/`billing_code_issued`/`paid`/`validated`).
- `validated` adalah status yang sengaja berbeda dari `paid` — praktik nyata mengharuskan PPAT
  (atau cek host-to-host DJP/Bapenda) mengonfirmasi pembayaran *diakui*, bukan sekadar bahwa
  transfer sudah dilakukan; hanya `validated` yang memenuhi gate penandatanganan di 3G.
- Engine ini **menghitung dan melacak**, tidak menyetorkan pembayaran atau melaporkan SPT —
  Coretax (DJP) dan sistem SSPD masing-masing Bapenda lokal tetap menjadi system of record,
  konsisten dengan batas modul yang sudah digambar untuk DMS (search tidak menggantikan dokumen
  sumber) dan WNE (notifikasi tidak menggantikan transaksi bisnis).

**Rules / logic**
- `LEGAL.deed_taxes` melacak **kewajiban pajak klien sendiri atas transaksi tanah mereka** —
  PPh Final penjual dan BPHTB pembeli — bukan pembukuan firma sendiri. Ini secara sengaja
  terpisah dari, dan tidak memiliki hubungan dengan, Engine Pajak Indonesia milik
  **Accounting** (`ACCOUNTING_SPECS.md` §3M), yang melacak withholding PPN/PPh *firma sendiri*
  sebagai wajib pajak/agen withholding atas transaksi AR/AP-nya sendiri (misalnya PPh 4(2) pada
  tagihan sewa vendor yang dibayar firma sendiri). Tumpang tindih nama antara "PPh Final atas
  peralihan tanah" (di sini) dan "PPh 4(2)" sebagai tipe withholding umum pada tagihan firma
  sendiri (`ACCOUNTING_SPECS.md` §3M) adalah kebetulan, bukan konsep bersama —
  `LEGAL.deed_taxes` tidak pernah menghasilkan entri journal Accounting atau Bukti Potong.
  Pembayaran BPHTB/PPh Final klien adalah uang yang berpindah antara klien dan pemerintah
  (dibuktikan kepada notaris), bukan transaksi pada general ledger firma sendiri.

## 3L. Pelacakan Pendaftaran BPN

**Tujuan:** langkah registry tanah pasca-penandatanganan (balik nama, pendaftaran APHT/HT-el,
split/merge, dll.), dilacak sebagai checklist/log status.

- `bpn_submissions`: `deed_id`, tipe pengajuan, tanggal diajukan, nomor tracking/tanda terima,
  jumlah biaya PNBP (dibantu formula: `(nilai_tanah / 1000) + Rp 50.000` sesuai konvensi PNBP
  BPN saat ini, dapat diedit karena ada variasi lokal), status
  (`prepared`/`submitted`/`in_process`/`completed`/`rejected`), tanggal selesai, dokumen hasil
  (sertifikat baru/diperbarui, dilampirkan via DMS).
- Penolakan membutuhkan alasan dan pengajuan ulang adalah baris baru yang mereferensikan yang
  sebelumnya (`resubmission_of_id`) — tidak pernah edit-in-place, filosofi non-destruktif yang
  sama seperti versioning DMS dan aturan immutability akta di 3C.
- Catatan cakupan MVP eksplisit (§5): tidak ada API BPN live pada skala firma solo-dev,
  sehingga ini adalah tracker yang diperbarui manual, bukan integrasi sistem — nilainya adalah
  memusatkan visibilitas status dan tenggat, bukan mengotomasi sisi pemerintah.

## 3M. Field Operations (Mobile)

**Tujuan:** satu-satunya workflow yang benar-benar hidup jauh dari meja.

- **Penjadwalan kunjungan**: kunjungan lapangan (`field_visits`) dibuat terhadap matter/land
  object/deed, dengan tipe (`site_survey`/`bpn_office_visit`/`document_pickup`/
  `signing_witness`/lainnya), operator lapangan yang ditugaskan, dan item kalender `Schedule`
  ter-link (menggunakan ulang model Task/Event milik Schedule — Legal tidak membangun
  kalendarisasi sendiri, sesuai batas Core/Vertical).
- **Alur check-in mobile**: operator membuka kunjungan di ponsel mereka, lokasi GPS ditangkap
  saat check-in, foto/scan ditangkap langsung ke DMS (di-tag ke matter/land object/deed),
  checklist singkat (per tipe kunjungan, dapat dikonfigurasi tenant) diselesaikan, dan catatan
  penutup ditambahkan. Status mengalir `scheduled → checked_in → completed`.
- **Toleransi offline**: karena kantor BPN dan lokasi tanah pedesaan sering memiliki
  konektivitas buruk, klien mobile mengantrekan data check-in dan upload foto secara lokal dan
  menyinkronkan saat kembali online — ini adalah alasan konkret mengapa workflow ini
  mendapatkan klien mobile sesungguhnya alih-alih halaman web responsif (lihat §5).
- Menyelesaikan sebuah kunjungan dapat langsung memperbarui status `due_diligence_checks` atau
  `bpn_submissions` yang ter-link (misalnya "lokasi dicek, tidak ditemukan sengketa" atau
  "sertifikat diambil") — menutup loop antara pekerjaan lapangan dan record sisi-kantor tanpa
  entri ulang.

# 4. Penyimpanan

**Database (skema `LEGAL`, DB tenant — konsisten dengan `CLAUDE.md` §7A; tanpa kolom
`tenant_id`, isolasi adalah batas database):**

**Tabel master / lookup**
- `LEGAL.deed_types` — kode, nama, kategori (`notary`/`ppat`), requires_tax (bool),
  requires_bpn_registration (bool), tipe buku protokol default.
- `LEGAL.party_role_types` — lookup yang dapat diedit tenant (penghadap, pihak_pertama, saksi,
  kuasa, ahli_waris, ...), mencerminkan pola `CRM.partner_role_types`.
- `LEGAL.field_visit_types` — lookup dengan checklist default yang dapat dikonfigurasi (JSON).

**Tabel transaksi / core**
- `LEGAL.matters` — header: title, matter_type, `partner_id` utama (FK `CRM.partners`),
  assigned_to, status, opened_at, target_close_at, `converted_from_lead_id` (nullable, FK
  `CRM.leads`).
- `LEGAL.deeds` — header: `matter_id` (nullable), `deed_type_id`, category, deed_number
  (ditetapkan saat penandatanganan), status, signing_date, minuta_reference, summary.
- `LEGAL.deed_parties` — `deed_id`, `partner_id` (FK `CRM.partners`), `role_type_id`, snapshot
  identitas (JSON: nama, nomor ID, alamat, dll. sebagaimana ada saat penandatanganan).
- `LEGAL.wills` — `deed_id` (FK, category=notary/wasiat), `partner_id` pewaris, dpw_reg_number,
  dpw_registered_at, status.
- `LEGAL.land_objects` — certificate_type, certificate_number, nib, address, area_m2,
  njop_reference, `partner_id` pemilik saat ini (informasional), status.
- `LEGAL.due_diligence_checks` — `land_object_id`, check_type, status, checked_by, checked_at,
  result_notes.
- `LEGAL.deed_taxes` — `deed_id`, tax_type, `partner_id` wajib pajak, base_amount, njop_amount,
  rate, npoptkp_applied, computed_amount, billing_code, ntpn, status.
- `LEGAL.bpn_submissions` — `deed_id`, submission_type, submitted_at, tracking_number,
  pnbp_amount, status, completed_at, `resubmission_of_id` (nullable, self-referencing).
- `LEGAL.protocol_books` — book_type, year, volume, notaris (user internal), status, opened_at,
  closed_at, handed_over_to, handed_over_at.
- `LEGAL.protocol_entries` — `book_id`, `deed_id` (nullable, referensi semi-polimorfik ke
  deed/will/legalization/waarmerking), sequence_number, entry_date. Append-only.
- `LEGAL.field_visits` — `matter_id`, `land_object_id`/`deed_id` (nullable), `visit_type_id`,
  assigned_to, `schedule_item_id` terjadwal (FK `SCHEDULE.sched_items`), status, checked_in_at,
  gps_lat, gps_lng, checklist_result (JSON), notes.

**Custom fields:** `deeds`, `matters`, `land_objects` semuanya didaftarkan terhadap skema
`CUSTOMFIELDS` yang sudah ada (sesuai `CLAUDE.md` §7A) — field spesifik-tipe-akta (misalnya
modal saham untuk Akta Pendirian PT, syarat tukar untuk Tukar Menukar) adalah custom field yang
dapat dikonfigurasi tenant/tipe, bukan satu migration per tipe akta.

**Penyimpanan file objek** (sesuai `CLAUDE.md` §7B — mencadangkan folder `LEGAL/` per tenant;
file sesungguhnya hidup di struktur penyimpanan DMS, direferensikan oleh
`subject_type = 'legal.deeds'` dll.), mengikuti konvensi path kanonik DMS sendiri persis
(`DMS_SPECS.md` §4: `{owning_module}/{yyyy}/{mm}/{document_uuid}/v{n}.{ext}`), bukan layout
khusus-Legal:
```text
tenant_001/DMS/LEGAL/{yyyy}/{mm}/{document_uuid}/
├── v1.{ext}
└── ...
```
Dokumen sebuah matter, deed, atau field visit ditemukan dengan meng-query DMS untuk
`subject_type = 'legal.matters'` / `'legal.deeds'` / `'legal.field_visits'` dan `subject_id`
yang relevan (sesuai §3B/§3M di atas) — pengelompokan adalah query database terhadap kolom
`subject_type`/`subject_id` milik DMS, bukan konvensi folder-path. Path fisik ada hanya untuk
keperluan penyimpanan/perencanaan-restore (sesuai `CLAUDE.md` §7B), sehingga tetap identik
dengan dokumen ter-rute-DMS setiap modul lainnya alih-alih mengkodekan struktur khusus-Legal
ke dalamnya.
- Legal sendiri tidak menyimpan file secara langsung — setiap dokumen (ekspor akta, scan
  sertifikat, scan KTP, bukti pembayaran pajak, foto lapangan) melalui `DocumentService`
  (facade DMS), sama seperti setiap modul lain. Ini menjaga versioning, jejak audit, dan
  retensi tetap konsisten platform-wide alih-alih Legal menciptakan ulang penanganan file.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Vertical di `app/Modules/Legal/`, bentuk yang sama seperti setiap
modul Core (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`, `Routes/`).
Legal bergantung pada CRM, DMS, dan Schedule (Vertical → Core, satu-satunya arah yang diizinkan
sesuai `CLAUDE.md` §2/§9) dan terintegrasi dengan WNE dengan cara decoupled yang sama seperti
setiap modul lain — Legal tidak pernah mengimplementasikan logika notifikasi/approval sendiri.

**Penempatan kategori (sesuai `CLAUDE.md` §10 — nyatakan kategori sebelum membangun):**
- Tabel `LEGAL.*`, logika deed/matter/protocol/tax/land → **Vertical**. Ini adalah pengetahuan
  domain khusus-Legal (tipe akta, buku protokol, mekanika pajak Indonesia) yang tidak punya
  kasus penggunaan ulang di Property atau vertikal masa depan.
- Linkage pihak, penyimpanan dokumen, penjadwalan, notifikasi → dikonsumsi **dari Core**
  (`CRM.partners`, `DocumentService`, `SCHEDULE.sched_items`, event WNE) via facade/event,
  sesuai pola seam yang sama yang sudah ditetapkan di `DMS_SPECS.md` §5 dan
  `SCHEDULE_SPECS.md` §5.
- **Tidak ada microservice baru** — Legal adalah CRUD ditambah beberapa gate workflow (tax
  clearance, due diligence) dan engine penomoran/ledger, tidak satu pun yang membutuhkan
  runtime berbeda atau scaling independen sesuai kriteria ekstraksi `CLAUDE.md` §2.

**Arah FK cross-schema:** `LEGAL.matters.partner_id`, `LEGAL.deed_parties.partner_id`, dan
`LEGAL.land_objects.current_owner` semuanya FK langsung ke `CRM.partners.id` — aman karena ini
Vertical bergantung pada Core (alasan yang sama yang sudah didokumentasikan `CRM_SPECS.md` §5
untuk FK cross-schema-nya sendiri). CRM tidak, dan tidak akan pernah, punya pengetahuan tentang
`LEGAL.*`.

**Immutability akta:** begitu sebuah akta mencapai `signed`, record akta, snapshot identitas
`deed_parties`-nya, dan nomor urut protokol yang ditetapkan menjadi read-only di lapisan
aplikasi. Koreksi terjadi via akta amandemen baru yang mereferensikan yang asli
(`amends_deed_id`), tidak pernah edit — ini mencerminkan filosofi non-destruktif yang sudah
digunakan untuk versi DMS (§3C, `DMS_SPECS.md`) dan merge CRM (§3G, `CRM_SPECS.md`),
diterapkan pada satu-satunya domain di mana ini adalah persyaratan *hukum*, bukan sekadar
kebersihan-data.

**Gate pajak adalah pengaman workflow, bukan sistem pelaporan — batas cakupan eksplisit:**
`deed_taxes` melacak status sampai `validated`; Legal tidak memanggil Coretax atau sistem
Bapenda mana pun. Ini menjaga modul tetap jujur tentang apa yang dimilikinya: PPAT/notaris
tetap melakukan pelaporan/pembayaran sesungguhnya melalui DJP Coretax dan proses SSPD Bapenda
lokal, dan menandai status di Legal. Jika versi masa depan platform ini menginginkan integrasi
sesungguhnya, `deed_taxes.billing_code`/`ntpn` sudah menjadi join key yang dibutuhkan —
aditif, bukan desain ulang.

**Pendaftaran BPN adalah tracker, bukan integrasi — alasan yang sama.** Tidak ada API publik
pada skala ini; `bpn_submissions` ada untuk memusatkan visibilitas dan tenggat lintas matter
sebuah firma, yang itu sendiri adalah nilai yang dapat dijual (praktisi solo atau firma kecil
saat ini melacak ini di spreadsheet atau ingatan).

**Field Operations Mobile — satu-satunya pengecualian API yang terjustifikasi:** bagian "Web
vs future clients" milik `CLAUDE.md` §2 secara eksplisit mengizinkan API REST bervensi begitu
"klien non-Inertia sudah nyata, bukan spekulatif." Field visit adalah kasus itu: ponsel di
kantor BPN atau lokasi tanah pedesaan, dengan kebutuhan toleransi-offline nyata (check-in GPS,
penangkapan foto yang diantre untuk sync), adalah bentuk klien yang benar-benar berbeda
dibanding halaman Inertia desktop — bukan preferensi gaya untuk feel native. Batasi
pengecualian secara sempit: permukaan `api/v1/legal/field-visits/*` yang tipis dan bervensi
yang memanggil `FieldVisitService` yang sama yang digunakan aplikasi web secara internal,
sehingga ada tepat satu tempat logika bisnis hidup, sesuai aturan "no duplicated domain logic"
`CLAUDE.md` §2. Semua yang lain di Legal (matters, deeds, tax, pelacakan BPN, buku protokol)
tetap desk-bound Inertia — tahan godaan untuk memperluas permukaan mobile lebih jauh dari yang
benar-benar dibutuhkan field visit.

**Integritas ledger protokol:** `protocol_entries.sequence_number` harus tanpa celah dalam
pasangan `(book_id, year)` — ditetapkan di dalam transaksi DB yang sama yang membalik sebuah
akta ke `signed` (atau menyelesaikan legalisasi/waarmerking), menggunakan row lock pada baris
`protocol_books` yang aktif untuk mencegah race condition dari dua akta yang ditandatangani
secara konkuren. Tidak ada update/delete yang diizinkan pada `protocol_entries` di lapisan
aplikasi, aturan integritas-audit yang sama seperti `DMS.access_logs`.

**Basis referensi regulasi (untuk konteks Claude Code, tidak disimpan sebagai data):** UU No.
30/2004 jo. UU No. 2/2014 (Jabatan Notaris — kewajiban protokol, wasiat,
legalisasi/waarmerking); PP No. 24/1997 dan peraturan PPAT di bawah Kementerian ATR/BPN
(delapan tipe akta PPAT statutori); PP No. 34/2016 (PPh Final atas pengalihan hak atas tanah
dan/atau bangunan, 2,5%); UU No. 28/2009 (BPHTB, pajak lokal/daerah, 5% net dari NPOPTKP,
NPOPTKP ditetapkan per Kabupaten/Kota). Rate dan ambang batas disimpan sebagai nilai yang dapat
dikonfigurasi tenant (§3K), tidak di-hardcode, karena angka NPOPTKP lokal bervariasi dan rate
nasional ditetapkan oleh regulasi yang bisa berubah — engine tidak boleh pernah membutuhkan
deploy kode untuk mencerminkan perubahan rate.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B/3C (matters + akta notariil, slice
end-to-end paling sederhana) → 3J (linkage pihak, dibutuhkan oleh segala sesuatu yang lain) →
3F (penomoran protokol, karena bahkan 3C membutuhkannya saat penandatanganan) → 3H/3I (land
object + due diligence) → 3G (akta PPAT, bergantung pada 3H/3I/3K) → 3K (engine pajak) → 3L
(pelacakan BPN) → 3D/3E (wasiat, legalisasi/waarmerking — pola yang sama seperti 3C,
kompleksitas lebih rendah) → 3M (field operations, termasuk permukaan API mobile) — dikirim
pada titik ini — lalu tinjau kembali item Future Version (e-meterai, integrasi AHU/BPN,
billing) begitu ada penggunaan nyata yang menjustifikasi pembangunannya.

**Catatan kelayakan jual (marketability)**
- Fitur protokol/ledger dan tax-gate adalah yang paling sulit dibangun dengan baik dan paling
  mudah dijual — mereka adalah perbedaan antara "folder dokumen dengan langkah ekstra" dan
  "software yang memahami apa yang secara hukum menjadi tanggung jawab Notaris/PPAT." Pimpin
  demo dengan ini, bukan layar CRUD.
- Standalone-first (tanpa memaksa pembelian CRM/DMS/Schedule) sesuai dengan cara praktik
  Notaris/PPAT kecil sesungguhnya membeli software — memungkinkan Legal dijual ke praktisi solo
  dengan murah, lalu upsell modul Core seiring pertumbuhan praktik, bentuk monetisasi yang sama
  yang sudah ditetapkan untuk DMS dan Schedule.
- Dukungan mobile Field Operations adalah diferensiator konkret dan dapat didemokan terhadap
  pesaing desktop-only, secara langsung mencerminkan bias "must be reused... independently" dan
  "genuinely sellable" yang sudah diterapkan pada fitur ICS feed milik Schedule.
