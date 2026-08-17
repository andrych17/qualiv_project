# Modul Schedule
## Engine Kalender & Penjadwalan — Modul Core Bersama (dapat berdiri sendiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Hampir setiap modul di ERP pada akhirnya butuh menaruh sesuatu di kalender: task tindak lanjut,
pertemuan klien, tanggal sidang, slot pengiriman, shift, pemesanan ruangan. Jika dibiarkan
diselesaikan sendiri-sendiri oleh setiap modul, ini menghasilkan logika date-picker duplikat,
tidak ada notion bersama tentang "apakah orang/ruangan ini sedang bebas sekarang," tidak ada
satu kalender yang bisa dilihat user di seluruh pekerjaan mereka, dan recurrence/pengingat
ditemukan-ulang per modul (atau dilewati sama sekali karena rumit).

**Kebutuhan klien (dari daftar fitur Anda):**
- Task (to-do dengan tanggal jatuh tempo, tanpa peserta yang dibutuhkan) dan Event (dibatasi
  waktu, biasanya multi-peserta) sebagai konsep first-class, berbeda-tetapi-terpadu.
- Perencanaan & Penjadwalan **pada Resource** — bukan hanya orang: ruang rapat, peralatan,
  kendaraan, staf bersama — sehingga bekerja baik untuk penjadwalan kantor maupun penjadwalan
  lapangan/ops.
- Pemeriksaan ketersediaan — jangan biarkan dua hal dipesan pada resource/orang yang sama pada
  waktu yang tumpang tindih.
- Recurrence — "setiap Senin," "tanggal 1 setiap bulan," dll., standar-industri (RRULE) agar
  portabel dan familiar-bergaya-aplikasi-kalender.
- Integrasi konferensi (audio/video) — menghasilkan/melampirkan tautan gabung
  (Zoom/Meet/Teams/kustom) ketika sebuah event membutuhkannya, tanpa meng-hardcode satu vendor.
- Dukungan mobile — dapat dipakai di ponsel; data kalender juga harus dapat dikonsumsi di luar
  aplikasi (subscribe dari Apple/Google Calendar).
- Harus berfungsi **standalone** — dapat dijual/dipakai bahkan untuk tenant yang belum membeli
  Workflows atau modul vertikal, tetapi berintegrasi secara bersih ketika WNE (Workflow &
  Notification Engine) atau modul vertikal (misalnya deadline perkara Legal) ada.

**Konteks bisnis:** Scheduler terdaftar di `CLAUDE.md` §5 sebagai salah satu dari empat modul
**Core** yang dibangun sebelum vertikal pertama (Legal). Legal akan sangat mengandalkan ini
(tanggal sidang, deadline pengajuan, pertemuan klien), jadi model data butuh cara yang bersih
bagi modul lain untuk melampirkan record mereka sendiri ke item kalender tanpa Schedule tahu
apa pun tentang Legal — pola decoupling yang sama yang sudah ditetapkan WNE (tautan polimorfik
`subject_type` / `subject_id`, event alih-alih panggilan langsung).

# 2. Tujuan (Goals)

> Fitur yang ditetapkan yang menyelesaikan Latar Belakang di atas. **MVP-first** — apa pun yang
> tidak dibutuhkan untuk mengirim Scheduler yang dapat dipakai dan dijual didorong ke Future
> Version di bawah.

## Dalam cakupan untuk v1 (implementasi cepat)
- **Model item kalender terpadu** — Task dan Event berbagi satu tabel backbone (start/end,
  owner, status) dengan diskriminator `type`, sehingga satu tampilan kalender me-render
  keduanya. Menjaga implementasi tetap kecil alih-alih dua sistem paralel.
- **Pemesanan resource** — Resource yang dapat dipesan (ruangan / peralatan / kendaraan /
  staf-sebagai-resource), ditautkan ke item kalender via pivot booking, sehingga jadwal sebuah
  resource cukup "semua booking di mana resource_id = X."
