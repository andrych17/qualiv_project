# Modul DMS
## Document Management System — Modul Inti Bersama (juga dapat digunakan mandiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Hampir setiap modul di ERP pada akhirnya butuh melampirkan, menyimpan, dan mengambil dokumen:
Purchasing butuh penawaran vendor dan PO yang sudah ditandatangani, HR butuh kontrak dan hasil
scan KTP, dan — yang paling mendesak, karena **Legal adalah vertikal berbayar pertama** — Legal
butuh berkas kasus, filing, kontrak, dan korespondensi dengan persyaratan kerahasiaan dan
retensi yang ketat. Jika dibiarkan tidak diselesaikan secara terpusat, ini mengulangi
anti-pola persis yang berusaha dihindari modul WNE:

- Setiap modul menciptakan kode upload/penyimpanan miliknya sendiri — tidak konsisten, tidak
  dapat digunakan ulang, tanpa versioning atau audit trail bersama.
- Tidak ada tempat terpusat untuk mencari "dokumen mana yang menyebutkan X" lintas modul.
- Tidak ada perilaku retensi/legal-hold yang konsisten — risiko kepatuhan nyata untuk produk
  vertikal Legal, di mana menghancurkan (atau gagal menghancurkan) sebuah dokumen sesuai jadwal
  memiliki konsekuensi hukum.
- Tidak ada UI preview/riwayat versi yang dapat digunakan ulang — setiap modul akan membangun
  miliknya sendiri.
- Kebutuhan kerahasiaan (dokumen kasus tidak boleh terlihat di luar tim kasus) tidak punya titik
  penegakan bersama.

**Kebutuhan klien:**
- Sadar multi-tenant, penyimpanan terisolasi per tenant (sudah diputuskan: Cloudflare R2, key
  berprefiks-tenant — lihat `CLAUDE.md` §7B).
- Modul mana pun dapat melampirkan dokumen ke record miliknya sendiri **tanpa mengetahui detail
  internal penyimpanan**, via facade/event, pola yang sama seperti WNE.
- Harus juga bekerja **mandiri** — seorang tenant dapat menggunakan DMS sebagai pustaka dokumen
  biasa (folder, upload, pencarian) tanpa apa pun yang lain terpasang, karena dapat dijual
  sebagai item lini tersendiri.
- Version control wajib — dokumen hukum diamendemen; tidak ada yang boleh secara diam-diam
  ditimpa.
- Audit trail penuh — siapa yang mengunggah/melihat/mengunduh/mengedit/menghapus, dan kapan
  (kebutuhan discoverability hukum dan bukti kerahasiaan).
- Aturan retensi harus dapat dikonfigurasi per tenant/tipe dokumen, dengan override **legal
  hold** yang memblokir penghapusan terlepas dari jadwal.
- Dokumen harus dapat ditemukan lebih dari sekadar nama file yang persis — pencarian full-text
  atas konten diharapkan; pencarian semantik/AI dan OCR diinginkan tetapi **tidak wajib untuk
  peluncuran**.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — luncurkan sesuatu yang bisa dijual dengan cepat, tunda
> pekerjaan AI/infra berat.

**MVP (diluncurkan bersama peluncuran vertikal Legal)**
- **Layanan penyimpanan terpusat dan terdekopel.** Modul lain berintegrasi via facade
  `DocumentService` (`attach()`, `upload()`, `getVersions()`, `search()`) atau event
  `DocumentUploaded` / `DocumentAttachRequested` — pola seam yang sama seperti WNE (lihat
  `WNE_SPECS.md` §4).
- **Lampiran polimorfik** — record modul mana pun dapat memiliki N dokumen (`subject_type` +
  `subject_id`), plus dokumen bisa ada tanpa modul pemilik sama sekali (pemakaian pustaka
  mandiri).
- **Pohon folder/kategori** untuk penjelajahan mandiri, dengan flag akses sederhana per-folder
  (private / team / tenant-wide) — cukup baik untuk kerahasiaan level-peluncuran, tanpa perlu
  membangun engine RBAC penuh.
- **Manajemen metadata** — set metadata dasar (judul, deskripsi, tipe dokumen, tanggal
  efektif/kedaluwarsa) ditambah custom field yang didefinisikan tenant, menggunakan ulang pola
  schema `CUSTOMFIELDS` yang sudah ada alih-alih menciptakan yang kedua.
