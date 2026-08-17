# Modul Projects
## Modul Core Bersama — Manajemen Proyek, Pelacakan Isu, Kanban Board, Lampiran & Komentar

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap organisasi mengelola item pekerjaan internal dan menghadap-klien — deliverable klien,
rollout sistem, bug software, perbaikan operasional, dan audit kepatuhan:

- Tanpa sistem pelacakan proyek dan isu yang tersentralisasi, alokasi tugas hidup di email,
  pesan chat, atau spreadsheet eksternal yang tidak terstruktur.
- Team lead kekurangan visibilitas real-time terhadap status backlog proyek, distribusi beban
  kerja lintas anggota tim, dan deliverable yang terlambat.
- Isu terkait akun klien, perkara hukum, atau audit inventory berakhir terputus dari platform
  ERP inti, menyebabkan duplikasi usaha dan konteks historis yang hilang.
- Alat manajemen proyek standalone membutuhkan lisensi user terpisah, kurang isolasi data
  multi-tenant, dan tidak bisa berintegrasi secara mulus dengan layanan bersama ERP (Users,
  Custom Fields, Notifications, Document Management).

**Kebutuhan klien:**
- **Registry Proyek Terpadu**: Mengelola proyek dengan kode unik seluruh-tenant, nama,
  deskripsi, pemilik lead, tanggal mulai/selesai, dan siklus hidup status.
- **Kanban Board Interaktif**: Kolom status visual (To Do, In Progress, Done) dengan transisi
  status drag-and-drop HTML5, penugasan assignee cepat bergaya Jira, indikator prioritas, dan
  peringatan tanggal-jatuh-tempo terlambat.
- **Dukungan Tampilan Ganda**: Beralih antara Kanban Board visual dan tampilan List terstruktur
  (DataTable dikelompokkan per status dengan footer jumlah subtotal).
- **Manajemen Backlog Isu**: Melacak item pekerjaan yang dikategorikan menurut tipe (Task, Bug,
  Story), prioritas (Low, Medium, High, Urgent), assignee, dan tanggal jatuh tempo.
- **Lampiran & Komentar**: Mendukung lampiran file dan komentar berulir per isu untuk menjaga
  jejak audit yang lengkap.
- **Isolasi Schema Multi-Tenant**: Semua data proyek berada di bawah schema PostgreSQL
  `PROJECTS` yang terlingkup-tenant (`PROJECTS.projects`, `PROJECTS.issues`,
  `PROJECTS.issue_comments`, `PROJECTS.issue_attachments`).
- **Custom Fields & Ekstensibilitas**: Mendukung custom field spesifik-tenant via
  `CUSTOMFIELDS` tanpa migration database inti.

---

# 2. Tujuan (Goals)

> Fitur yang ditetapkan yang menyelesaikan Latar Belakang di atas.

- **Registry Master Proyek**: CRUD terlingkup-tenant untuk Projects dengan generasi UUID
  otomatis, prefiks kode isu berbasis-sequence, dan penugasan lead proyek dari direktori user.
- **Kanban Board Engine (`Show.vue`)**: Board drag-and-drop Vue 3 / HTML5 berfitur lengkap yang
  mendukung:
  - Transisi status isu drag-and-drop (`todo` → `in_progress` → `done`).
  - Combobox pencarian quick-assign (`FormSearchableSelect`) langsung di kartu isu.
  - Indikator badge prioritas (Low, Medium, High, Urgent) dan indikator lampiran
    (`Paperclip`).
  - Indikator visual tanggal-jatuh-tempo terlambat dengan pengurutan otomatis (item terlambat
    muncul lebih dulu).
- **List View & Subtotal DataTable**: Tampilan tabel alternatif dengan pengurutan sisi-klien,
  pencarian kode/judul, dan pengelompokan status yang menampilkan jumlah task per kolom.
- **Detail Isu & Log Aktivitas**: Mengelola siklus hidup isu, deskripsi, tanggal jatuh tempo,
  perubahan assignee, unggah file, dan riwayat komentar.
- **Keamanan Multi-tenant**: Semua model mewarisi lingkup schema tenant (schema `PROJECTS`)
  dan permission user yang ketat.

---

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Projects Index (`Projects/Index.vue`)

**Fungsi / Fitur**
- Tabel ringkasan semua proyek tenant.
- Pencarian berdasarkan kode atau nama proyek.
- Filter berdasarkan status (`planning`, `active`, `on_hold`, `completed`, `cancelled`).
- Tabel berpaginasi menampilkan kode proyek, nama, nama lead, jumlah isu, tanggal pembuatan,
  dan tautan aksi (View Board, Edit, Delete).
- Dukungan penghapusan massal untuk proyek terpilih.

**Aturan / Logika**
- Paginasi sisi-server via `TableQuery::applySort`.
- Jumlah isu dihitung via `withCount('issues')`.

## 3B. Entri & Edit Proyek (`Projects/Create.vue` & `Edit.vue`)

**Field**
- `code`: Kode proyek unik pendek (misalnya `PRJ-001`, `WEBSITE`).
- `name`: Judul proyek lengkap.
- `description`: Deskripsi teks / cakupan proyek.
- `lead_id`: Lead proyek yang dapat dipilih dari direktori user aktif (`FormSearchableSelect`).
- `status`: Status proyek (`planning`, `active`, `on_hold`, `completed`, `cancelled`).
- `start_date` & `end_date`: Batas garis waktu proyek opsional.