- **Pemeriksaan ketersediaan** — satu panggilan service: *"apakah Resource X (atau User X)
  bebas antara T1–T2?"* — memeriksa booking yang tumpang tindih. Tabel jam-kerja mingguan
  sederhana opsional per resource untuk pemeriksaan "di luar jam kerja" dasar.
- **Recurrence (berbasis RRULE)** — menyimpan string RRULE iCalendar standar-industri
  (`FREQ=WEEKLY;BYDAY=MO;COUNT=10`, dll.) pada item kalender; memperluas kemunculan
  (occurrences) saat dibaca (tidak dimaterialisasi-ulang sebelumnya), dengan tabel exception
  kecil untuk "lewati yang ini" / "pindahkan yang ini." Ini jalur tercepat menuju recurrence
  yang benar dan tetap kompatibel dengan ekspor/impor ICS.
- **Integrasi konferensi** — `ConferenceDriverInterface` yang pluggable (mencerminkan pola
  `ChannelDriverInterface` milik WNE yang sudah Anda pakai), menyimpan provider + join URL +
  metadata pertemuan pada event. Driver v1: entri tautan manual/kustom + satu provider nyata
  (Zoom **atau** Google Meet, yang mana pun akses API-nya Anda dapatkan lebih dulu). Provider
  tambahan bersifat aditif nanti.
- **Dukungan mobile (v1 = responsif + subscribe, bukan aplikasi native)**:
  - Tampilan kalender responsif (day/week/month/agenda) via design system Vue/Inertia +
    Tailwind yang sudah ada (`DESIGN.md`) — belum butuh basis kode mobile terpisah.
  - URL feed **ICS** per-user/per-resource (ditandatangani, token UUID) sehingga orang bisa
    subscribe dari aplikasi kalender native ponsel mereka — murah untuk dibangun, nilai
    persepsi tinggi, benar-benar dapat dijual sebagai "jadwal Anda, di aplikasi kalender Anda
    sendiri."
- **Hook integrasi terpisah (decoupled)** — Schedule mempublikasikan event internal
  (`schedule.item_created`, `schedule.item_due_soon`, `schedule.item_cancelled`) yang bisa
  dirutekan WNE (jika terpasang/diaktifkan untuk tenant) ke notifikasi, persis seperti yang
  dilakukan Purchasing/HR di spesifikasi WNE. Schedule sendiri tidak pernah memanggil provider
  mail/SMS secara langsung.
- **Aman-standalone**: jika WNE tidak diaktifkan untuk sebuah tenant, Schedule tetap berfungsi
  penuh untuk task/event/resource/ketersediaan — hanya saja tidak ada notifikasi keluar (atau
  fallback ke badge in-app minimal bawaan "due today," tanpa channel eksternal).

## Future Version (secara eksplisit ditunda — jangan dibangun sekarang)
- **Auto-resolution** konflik resource / waitlisting / saran resource alternatif.
- **Pool/kapasitas** resource (misalnya "salah satu dari 5 laptop," bukan aset spesifik).
- **Sharing/delegasi** kalender (melihat/mengelola kalender orang lain).
- **Sinkronisasi eksternal dua-arah** dengan Google/Outlook (v1 ICS satu-arah, subscribe
  read-only).
- Tampilan **timezone per-peserta** (v1 menyimpan/beroperasi dalam satu timezone tenant + UTC;
  rendering timezone per-peserta yang layak adalah nice-to-have, bukan MVP).
- SLA/eskalasi pada Task yang terlambat (itu wilayah Workflow WNE jika suatu saat dibutuhkan —
  jangan duplikasi logika workflow di sini).
- Tampilan Gantt/utilisasi-resource drag-and-drop.
- Aplikasi mobile native / push notification (channel push sudah ada di lapisan WNE kapan pun
  Anda siap — Schedule tidak butuh miliknya sendiri).
- Widget booking publik yang dapat disematkan (misalnya "book a consultation" untuk klien
  eksternal).

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Kalender Utama