- **Version control** — setiap unggah ulang membuat versi baru yang immutable; tidak ada yang
  pernah ditimpa di object storage; pointer versi saat ini + riwayat lengkap.
- **Lifecycle dokumen** — `draft → active → archived → expired → purged`, digerakkan
  admin/sistem.
- **Audit trail** — log immutable dari upload / view / download / edit-metadata / version /
  restore / delete / perubahan-permission.
- **Manajemen retensi** — kebijakan retensi per tenant/tipe-dokumen (periode + aksi saat
  kedaluwarsa: notify, archive, atau delete) dengan flag **legal hold** yang meng-override aksi
  terjadwal mana pun. Menggunakan ulang **WNE** untuk notifikasi "dokumen akan kedaluwarsa,
  mohon ditinjau" — tanpa kode notifikasi terpisah yang dibutuhkan.
- **Relasi objek dasar** — menautkan dokumen satu sama lain (`amendment_of`, `supersedes`,
  `attachment_of`, `related_to`) sebagai tabel lookup sederhana.
- **Pencarian keyword/full-text** — pencarian full-text native PostgreSQL (`tsvector`) atas
  filename, deskripsi, tag, dan metadata. Tidak butuh AI.

**Future Version (pasca-peluncuran, begitu ada volume pemakaian/pendapatan nyata yang
menjustifikasi pembangunannya)**
- **OCR cerdas** — mengekstrak teks dari dokumen scan/gambar. Secara alami menjadi kandidat
  ekstraksi sesuai `CLAUDE.md` §2 (pemrosesan async berat, runtime berbeda lebih cocok —
  misalnya Python + Tesseract/cloud OCR API), bukan urusan monolith.
- **Pencarian semantik** — embedding + `pgvector`, ranking hybrid keyword+semantik. Bergantung
  pada teks hasil OCR sudah ada lebih dulu.
- **Auto-tagging** — LLM mengklasifikasikan tipe/tag dokumen dari teks hasil ekstraksinya,
  dengan antrean tinjauan manusia sebelum tag diterapkan secara otomatis.
- **ACL granular / berbagi** — grant eksplisit per-dokumen untuk user/role, tautan berbagi
  eksternal dengan kedaluwarsa — melampaui flag level-folder milik MVP.
- **Visualisasi graf relasi** — tampilan visual bagaimana dokumen saling terhubung.
- **UX deduplikasi** — checksum ditangkap sejak hari pertama (dibutuhkan untuk integritas
  bagaimanapun juga); prompt "file ini sudah ada, tautkan saja?" adalah polesan masa depan.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama (Pustaka Dokumen)

**Fungsi / fitur**
- Pohon folder + list/grid dokumen. Filter: modul pemilik, tipe dokumen, tag, status, rentang
  tanggal, "akan kedaluwarsa" / "sedang legal hold".
- Panel preview cepat (PDF/gambar inline; tipe lain menampilkan metadata + download).
- Tombol upload (tunggal atau bulk), drag-and-drop.

**Layout**
- Kiri: pohon folder (mode mandiri) atau list "Terlampir pada record ini" (mode embedded,
  misalnya di dalam halaman kasus Legal).
- Utama: Data table (sesuai inventaris komponen `DESIGN.md`) dengan Status Rail berwarna sesuai
  state lifecycle (`active` = neutral/success, `expiring soon` = warning, `expired`/`on hold` =
  danger, `archived` = border neutral).
- Klik baris membuka drawer: metadata, tab riwayat versi, tab audit log, tab relasi.

**Aturan / logika**
- Terlingkup-tenant secara global scope default, sama seperti WNE.
- Dokumen yang embedded di dalam record modul (misalnya kasus Legal) selalu juga terlihat dari
  pustaka mandiri, difilter berdasarkan "modul pemilik = Legal" — satu store, dua tampilan.
- Flag akses folder (`private` / `team` / `tenant`) ditegakkan saat query, bukan hanya
  disembunyikan di UI.

## 3B. Entri Dokumen (Upload / Edit)