**Aturan / Logika**
- Menghasilkan `uuid` otomatis saat pembuatan jika tidak disediakan.
- Memvalidasi keunikan kode dalam schema tenant.

## 3C. Board & Kanban Proyek (`Projects/Show.vue`)

**Layout & Tampilan**
- **Tab / Toggle Tampilan**: Beralih antara **Board** (kolom Kanban) dan **List** (DataTable).
- **Info Header**: Menampilkan kode proyek, nama, lead, rentang tanggal, dan statistik cepat.
- **Quick Task Bar**: Form satu-baris untuk membuat task baru langsung ke kolom `To Do` dengan
  judul, tipe, prioritas, dan assignee.
- **Kolom Kanban**:
  - `To Do`
  - `In Progress`
  - `Done`
- **Elemen Kartu**:
  - Kode & Judul Isu (tautan ke Edit/Detail Isu).
  - Badge Tipe & Prioritas (diberi warna sesuai urgensi).
  - Dropdown Quick-Assignee (`FormSearchableSelect` pada kartu).
  - Jumlah lampiran (ikon `Paperclip` + jumlah).
  - Badge tanggal jatuh tempo (disorot merah ketika terlambat).

**Drag & Drop & Aturan State**
- `onDragStart` HTML5 menangkap ID isu.
- `onDrop` memicu request PATCH ke `projects.issues.updateStatus` dengan
  `preserveScroll: true`.
- Mengubah assignee pada kartu memicu request PATCH ke `projects.issues.updateAssignee` dengan
  `preserveScroll: true`.
- Item terlambat (`due_date < today` dan `status != done`) menampilkan badge peringatan yang
  menonjol dan diurutkan ke atas kolom.

## 3D. Manajemen Isu (`Projects/Issues/Edit.vue`)

**Field & Schema**
- `code`: Kode isu berbasis-sequence (misalnya `PRJ-001-1`, `PRJ-001-2`).
- `title`: Ringkasan task pendek.
- `type`: `task`, `bug`, `story`.
- `status`: `todo`, `in_progress`, `done`.
- `priority`: `low`, `medium`, `high`, `urgent`.
- `assignee_id`: ID user anggota tim yang ditugaskan.
- `due_date`: Tanggal isu harus diselesaikan.
- `description`: Detail task lengkap / Markdown.
- **Lampiran**: Dukungan unggah file (`PROJECTS.issue_attachments`).
- **Komentar**: Diskusi berulir (`PROJECTS.issue_comments`).

---

# 4. Penyimpanan

> Tabel dan tata letak schema di bawah schema PostgreSQL `PROJECTS` milik tenant.

### Tabel
1. `PROJECTS.projects`
   - `id`: `bigserial primary key`
   - `uuid`: `uuid not null`
   - `code`: `varchar(50) not null`
   - `name`: `varchar(255) not null`
   - `description`: `text nullable`
   - `status`: `varchar(30) default 'active'`
   - `lead_id`: `bigint nullable references USERS(id)`
   - `start_date`: `date nullable`
   - `end_date`: `date nullable`
   - `next_issue_seq`: `integer default 1`
   - `created_at`, `updated_at`: `timestamps`

2. `PROJECTS.issues`
   - `id`: `bigserial primary key`
   - `project_id`: `bigint not null references PROJECTS.projects(id) on delete cascade`
   - `code`: `varchar(60) not null`
   - `title`: `varchar(255) not null`
   - `type`: `varchar(30) default 'task'`
   - `status`: `varchar(30) default 'todo'`
   - `priority`: `varchar(30) default 'medium'`
   - `assignee_id`: `bigint nullable references USERS(id)`
   - `due_date`: `date nullable`
   - `description`: `text nullable`
   - `created_at`, `updated_at`: `timestamps`

3. `PROJECTS.issue_comments`
   - `id`: `bigserial primary key`
   - `issue_id`: `bigint not null references PROJECTS.issues(id) on delete cascade`
   - `user_id`: `bigint not null references USERS(id)`
   - `comment`: `text not null`
   - `created_at`, `updated_at`: `timestamps`

4. `PROJECTS.issue_attachments`
   - `id`: `bigserial primary key`
   - `issue_id`: `bigint not null references PROJECTS.issues(id) on delete cascade`
   - `file_path`: `varchar(255) not null`
   - `file_name`: `varchar(255) not null`
   - `file_size`: `bigint not null`
   - `created_at`, `updated_at`: `timestamps`

---

# 5. Catatan Teknis

- **Tech Stack Frontend**: Vue 3 (Options/Composition API dengan `<script setup lang="ts">`),
  Inertia.js, Tailwind CSS, ikon Lucide (`Paperclip`).
- **Implementasi Drag & Drop**: API drag/drop HTML5 murni (`dragstart`, `dragover`, `drop`)
  tanpa dependensi NPM eksternal yang berat.
- **UI Optimistis & Preservasi Scroll**: Semua update status dan assignee menggunakan
  `preserveScroll: true` milik Inertia untuk mencegah lompatan scroll pada update board.
- **Isolasi Tenant**: Multi-tenancy berbasis schema (`PROJECTS.*`) terisolasi otomatis per
  database tenant.
- **Custom Fields**: Kompatibel dengan registry `CUSTOMFIELDS` untuk ekstensi field per-tenant
  pada projects dan issues.