**Fungsi / fitur**
- Tampilan Day / Week / Month / Agenda, dapat di-toggle, menampilkan Task dan Event bersama.
- Filter berdasarkan: item saya / resource tertentu / modul tertentu (misalnya hanya item
  tertaut-Legal).
- Quick-create: klik slot waktu → mini-form inline (title, type, waktu, resource) tanpa
  meninggalkan kalender.
- Status Rail (sesuai `DESIGN.md`) pada setiap item: `danger` = task terlambat / booking
  konflik, `warning` = jatuh tempo segera, `success` = selesai, `info` = dihasilkan-sistem
  (misalnya kemunculan auto-recurring), border netral = item terjadwal biasa.

**Layout**
- Top bar: view switcher (Day/Week/Month/Agenda), navigator tanggal, tombol "+ New", dropdown
  filter resource.
- Utama: grid kalender (sesuai tampilan terpilih) atau daftar agenda.
- Panel samping (saat item diklik): drawer menampilkan detail lengkap, peserta, resource yang
  dipesan, tautan konferensi jika ada, ringkasan recurrence, dan aksi cepat (Edit / Cancel /
  Mark Done).

**Aturan / logika**
- Query kalender otomatis terlingkup ke DB tenant saat ini (DB-per-tenant — tidak butuh filter
  tenant level-aplikasi, tidak seperti lingkup `tenant_id` milik WNE).
- Item recurring diperluas di sisi server menjadi kemunculan virtual hanya untuk rentang
  tanggal yang terlihat (tidak pernah memateraialisasi seluruh seri recurrence menjadi baris).
- Konflik ketersediaan (resource yang dipesan ganda) muncul sebagai Status Rail `danger`
  langsung pada kalender, bukan hanya dalam pesan validasi tersembunyi.

## 3B. Manajemen Task (Form)

- Field: `title`, `description`, `due_at`, `priority` (low/normal/high), `status`
  (open/in_progress/done/cancelled), `owner_id`, `subject_type`/`subject_id` (tautan
  polimorfik opsional kembali ke record yang memicunya, misalnya sebuah perkara Legal),
  `recurrence_rule` (opsional).
- Tidak butuh peserta/resource — Task adalah single-owner secara default (masih bisa menamai
  user lain sebagai watcher di v1 via baris peserta sederhana dengan role = `watcher`,
  opsional).
- "Mark Done" adalah aksi satu-klik baik dari dashboard maupun drawer item.

## 3C. Manajemen Event (Form)

- Field: `title`, `description`, `start_at`, `end_at`, `all_day`, `location` (teks bebas,
  untuk event fisik), `status` (scheduled/cancelled), `owner_id`,
  `subject_type`/`subject_id` (opsional), `recurrence_rule` (opsional).
- **Peserta**: menambahkan user internal (dan nanti, email eksternal — v1 hanya mendukung user
  internal untuk menjaga cakupan tetap kecil; email undangan eksternal adalah fast follow,
  bukan blocker).