- Field: file, folder, `doc_type`, judul, deskripsi, tag (free-tag di MVP, kosakata terkontrol
  belakangan), metadata custom (per `doc_type`, via CUSTOMFIELDS), referensi modul pemilik
  (`subject_type` / `subject_id`, opsional), tanggal efektif, tanggal kedaluwarsa, kebijakan
  retensi (default dari `doc_type`, dapat di-override).
- **Saat upload:** menghitung checksum SHA-256, stream ke R2 di bawah
  `tenant_{id}/DMS/{module}/{yyyy}/{mm}/{document_uuid}/v{n}.{ext}`, membuat baris `documents`
  (jika baru) atau baris `document_versions` baru (jika unggah ulang), memicu event
  `DocumentUploaded` → entri audit log. Hook OCR/auto-tag mendengarkan event ini tetapi
  merupakan no-op sampai Future Version diluncurkan.

## 3C. Penampil Riwayat Versi

- Daftar versi (pengunggah, timestamp, ukuran, checksum, catatan).
- Aksi: unduh versi tertentu, restore sebagai versi saat ini, bandingkan metadata antara dua
  versi.
- Restore membuat versi **baru** yang menunjuk ke file lama — riwayat tidak pernah destruktif.

## 3D. Manajemen Folder / Kategori (pemakaian mandiri)

- CRUD pohon, per-folder: `doc_type` default, kebijakan retensi default, flag akses.
- Menghapus folder yang tidak kosong mengharuskan menugaskan-ulang atau meng-archive
  dokumen-dokumennya lebih dulu.

## 3E. Engine Pencarian (MVP: keyword; Future: semantik)

- MVP: kolom generated `tsvector` Postgres atas filename + judul + deskripsi + tag +
  `extracted_text` (nullable, diisi belakangan oleh OCR). Hasil yang di-ranking, dapat
  difilter oleh facet yang sama seperti dashboard.
- Future: kolom embedding `pgvector` pada `documents`/`document_versions`, re-ranking hybrid,
  "temukan dokumen yang mirip dengan yang ini."

## 3F. Engine Retensi & Lifecycle

- State: `draft → active → archived → expired → purged` (soft-delete dulu, hard-delete hanya
  setelah periode tenggang yang dapat dikonfigurasi).
- `retention_policies`: per tenant × doc_type, `retention_period_days`, `action_on_expiry`
  (`notify_only` / `archive` / `delete`), flag `legal_hold_overridable`.
- Job terjadwal (harian) memindai dokumen yang mendekati/mencapai kedaluwarsa:
  - Jika `legal_hold = true` pada dokumen → lewati sepenuhnya, catat entri audit "hold mencegah
    aksi".
  - Jika tidak, memicu event `WorkflowRequested`/`NotificationRequested` ke dalam **WNE**
    (gunakan ulang, tanpa kode notifikasi baru) agar seorang reviewer mengonfirmasi sebelum
    aksi destruktif, atau aksi yang dikonfigurasi berjalan otomatis untuk kebijakan
    `notify_only`/`archive`.

## 3G. Engine OCR & Auto-Tagging — **Future Version**

- Job async mengirim ke microservice eksternal (Python; Tesseract atau cloud OCR API) —
  ekstraksi yang terjustifikasi sesuai `CLAUDE.md` §2 (runtime berbeda, beban kerja async
  berat), alasan yang sama yang sudah diterapkan pada gateway tenancy on-prem.
- Menulis `extracted_text` kembali via callback/webhook, yang me-refresh `tsvector` pencarian
  dan (begitu pencarian semantik diluncurkan) embedding-nya.
- Auto-tagging: LLM mengusulkan tipe/tag dokumen dari `extracted_text`; masuk ke antrean
  tinjauan, tidak pernah diterapkan otomatis secara diam-diam — menjaga ekspektasi
  kerahasiaan/akurasi tetap utuh untuk dokumen hukum.

## 3H. Engine Relasi Objek

- MVP: tabel `document_relations` — `source_document_id`, `target_document_id`,
  `relation_type` (`version_of` bersifat implisit via `document_versions`; tipe eksplisit di
  sini adalah `amendment_of`, `supersedes`, `attachment_of`, `related_to`).
- Future: visualisasi graf jaringan relasi sebuah dokumen.

## 3I. Audit Trail