- **Resource**: melampirkan satu atau lebih resource yang dapat dipesan (misalnya "Conference
  Room A" + "Projector").
- **Konferensi**: toggle opsional "Add video/audio link" → memilih provider yang dikonfigurasi,
  membuat pertemuan via driver provider tersebut (atau membiarkan user menempelkan tautan
  manual).

## 3D. Manajemen Resource (Form)

- `resource_types` (master): Room, Equipment, Vehicle, Staff — daftar yang dapat diperluas,
  bukan enum hardcoded, sehingga tenant bisa menambahkan tipe mereka sendiri tanpa perubahan
  kode.
- `resources`: name, type, location/notes, is_active, kapasitas opsional (int, hanya
  informasional di v1 — tidak ditegakkan/dikumpulkan, lihat Future Version).
- **Jam kerja** sederhana opsional per resource (hari-dalam-minggu + waktu start/end) untuk
  mendukung pemeriksaan ketersediaan di bawah. Jika tidak diatur, resource diperlakukan
  tersedia 24/7.

## 3E. Pemeriksaan Ketersediaan (Engine)

**Tujuan:** satu service yang dapat dipakai ulang yang dipanggil setiap form lain sebelum
mengonfirmasi booking.

- `AvailabilityService::isFree(resourceOrUserId, startAt, endAt): bool`
- `AvailabilityService::findConflicts(resourceOrUserId, startAt, endAt): array`
- Logika: pemeriksaan overlap (`existing.start < new.end AND existing.end > new.start`)
  terhadap booking aktif (tidak-dibatalkan) untuk resource/user tersebut, ditambah — jika jam
  kerja didefinisikan — pemeriksaan bahwa jendela yang diminta jatuh di dalamnya.
- Dipanggil secara sinkron saat penyimpanan (memblokir penyimpanan yang konflik dengan error
  yang jelas, sesuai panduan suara `DESIGN.md`: *"Conference Room A is already booked 2:00–3:00
  PM. Choose another time or resource."*) — tidak butuh async/queue untuk ini, ini query DB
  yang cepat.

## 3F. Recurrence Engine

**Tujuan:** memperluas aturan recurrence menjadi kemunculan konkret untuk rentang tanggal
tertentu, dan menangani edit/pembatalan "hanya kemunculan ini."

- Menyimpan satu `recurrence_rule` (string RRULE, subset RFC 5545: `FREQ`, `INTERVAL`,
  `BYDAY`, `COUNT` atau `UNTIL`) pada item kalender induk.
- Gunakan library RRULE yang sudah ada dan teruji-pertempuran (misalnya `simshaun/recurr`
  untuk PHP) alih-alih membuat sendiri matematika recurrence — ini persis jenis kasus
  "jangan menemukan-ulang" yang menjaga v1 tetap cepat.
- Tabel `recurrence_exceptions`: `(calendar_item_id, original_occurrence_date, action:
  skipped|moved|modified, override_start_at, override_end_at)` — memungkinkan user menghapus
  atau menjadwalkan-ulang satu instance tanpa merusak seri.
- Pemeriksaan ketersediaan berjalan **per kemunculan yang diperluas**, bukan hanya sekali pada
  induk — booking recurring mingguan tidak boleh diam-diam konflik pada minggu ke-3.

## 3G. Integrasi Konferensi (Engine)

**Tujuan:** melampirkan tautan gabung ke sebuah Event tanpa Schedule meng-hardcode vendor.

- `ConferenceDriverInterface`: `createMeeting(event): ConferenceLink`,
  `cancelMeeting(conferenceLink): void` — pola driver-aditif yang sama dengan
  `ChannelDriverInterface` milik WNE, sehingga provider baru adalah kelas baru, bukan
  perubahan inti.
- Driver v1: `ManualLinkDriver` (user menempelkan URL apa pun — nol biaya integrasi, dikirim
  hari pertama) dan **satu** driver provider nyata (Zoom atau Google Meet — pilih yang setup
  OAuth-nya lebih sederhana untuk solo dev; disarankan mulai di sini karena ini nilai persepsi
  tertinggi untuk kode paling sedikit).
- Disimpan per event: kode provider, join URL, ID pertemuan eksternal (untuk panggilan
  cancel/update masa depan), info dial-in jika berlaku (field teks, bukan terstruktur — tidak
  layak dimodelkan di v1).

# 4. Penyimpanan

> Daftar tabel dan penyimpanan object file yang dipakai modul ini.

**Schema:** `SCHEDULE` (sesuai struktur database `CLAUDE.md` §7 — satu schema di dalam
database setiap tenant; tidak butuh kolom `tenant_id` karena database itu sendiri adalah batas
isolasi).

**Tabel master** (satu kata)
- `resource_types`
- `resources`
- `conference_providers`

**Tabel transaksi** (`sched_` + level, sesuai konvensi `wrkflow_`/`msg_` milik WNE)
- `sched_items` — header Task/Event terpadu (kolom `type`: `task` | `event`)
- `sched_bookings` — pivot: resource mana yang dipesan ke baris `sched_items` mana
- `sched_attendees` — user mana yang ada di item (role owner/attendee/watcher)
- `sched_recurrence_exceptions` — override per-kemunculan untuk item recurring
- `sched_conference_links` — metadata konferensi untuk event yang memilikinya
- `sched_working_hours` — jendela ketersediaan mingguan opsional per-resource
- `sched_calendar_feeds` — token UUID yang ditandatangani untuk URL subscription ICS (per user
  atau per resource)

**Object files:** tidak dibutuhkan untuk v1 (belum ada lampiran pada item kalender). Jika
lampiran task/event diinginkan nanti, mereka akan hidup di bawah struktur R2 per-tenant yang
sudah ada sebagai `tenant_xxx/SCHEDULE/...`, konsisten dengan `CLAUDE.md` §7B.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul modular monolith di `app/Modules/Schedule/`, bentuk yang sama
dengan setiap modul Core lain (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`,
`Enums/`, `Routes/`). Tidak ada ekstraksi microservice di sini — Schedule adalah CRUD biasa +
beberapa service berat-kalkulasi (ketersediaan, ekspansi recurrence), tidak satu pun butuh
runtime berbeda atau scaling independen sesuai kriteria ekstraksi `CLAUDE.md` §2.

**Integrasi lintas-modul (terpisah, digerakkan-event — seam yang sama dengan WNE):**
- Modul lain melampirkan ke item kalender via `subject_type` / `subject_id` (polimorfik),
  tidak pernah foreign key keras ke Legal/CRM/dll. — Schedule tetap agnostik-vertikal.
- Schedule mempublikasikan event internal (`schedule.item_created`, `schedule.item_due_soon`,
  `schedule.item_cancelled`); WNE mendengarkan dan menerapkan aturan routing-nya sendiri **jika
  tenant mengaktifkan WNE**. Schedule tidak punya dependensi compile-time terhadap kelas WNE.
- Sadar feature-flag: `SCHEDULE` dan `NOTIFICATIONS` (WNE) masing-masing dapat di-toggle per
  tenant/plan secara independen, sesuai catatan plan/feature-flagging `CLAUDE.md` §4 —
  Schedule tidak boleh throw jika WNE sekadar tidak ada untuk sebuah tenant.

**Recurrence:** gunakan `simshaun/recurr` (atau library RRULE terpelihara yang setara) —
jangan membuat sendiri parsing RFC 5545. Perluas kemunculan saat dibaca untuk rentang tanggal
yang terlihat; jangan pernah pre-generate baris untuk seluruh seri.

**Ekspor ICS:** hasilkan secara on-the-fly dari `sched_items` + recurrence yang diperluas
menggunakan penulis ICS kecil (misalnya `spatie/icalendar-generator`); sajikan pada URL yang
ditandatangani berkunci token UUID di `sched_calendar_feeds`, sehingga dapat dicabut tanpa
menyentuh auth user.

**Queues:** Pemeriksaan ketersediaan dan CRUD bersifat sinkron (cepat, menghadap-user). Hanya
tahap "publikasikan event `schedule.*` → WNE mengambilnya → mengirim notifikasi" yang async,
dan queue itu sudah ada di sisi WNE (queue `notifications`) — Schedule tidak butuh queue-nya
sendiri untuk v1.

**IDs:** `BIGSERIAL` untuk semua PK/FK internal sesuai `CLAUDE.md` §7. `sched_calendar_feeds.
token` adalah UUID (menghadap-eksternal, muncul di URL — tidak boleh menjadi ID sekuensial yang
mudah ditebak).

**Ekstensibilitas:** provider konferensi baru = kelas baru yang mengimplementasikan
`ConferenceDriverInterface`, terdaftar di driver map — tanpa perubahan engine inti,
mencerminkan pola channel-driver WNE yang sudah Anda pakai, sehingga kedua modul tetap
konsisten secara konseptual bagi Anda-di-masa-depan yang membaca ulang kodenya.