- `dms.access_logs`: append-only, satu baris per aksi (`upload`, `view`, `download`,
  `edit_metadata`, `version_upload`, `restore`, `delete`, `permission_change`,
  `hold_applied`, `hold_released`), aktor, timestamp, IP (opsional), referensi dokumen +
  versi.
- Tidak ada update/delete yang diizinkan pada tabel ini di lapisan aplikasi — integritas audit
  untuk discoverability hukum.

---

# 4. Penyimpanan

**Database (schema `DMS`, DB tenant — konsisten dengan `CLAUDE.md` §7A):**
- `dms.folders`
- `dms.doc_types`
- `dms.documents` (pointer versi saat ini, state lifecycle, referensi modul pemilik, folder,
  referensi kebijakan retensi, flag legal_hold)
- `dms.document_versions` (immutable, checksum, storage key, ukuran, mime type, uploaded_by)
- `dms.document_relations`
- `dms.tags`, `dms.document_tags`
- `dms.retention_policies`
- `dms.access_logs` (audit trail, append-only)
- Metadata custom menumpang pada schema/mekanisme `CUSTOMFIELDS` yang sudah ada, alih-alih
  mekanisme khusus DMS.

**File Objek (sesuai `CLAUDE.md` §7B, sudah mereservasi folder `DMS/` per tenant):**
```text
tenant_001/DMS/
├── {owning_module}/{yyyy}/{mm}/{document_uuid}/
│   ├── v1.{ext}
│   ├── v2.{ext}
│   └── ...
```
- Satu bucket Cloudflare R2 bersama, key berprefiks-tenant — konvensi yang sama seperti sisa
  platform. Versi tidak pernah ditimpa atau dihapus dari penyimpanan sampai hard-purge setelah
  periode tenggang retensi.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu AI Coding

**Pola arsitektur:** Monolitik-modular, bentuk yang sama seperti WNE.
- **Facade internal** — `DocumentService::upload()`, `::attach()`, `::getVersions()`,
  `::search()`, `::applyRetention()` — untuk modul same-process (lebih disukai).
- **Event bus internal** — `DocumentUploaded`, `DocumentAttachRequested`,
  `RetentionActionDue` — mendekopel pemanggil, dan merupakan seam yang memungkinkan pekerjaan
  OCR/AI dipindahkan keluar monolith belakangan tanpa menyentuh modul pemanggil mana pun.
- **Penggunaan ulang lintas-modul dari WNE** untuk semua notifikasi retensi/kedaluwarsa dan
  langkah approval mana pun (misalnya "setujui penghapusan permanen") — jangan membangun jalur
  notifikasi paralel di dalam DMS.

**Batas cakupan MVP (bersikap eksplisit tentang apa yang ditunda):**
- Kolom `extracted_text` ada sejak hari pertama (nullable) sehingga infrastruktur dan skema
  pencarian tidak butuh migration yang breaking belakangan — kolom ini sekadar belum terisi
  sampai OCR diluncurkan.
- Tidak ada `pgvector`/embedding di MVP; tambahkan sebagai migration aditif saat pencarian
  semantik dibangun.
- Flag akses level-folder sudah cukup untuk peluncuran; jangan membangun ACL per-dokumen
  sampai ada klien yang benar-benar memintanya — sesuai bias MVP "versi sellable yang lebih
  sederhana dulu" di `CLAUDE.md` §10.

**Integritas versioning:** checksum SHA-256 ditangkap pada setiap versi, baik untuk dedupe
di masa depan maupun untuk membuktikan bahwa sebuah dokumen belum diubah — relevan untuk
pemakaian evidentiary hukum.

**Ekstensibilitas:** provider OCR/tagging mendaftar di balik pola interface driver yang sama
yang dipakai WNE untuk channel (`OcrDriverInterface`) — mengganti Tesseract dengan cloud API
belakangan bersifat aditif, bukan penulisan ulang.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3A/3B/3D (upload + browse) →
3C (versioning) → 3I (audit trail, murah dan bernilai tinggi) → 3F (retensi, disambungkan ke
WNE) → 3H (relasi) → 3E pencarian keyword — luncurkan pada titik ini — lalu revisit
3G/pencarian semantik sebagai Future Version.
