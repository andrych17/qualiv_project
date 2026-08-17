# Modul WNE
## Workflow & Notification Engine — Modul Bersama Inti (mampu berdiri sendiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap modul di ERP ini pada akhirnya membutuhkan dua hal: **sesuatu harus bergerak melalui
serangkaian keputusan/persetujuan**, dan **seseorang harus diberi tahu tentang itu**. Jika
dibiarkan tidak diselesaikan secara terpusat, ini mengulangi persis anti-pola yang secara
eksplisit dirancang untuk dihindari oleh setiap modul Core berikutnya (DMS, CRM, Schedule,
Accounting, HCM, Inventory, Legal, Purchase) dengan bergantung pada modul ini:

- Setiap modul menciptakan field "status" dan logika approval if/else miliknya sendiri —
  tidak ada jejak audit bersama, tidak ada cara bersama untuk melihat "apa yang menunggu
  saya" di seluruh ERP, tidak ada perilaku eskalasi yang dapat digunakan ulang saat sesuatu
  mengendap terlalu lama.
- Setiap modul menciptakan caranya sendiri untuk mengirim email/SMS/push — template yang
  tidak konsisten, tidak ada pelacakan pengiriman bersama, tidak ada cara untuk menghormati
  preferensi "jangan SMS saya setelah jam 8 malam" milik user sekali saja, secara terpusat,
  alih-alih per-modul.
- Tidak ada satu tempat untuk menjawab "apa yang menunggak, apa yang menunggu saya, apa yang
  sudah selesai" — ini adalah janji inti dari motif **Status Rail** platform (`DESIGN.md`),
  dan itu hanya berfungsi jika approval/notifikasi setiap modul mengalir melalui satu engine
  dengan satu model state.
- Tidak ada pelacakan proses yang toleran-kesalahan — jika aplikasi restart di tengah rantai
  approval hari ini, tidak ada cara agnostik-modul untuk mengetahui di mana sebuah proses
  sebenarnya berada.
- Tidak ada pemisahan antara "sebuah business event terjadi" dan "siapa yang diberi tahu,
  bagaimana, dan kapan" — setiap modul jika tidak akan meng-hardcode panggilan
  `Mail::send(...)` yang tersebar di seluruh codebase.

**Kebutuhan klien:**
- Harus berfungsi **sepenuhnya berdiri sendiri** — seorang tenant bisa menjalankan WNE tanpa
  apa pun yang lain terinstal, menjalankan rantai approval internal sederhana dan notifikasi,
  karena ini dapat dijual sebagai item lini tersendiri (otomasi proses bisnis generik),
  postur yang sama seperti setiap modul Core lainnya.
- Harus juga menjadi **hal pertama yang dijangkau setiap modul lain** — WNE adalah modul
  Core #1 dalam urutan pembangunan (`CLAUDE.md` §5) persis karena
  Notifications/Workflows mendasari segala hal lainnya; spesifikasi setiap modul berikutnya
  mengasumsikan WNE sudah ada dan menggunakannya ulang alih-alih membangun logika
  approval/notifikasi paralel.
- Sadar multi-tenant, isolasi DB-per-tenant yang sama seperti setiap modul Core lainnya —
  tanpa kolom `tenant_id` (sesuai `CLAUDE.md` §4/§7; modul ini mengikuti aturan itu dengan
  bersih).
- Terpisah (decoupled) dari setiap modul yang menggunakannya: WNE mengekspos sebuah facade
  (`WorkflowService`, `MessagingService`) dan mengonsumsi/menerbitkan event
  (`WorkflowRequested`, `NotificationRequested`, `WorkflowStepCompleted`, ...) — ia tidak
  pernah menjangkau ke dalam schema modul pemanggil, dan modul pemanggil tidak pernah
  menjangkau ke dalam internal WNE di luar facade.
- Definisi proses harus dapat diubah oleh non-developer (admin/analis bisnis) tanpa deploy
  kode — inilah keseluruhan tujuan sebuah workflow engine "low-code" untuk solo dev yang
  tidak bisa membangun ulang rantai approval setiap tenant secara manual.
- Pengiriman notifikasi harus bertahan menghadapi lonjakan traffic dan gangguan provider
  tanpa diam-diam kehilangan pesan — retry, penanganan dead-letter, dan pelacakan pengiriman
  bukan opsional untuk produk yang dipasarkan atas dasar "trust, precision" (`DESIGN.md`).
- User harus bisa mengontrol bagaimana mereka diberi tahu (channel, quiet hours, opt-out
  kategori) — baik ekspektasi UX maupun, untuk channel seperti SMS/email, ekspektasi
  kepatuhan (compliance).

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. **MVP-first** — modul ini menghambat pembangunan setiap modul lain,
> jadi kecepatan menuju inti yang benar dan dapat digunakan ulang lebih penting daripada
> mengirimkan setiap kapabilitas lanjutan sejak hari pertama. Item kompleks/lanjutan secara
> eksplisit didorong ke Future Version di bawah, sesuai brief.

## Termasuk lingkup v1 (MVP — implementasi cepat)

**Workflow Engine**
- **Definisi workflow terstruktur** — step, transisi, dan kondisi dimodelkan sebagai data
  terstruktur (baris step/transition berbasis JSON), disusun melalui **builder berbasis
  form** (tambah step → set type → set condition → hubungkan ke step berikutnya) dengan
  **preview visual read-only** dari alur yang dihasilkan (dirender sebagai diagram
  node/panah sederhana). Ini mendapatkan *nilai* "memodelkan proses tanpa menulis kode" ke
  dalam v1 dengan cepat; editor canvas drag-and-drop sungguhan adalah Future Version (lihat
  di bawah) — schema-nya dirancang sehingga canvas hanyalah UI baru di atas tabel
  `wrkflow_steps`/`wrkflow_transitions` yang sama, bukan rework.
- **Manajemen & persistensi state** — setiap instance workflow dan setiap step di dalamnya
  adalah baris DB yang tahan-lama (durable) dengan status eksplisit (`pending`/
  `in_progress`/`completed`/`failed`/`skipped`/`cancelled`). Tidak ada apa pun tentang "di
  mana sebuah proses berada" yang hanya hidup di memori atau job queue — restart server
  tidak pernah kehilangan state proses, karena job queue hanya pernah "resume dari state
  yang dipersistensikan," tidak pernah "menahan state."
- **Routing & branching dinamis (lingkup v1)** — step sekuensial, cabang kondisional (if/then
  pada sebuah field dari payload pemicu), dan step paralel sederhana (fan-out ke N step,
  join saat all/any selesai). **Penugasan** task dinamis mendukung: user spesifik, sebuah
  role, sebuah tim, atau "owner record tersebut" (diresolusi dari payload) — mencakup
  sebagian besar rantai approval nyata tanpa DSL mesin-aturan-bisnis penuh.
- **Kontrol versi** — sebuah definisi punya state draft/published/unpublished; publishing
  membuat baris versi yang immutable. Sebuah instance yang sedang berjalan selalu selesai
  pada versi yang dimulainya (`wrkflow_instances.definition_version_id` tetap saat mulai) —
  tidak pernah di-upgrade di tengah jalan, persis sesuai kebutuhan.
- **SLA & eskalasi (lingkup v1)** — timer durasi per step (`sla_hours`); saat breach, engine
  menugaskan ulang/menambah target eskalasi (user/role berbeda) dan memicu event
  `NotificationRequested` — tidak ada jalur kode eskalasi terpisah, ini adalah mekanisme
  notifikasi yang sama seperti hal lainnya di modul ini.
- **Ekstensibilitas API & webhook (lingkup v1)** — outbound: step mana pun bisa memicu
  webhook (URL + template payload) via job terantre dengan retry (menggunakan ulang
  mekanisme retry/backoff yang sama seperti pengiriman Notification, §3M, alih-alih
  implementasi kedua). Inbound: sebuah step bisa `waiting_for_callback` — engine
  menerbitkan token/URL callback bertanda tangan; ketika sistem eksternal melakukan POST
  kembali ke situ, step instance yang cocok resume. Inilah seam yang memungkinkan payment
  gateway masa depan, penyedia e-signature, atau API pemerintah (misalnya pelacakan
  Coretax/BPN milik Legal) meresumekan workflow tanpa WNE mengetahui apa pun tentang
  integrasi itu.
- **My Approvals / Task Inbox** — antrean bersama "apa yang menunggu saya" yang sudah
  diasumsikan ada oleh dashboard setiap modul lain (CRM, HCM, Accounting, Purchase, Sales,
  Legal semuanya mereferensikan pola ini) — diresolusi via penugasan langsung **dan**
  keanggotaan role/tim.

**Notification Module**
- **Dukungan multi-channel (lingkup v1)** — `ChannelDriverInterface` yang dapat
  di-plug (`send($message): DeliveryResult`), dengan driver nyata v1 untuk **Email**
  (SMTP/SendGrid) dan **In-App** (berbasis DB + push WebSocket via broadcasting yang
  kompatibel Laravel Reverb/Pusher untuk badge/toast real-time). **SMS (Twilio)** dan
  **Push (FCM/APNs)** dikirim dengan interface driver yang sama dengan implementasi v1 yang
  berfungsi di balik flag enable per-tenant — tenant yang belum butuh SMS/Push hanya
  sekadar tidak mengonfigurasi credential; tidak ada build terpisah nanti, hanya konfigurasi.
- **Pusat Preferensi User (v1, inti bagi kepatuhan — tidak dipangkas)** — per user: channel
  yang dipilih per kategori notifikasi, quiet hours (start/end, timezone tenant), dan toggle
  opt-out keras per kategori (kategori yang kritis-keamanan, misalnya "reset password," bisa
  ditandai tidak-bisa-opt-out oleh definisi kategori itu sendiri).
- **Antrean pesan & pemrosesan async (v1 = Laravel queue berbasis Redis)** — setiap
  notifikasi dikirim sebagai job terantre ke queue `notifications` khusus (sudah menjadi
  queue bersama yang direferensikan spesifikasi setiap modul lain), memisahkan event yang
  memicu notifikasi dari panggilan provider yang sebenarnya. Ini secara sengaja **bukan**
  Kafka/RabbitMQ untuk v1 — queue Redis sudah ada di stack (`CLAUDE.md` §3) dan dengan
  nyaman menangani volume notifikasi SaaS DB-per-tenant-per-tenant tunggal; message broker
  sungguhan adalah ekstraksi Future Version (lihat di bawah), bukan kebutuhan hari pertama.
- **Manajemen template dinamis** — sebuah template per (kategori × channel × locale), dengan
  sintaks placeholder `{{variable}}` diresolusi terhadap payload event pemicu saat waktu
  kirim. Tersentralisasi sehingga "apa isi email reminder kami" adalah satu tempat untuk
  diedit, tidak tersebar di seluruh kode modul.
- **Mekanisme retry & Dead Letter Queue** — backoff eksponensial (misalnya
  1m/5m/30m/2j, maksimum attempt yang dapat dikonfigurasi per channel) pada kegagalan
  provider; setelah mencapai maksimum attempt, pesan pindah ke tabel `msg_dead_letters`
  alih-alih diam-diam dijatuhkan, terlihat di dashboard WNE untuk tinjauan/kirim-ulang
  manual.
- **Observabilitas & pelacakan (lingkup v1)** — siklus hidup setiap notifikasi dicatat
  sebagai event diskret (`created → queued → sent → delivered/failed/bounced`) di tabel
  append-only `msg_delivery_events`, dengan provider message ID ditangkap untuk korelasi.
  Ingesti read-receipt dan bounce-webhook (callback status SendGrid/Twilio) dipasang di v1
  untuk provider yang mendukungnya — ini murah begitu tabel event ada dan merupakan fitur
  trust/marketability sungguhan ("apakah client benar-benar menerima reminder ini").

## Future Version (secara eksplisit ditunda — jangan dibangun sekarang)

- **Desainer canvas visual drag-and-drop sungguhan** — editor node-graph sungguhan (gaya
  react-flow) di atas `wrkflow_steps`/`wrkflow_transitions`. Builder berbasis form v1 +
  preview read-only mencakup kebutuhan sebenarnya (mendefinisikan proses tanpa kode) dengan
  sebagian kecil biaya pembangunan; canvas adalah upgrade UI murni nanti, aditif terhadap
  schema yang sama.
- **DSL aturan-bisnis penuh** untuk kondisi branching (v1 mendukung perbandingan field
  sederhana — equals/not-equals/greater-than/contains terhadap payload pemicu — yang
  mencakup sebagian besar logika approval nyata; sebuah rules engine/expression sungguhan
  ditunda sampai kebutuhan nyata tenant menjustifikasinya).
- **Migrasi message broker (Kafka/RabbitMQ)** — ekstraksi terjustifikasi sesuai
  `CLAUDE.md` §2 hanya begitu volume notifikasi benar-benar melampaui queue berbasis Redis
  di banyak tenant sekaligus (profil scaling yang berbeda, kemungkinan armada worker
  berdiri sendiri) — bukan concern hari pertama untuk produk yang diluncurkan solo dev.
- **Engine batching & digest** — mengelompokkan notifikasi prioritas-rendah menjadi digest
  harian/mingguan. Flag `msg_notifications.priority` dan `msg_categories.digestible`
  ditangkap dalam schema v1 sehingga ini adalah scheduled job aditif nanti, bukan perubahan
  schema; v1 mengirim setiap notifikasi begitu terpicu (dapat diterima pada volume
  peluncuran, dan lebih sederhana untuk dipahami dan didemokan).
- **Analitik SLA lanjutan** (heatmap bottleneck, laporan rata-rata waktu-per-step lintas
  definisi workflow) — cocok alami untuk modul Performance/BI masa depan atau pola
  "ask your data" **AIInsights Core** begitu ada data historis nyata untuk dianalisis.
- **Failover multi-region/multi-provider** untuk channel notifikasi (misalnya auto-fallback
  dari SendGrid ke SES saat kegagalan berkelanjutan) — v1 adalah satu-provider-per-channel
  per tenant, dapat dikonfigurasi tapi tidak otomatis di-failover.
- **Mode simulasi/dry-run workflow** — menguji sebuah definisi terhadap data sampel sebelum
  publish, tanpa membuat instance sungguhan. Berguna, tidak menghambat untuk v1.
- **Fitur push notification kaya penuh** (deep link, tombol aksi, pengelompokan/threading
  notifikasi on-device) — push v1 adalah kirim title/body/data-payload biasa.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis,
> desain DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Kesehatan workflow: instance aktif berdasarkan definisi, instance yang breach SLA, task
  menunggu approval (saya / tim saya), instance yang baru-baru ini selesai/gagal.
- Kesehatan notifikasi: pesan terkirim hari ini, rate kegagalan pengiriman, item di DLQ yang
  butuh perhatian, jumlah yang ditunda quiet-hours.
- Antrean "pekerjaan saya" — pola bersatu yang sama yang diasumsikan ada oleh dashboard
  setiap modul hilir (semua "pekerjaan saya" CRM, "Pending Approvals" HCM, "My Approvals"
  Accounting, dll. diresolusi melalui engine yang sama ini).

**Layout**
- Atas: kartu ringkasan — Active Instances, My Pending Tasks, SLA Breaches (24j),
  Notifications Sent Today, DLQ Items.
- Utama: tabel bertab — "My Tasks" | "Active Instances" | "SLA Breaches" | "DLQ / Failed
  Deliveries".
- Setiap baris menggunakan **Status Rail** bersama (per `DESIGN.md`): `danger` =
  SLA-breached / failed / DLQ, `warning` = due soon, `success` = completed/delivered,
  `info` = dihasilkan sistem (auto-escalation, auto-retry), netral = item in-progress
  normal.

**Aturan / logika**
- Terlingkup-tenant secara otomatis (batas DB-per-tenant, tanpa filter tingkat-aplikasi
  yang dibutuhkan — WNE mengikuti aturan yang sama seperti setiap modul Core lainnya).
- Breach SLA dan pengiriman gagal muncul lebih dulu terlepas dari urutan — konvensi
  "breach-muncul-lebih-dulu" yang sama yang diwarisi CRM/Legal/Accounting dari modul ini.

## 3B. Workflow Definition Builder (Entry)

**Tujuan:** menyusun sebuah proses tanpa menulis kode — v1 berbasis form, schema
siap-canvas.

- Header: `code` (unik per tenant, dipakai modul pemanggil misalnya `workflow_code =
  hcm.leave_approval`), name, description, category (lookup bebas), status
  (`draft`/`published`/`unpublished`).
- **Step list builder**: menambah step secara berurutan; setiap step punya `type`
  (`approval` / `task` / `condition` / `parallel_split` / `parallel_join` / `webhook_call` /
  `wait_for_callback` / `notify`), payload config (JSON — aturan assignee untuk
  `approval`/`task`, ekspresi kondisi untuk `condition`, template URL/payload untuk
  `webhook_call`, referensi template untuk `notify`), dan posisi (`x`,`y` — tidak dipakai
  UI form v1 tapi ditangkap sekarang sehingga canvas Future Version tidak perlu migration).
- **Transition list**: `from_step_id` → `to_step_id`, `condition_expression` opsional
  (dievaluasi terhadap payload instance; transisi default/tanpa-kondisi adalah jalur
  "else" fallback).
- **Panel preview**: rendering node/panah read-only dari step + transition yang baru saja
  didefinisikan — memberikan nilai "lihat proses Anda" dari sebuah desainer visual tanpa
  membangun editor lengkap.
- **Aksi Publish**: memvalidasi graph (setiap step dapat dijangkau, setiap
  `parallel_split` punya `parallel_join` yang cocok, tidak ada step yatim), membuat
  snapshot step/transition saat ini ke dalam baris `wrkflow_versions` yang immutable,
  membalik status menjadi `published`. Versi yang sebelumnya published bisa di-
  `unpublished` (memblokir instance baru mulai di atasnya) tanpa memengaruhi instance yang
  sudah berjalan di atasnya.

**Aturan / logika**
- Mengedit definisi yang `published` selalu mengedit **draft baru**, tidak pernah versi
  published di tempat — publishing lagi membuat versi N+1. Inilah yang menjamin instance
  yang sedang berjalan tidak pernah melihat definisi yang berubah di tengah jalan
  (kebutuhan version-pinning).
- Modul pemanggil mereferensikan definisi berdasarkan `code`, bukan berdasarkan ID/versi —
  `WorkflowService` meresolusi "versi published saat ini untuk code ini" pada saat instance
  dimulai, tepat sekali, lalu terpaku padanya.

## 3C. Workflow Instance Engine (Manajemen & Persistensi State)

**Tujuan:** inti eksekusi yang tahan-lama — setiap engine lain di modul ini (routing, SLA,
webhook) beroperasi pada state yang dipersistensikan engine ini.

- `WorkflowService::start(code, subjectType, subjectId, payload): instanceId` —
  meresolusi versi published, membuat baris `wrkflow_instances` (`status = running`) dan
  baris `wrkflow_instance_steps` untuk step masuk (`status = pending`).
- Eksekusi setiap step adalah transaksi diskret yang dipersistensikan: tandai
  `in_progress` → lakukan aksi step (buat task, evaluasi kondisi, picu webhook, kirim
  notifikasi) → tandai `completed`/`failed` → maju ke step berikutnya sesuai aturan
  transisi. **Tidak ada step yang pernah menahan state hanya di memori job queue** — worker
  queue yang crash di tengah step hanya berarti baris DB step tersebut masih
  `in_progress`/`pending` dan sweep pemulihan (scheduled job yang mencari step
  `in_progress` yang macet melewati grace period) bisa dengan aman menggerakkannya ulang,
  karena setiap aksi dirancang idempoten (diperiksa via idempotency key tingkat-step).
- Instance selesai (`status = completed`) saat setiap step yang dapat dijangkau mencapai
  state terminal; gagal (`status = failed`) jika sebuah step gagal tanpa transisi-kegagalan
  yang didefinisikan; bisa `cancelled` secara manual oleh user yang berwenang (dicatat,
  tidak pernah diam-diam).

**Aturan / logika**
- `wrkflow_instances.subject_type`/`subject_id` adalah seam polimorfik yang sama yang
  dipakai link opsional setiap modul lain (sebuah akta Legal, permintaan cuti HCM, PO
  Purchase) — WNE tidak pernah butuh foreign key ke schema modul pemanggil.
- Menerbitkan event `WorkflowInstanceStarted`, `WorkflowStepCompleted`,
  `WorkflowInstanceCompleted`, `WorkflowInstanceFailed` — modul pemanggil bisa subscribe
  (misalnya HCM memperbarui `leave_requests.status` saat approval) tanpa WNE mengetahui apa
  pun tentang HCM.

## 3D. Routing & Branching Engine

**Tujuan:** memutuskan apa yang terjadi selanjutnya — bagian yang mengubah daftar step
menjadi graph proses sungguhan.

- **Sekuensial**: default — satu transisi keluar, tanpa kondisi.
- **Cabang kondisional**: sebuah step dengan beberapa transisi keluar, masing-masing dengan
  `condition_expression` dievaluasi terhadap payload instance (perbandingan field: `=`,
  `!=`, `>`, `<`, `in`, `contains`); transisi pertama yang cocok menang, dengan transisi
  default/"else" wajib sehingga sebuah cabang tidak pernah diam-diam buntu.
- **Parallel split/join**: `parallel_split` membuat N `wrkflow_instance_steps` konkuren
  (satu per transisi keluar); step `parallel_join` pasangannya hanya maju begitu aturan
  join yang dikonfigurasi (`all` / `any`) terpenuhi di antara step yang mengalir ke
  dalamnya.
- **Penugasan dinamis**: assignee sebuah step `approval`/`task` diresolusi saat runtime
  dari salah satu: user tetap, sebuah role, sebuah tim, atau sebuah field payload
  (misalnya "owner record tersebut" — `payload.owner_id`) — mencakup pola penugasan
  langsung dan resolusi tim/role yang sudah diandalkan dashboard "pekerjaan saya" setiap
  modul hilir (logika resolusi yang sama yang dideskripsikan CRM/HCM/Accounting/Purchase
  sebagai "penugasan langsung dan keanggotaan tim/role").

**Aturan / logika**
- Ekspresi kondisi disimpan dan dievaluasi server-side terhadap **snapshot payload
  instance yang diambil saat mulai** (bukan re-query langsung data terkini modul
  pemanggil) — menjaga branching tetap deterministik dan menghindari keputusan sebuah
  workflow diam-diam berubah karena data sumber diedit di tengah jalan.

## 3E. Version Control Engine

- Sudah dibahas secara fungsional di 3B (definition builder adalah tempat authoring
  terjadi); engine ini adalah lapisan penegakan: `WorkflowService::resolvePublishedVersion(code)`
  selalu mengembalikan satu-satunya versi yang saat ini `published`, dan
  `wrkflow_instances.definition_version_id` immutable begitu diset.
- Meng-unpublish sebuah versi memblokir panggilan `start()` baru terhadap `code` tersebut
  (modul pemanggil sebaiknya memperlakukan ini sebagai "workflow belum dikonfigurasi" dan
  menampilkan error yang jelas menghadap admin) tapi tidak pernah menyentuh instance yang
  sudah berjalan di atasnya.
- Riwayat versi lengkap dipertahankan (tidak pernah dihapus) untuk audit — filosofi
  non-destruktif yang sama yang diterapkan setiap modul lain di platform ini pada record
  historisnya sendiri (versi DMS, log merge CRM, amandemen akta Legal).

## 3F. SLA & Escalation Engine

- `wrkflow_sla_rules`: per step (atau default per definisi), `sla_hours`, aksi eskalasi
  (`reassign_to_role` / `notify_manager_of_assignee` / `notify_role`), target eskalasi.
- Sebuah scheduled job (setiap beberapa menit) memindai `wrkflow_instance_steps` di mana
  `status = pending` atau `in_progress` melewati `due_at` (dihitung saat step-start dari
  `sla_hours`); saat breach: mencatat baris `wrkflow_escalation_log`, menerapkan aksi
  eskalasi (penugasan ulang dan/atau assignee tambahan), dan memicu event
  `NotificationRequested` melalui engine Notification modul yang sama ini — **tidak ada
  mekanisme alerting terpisah**, eskalasi hanyalah pemicu notifikasi lainnya.
- Step yang breach muncul dengan Status Rail `danger` di Dashboard (3A) dan di Task Inbox
  (3H) assignee terlepas dari urutan, sesuai konvensi "breach muncul lebih dulu" yang sudah
  ditetapkan di seluruh platform.

**Aturan / logika**
- Eskalasi bersifat aditif secara default (menambah assignee target-eskalasi di samping
  yang asli) alih-alih diam-diam menugaskan ulang menjauh dari owner asli, kecuali aturan
  secara eksplisit dikonfigurasi sebagai `reassign` — menghindari task yang diam-diam
  menghilang dari antrean seseorang tanpa mereka tahu alasannya.

## 3G. API & Webhook Extensibility Engine

**Outbound**
- Sebuah tipe step `webhook_call`: URL, HTTP method, template payload (variabel
  diresolusi dari payload instance), dan config auth header opsional (disimpan
  terenkripsi). Dikirim sebagai job terantre — menggunakan ulang **mekanisme retry/backoff
  + DLQ yang sama** seperti pengiriman Notification (§3M), alih-alih implementasi retry
  kedua yang hidup di workflow engine.

**Inbound (pause-and-resume)**
- Sebuah tipe step `wait_for_callback`: engine menghasilkan token callback bertanda
  tangan, sekali-pakai, dan mengekspos `POST /api/wne/callbacks/{token}`. Baris
  `wrkflow_instance_steps` milik step tersebut pindah ke `status = waiting_external`; saat
  callback tiba (atau timeout yang dikonfigurasi berlalu, diperlakukan sebagai
  kegagalan/eskalasi per 3F), step resume dan instance maju.
- Inilah seam konkret yang sudah diasumsikan ada oleh spesifikasi modul lain: konfirmasi
  payment gateway masa depan, penyelesaian e-signature, atau pemeriksaan portal
  pemerintah (tax clearance / pelacakan BPN milik Legal, dilacak manual hari ini sesuai
  `LEGAL_SPECS.md` §5, bisa nanti menggunakan mekanisme persis ini jika API pernah
  tersedia) — WNE tidak tahu atau peduli apa yang ada di ujung lain callback tersebut.

**Aturan / logika**
- Token callback sekali-pakai dan kedaluwarsa — callback yang di-replay atau terlambat
  setelah kedaluwarsa dicatat dan ditolak, tidak diam-diam diterapkan pada step yang sudah
  maju.

## 3H. My Approvals / Task Inbox

**Tujuan:** permukaan antrean bersama yang sudah diasumsikan bisa dibaca oleh dashboard
setiap modul lain — dibangun sekali di sini, tidak sekali per modul.

- List view: task yang ditugaskan kepada saya (resolusi langsung + role/tim, per 3D),
  dapat difilter berdasarkan modul sumber (via `subject_type`), tanggal jatuh tempo,
  priority (diwarisi dari kedekatan SLA).
- Detail task: apa yang diminta (dari config step — misalnya "Approve leave request untuk
  [employee]"), link ke record sumber (diresolusi oleh frontend modul pemanggil sendiri,
  karena WNE hanya menyimpan pointer polimorfik), dan aksi yang tersedia (`approve` /
  `reject` / set keputusan kustom yang didefinisikan pada step).
- Mengambil sebuah aksi memanggil `WorkflowService::completeTask(taskId, decision, comment)`,
  yang menandai baris `wrkflow_instance_steps` `completed`, mencatat keputusan, dan
  memajukan instance sesuai aturan routing 3D.

**Aturan / logika**
- Setiap keputusan dicatat dengan actor, timestamp, dan komentar opsional — memberi makan
  jejak audit (3-Audit) dan itulah yang membuat sebuah rantai approval dapat dipertahankan
  secara hukum/prosedural, bukan sekadar flip status.

---

## 3I. Multi-Channel Delivery Engine (Notification Module)

**Tujuan:** mengirim sebuah pesan melalui channel apa pun yang berlaku, tanpa pemanggil
(step workflow atau panggilan modul langsung) mengetahui apa pun tentang detail
SMTP/Twilio/FCM.

- `ChannelDriverInterface`: `send(NotificationMessage $message): DeliveryResult` — pola
  driver-aditif yang sama yang sudah ditetapkan `OcrDriverInterface` milik DMS,
  `ConferenceDriverInterface` milik Schedule, dan `CostingStrategyInterface` milik
  Inventory; channel baru adalah class baru yang didaftarkan di driver map, tidak pernah
  perubahan engine inti.
- Driver v1, implementasi nyata: `EmailDriver` (SMTP/SendGrid), `InAppDriver` (menulis ke
  `msg_notifications` + broadcast lewat WebSocket untuk badge/toast in-app live).
- Driver v1, berfungsi tapi opt-in-tenant (digerbang credential): `SmsDriver` (Twilio),
  `PushDriver` (FCM untuk Android/web push, APNs untuk iOS) — interface dan plumbing
  pengiriman/retry/pelacakan identik dengan Email/In-App; tenant hanya sekadar tidak
  melihat opsi SMS/Push di Preference Center (3J) sampai mereka mengonfigurasi credential
  provider.
- Satu notifikasi logis (header `msg_notifications`) bisa fan-out ke beberapa channel
  sesuai preferensi yang diresolusi penerima (3J) — setiap attempt channel adalah baris
  `msg_notification_deliveries`-nya sendiri, dilacak secara independen (sehingga bounce
  email tidak menyembunyikan pengiriman in-app yang berhasil).

**Aturan / logika**
- Engine tidak pernah memanggil provider secara sinkron dari request pemicu — setiap kirim
  adalah `MessagingService::notify(...)` → event → job terantre → driver. Inilah yang
  menjaga lonjakan notifikasi tidak pernah memblokir modul yang memicunya (sebuah run
  payroll HCM massal yang selesai seharusnya tidak menunggu 200 email terkirim satu per
  satu).

## 3J. Pusat Preferensi User

- Per user, per baris `msg_categories`: channel yang dipilih (multi-select — user bisa
  menginginkan email dan in-app untuk "leave approved," hanya in-app untuk "comment
  mentioned you"), toggle opt-out (diblokir sepenuhnya jika flag `is_mandatory` kategori
  tersebut false — kategori mandatory seperti alert keamanan/reset password tidak bisa
  di-opt-out, ditegakkan di level definisi kategori, bukan sekadar konvensi UI).
- **Quiet hours**: `start_time`/`end_time` di timezone tenant milik user, diterapkan
  per-channel (seorang user mungkin mengizinkan in-app kapan saja tapi membisukan
  push/SMS di malam hari). Sebuah notifikasi yang dihasilkan selama quiet hours untuk
  kategori non-urgent ditunda ke akhir jendela quiet-hours (tidak dijatuhkan) —
  kategori urgent/keamanan melewati quiet hours sepenuhnya, flag bergaya
  `is_mandatory` yang sama pada kategori.
- Layar self-service, komponen library bersama yang sama seperti setiap form bergaya
  pengaturan lainnya di platform ini (`DESIGN.md`).

**Aturan / logika**
- Jika seorang user belum mengatur preferensi eksplisit untuk sebuah kategori,
  `default_channels` milik kategori itu sendiri berlaku — setiap kategori dikirim dengan
  default yang masuk akal sehingga v1 tidak mewajibkan setiap user mengonfigurasi
  preferensi sebelum apa pun bisa dikirim.

## 3K. Message Queue & Async Processing

- **Implementasi v1**: Laravel queue di atas Redis (sudah disediakan sesuai
  `CLAUDE.md` §3), sebuah queue `notifications` khusus yang terpisah dari queue umum
  aplikasi sehingga lonjakan notifikasi tidak bisa membuat job background lain kelaparan
  (dan sebaliknya) — queue bersama yang sama yang sudah diasumsikan ada dan digunakan
  ulang alih-alih dibangun sendiri oleh spesifikasi setiap modul lain (retensi DMS, SLA
  CRM, approval HCM, reminder Accounting, ...).
- Alur event → job: sebuah business event (`NotificationRequested`, atau step workflow
  bertipe `notify`) diterjemahkan menjadi satu baris `msg_notifications` + N baris
  `msg_notification_deliveries` (satu per channel yang diresolusi) + N dispatch
  `SendNotificationJob` terantre — queue secara ketat adalah titik serah-terima async
  antara "sebuah notifikasi perlu terjadi" dan "panggilan provider benar-benar terjadi,"
  tidak pernah tempat di mana state hanya ada secara transien.
- Job yang gagal dicoba ulang oleh queue worker (tingkat framework) hingga kebijakan retry
  engine notifikasi sendiri (3M), lalu mendarat di DLQ alih-alih tabel failed-jobs generik
  milik framework — menjaga penanganan kegagalan notifikasi di satu tempat yang terlihat
  dan menghadap-bisnis (Dashboard WNE), tidak terkubur di tooling infrastruktur yang hanya
  akan diperiksa developer.

## 3L. Manajemen Template Dinamis

- `msg_templates`: `category_id`, channel, locale, subject (judul email/push), body
  (HTML untuk email, teks biasa untuk SMS/push/in-app), daftar variabel (didokumentasikan
  per template untuk admin yang menyusunnya — misalnya `{{employee_name}}`,
  `{{due_date}}`, `{{link}}`).
- Resolusi variabel terjadi saat waktu kirim terhadap payload pemicu (payload yang sama
  yang dibawa instance workflow, atau payload yang dilewatkan langsung oleh modul yang
  memanggil `MessagingService::notify(...)` di luar sebuah workflow) — variabel yang hilang
  dirender sebagai placeholder yang jelas-kosong dalam mode debug/preview, tidak pernah
  kosong diam-diam dalam kiriman produksi (divalidasi sebelum sebuah template bisa
  ditandai aktif).
- Template CRUD adalah form sederhana (per inventaris komponen `DESIGN.md`) dengan panel
  preview live yang menampilkan output tersubstitusi-data-sampel.

**Aturan / logika**
- Sebuah kategori bisa punya template per channel per locale, tapi hanya perlu
  channel/locale yang benar-benar dipakai tenant — kombinasi template yang hilang gagal
  keras saat waktu konfigurasi (peringatan yang jelas menghadap admin), tidak diam-diam
  saat waktu kirim.

## 3M. Mekanisme Retry & Dead Letter Queue

- Kebijakan retry per-channel (`msg_channel_configs`): maksimum attempt, jadwal backoff
  (misalnya 1 mnt → 5 mnt → 30 mnt → 2 jam, dapat dikonfigurasi, eksponensial secara
  default).
- `msg_notification_deliveries.status` maju `queued → sending → sent → delivered` (jalur
  bahagia) atau `queued → sending → failed → retrying → failed → ... → dead_lettered`
  (jalur kegagalan) — setiap transisi adalah baris di `msg_delivery_events` (3O), sehingga
  riwayat retry yang tepat dari pesan mana pun sepenuhnya dapat direkonstruksi.
- Saat mencapai maksimum attempt, delivery pindah ke `msg_dead_letters` (pesan lengkap +
  riwayat kegagalan dipertahankan) dan muncul di tab DLQ Dashboard (3A) — seorang admin
  bisa memeriksa alasan kegagalan dan secara manual **resend** (mengantre ulang dengan
  counter attempt segar) atau **discard** (aksi eksplisit, dicatat — tidak pernah
  dijatuhkan diam-diam).
- Mekanisme retry/backoff yang persis sama digunakan ulang oleh engine Webhook (3G
  outbound) — satu implementasi retry di seluruh modul, bukan dua.

## 3N. Batching and Digesting — **Future Version**

- Flag `msg_categories.digestible` dan `msg_notifications.priority` ada di schema v1
  (nullable/default-false) sehingga ini adalah scheduled job aditif murni nanti:
  mengelompokkan notifikasi digestible seorang user sejak digest terakhir mereka, merender
  satu template digest, kirim sekali per interval yang dikonfigurasi (harian/mingguan).
  Tidak ada breaking change pada tabel mana pun yang sudah ada.

## 3O. Observability & Tracking Engine

- `msg_delivery_events`: append-only, satu baris per event siklus hidup
  (`created`/`queued`/`sending`/`sent`/`delivered`/`opened`/`bounced`/`failed`/`retrying`/
  `dead_lettered`) per baris `msg_notification_deliveries`, dengan `occurred_at` dan
  `provider_payload` mentah (JSON) untuk detail apa pun yang dikembalikan provider (alasan
  bounce, message ID, dll.).
- **Webhook status provider** (v1, untuk provider yang mendukungnya — event
  delivery/bounce SendGrid, callback delivery-status Twilio) diingest ke tabel
  `msg_delivery_events` yang sama via endpoint webhook inbound kecil per provider, menjaga
  "apakah ini benar-benar sampai" bisa dijawab dari data, bukan asumsi.
- Ditampilkan di Dashboard (3A) sebagai ringkasan rate-pengiriman dan rate-kegagalan, dan
  per-pesan di drawer detail notifikasi sebagai timeline sederhana (pola komponen bergaya
  Comment/Activity Thread yang sama yang dipakai di tempat lain di platform).

**Aturan / logika**
- Tidak ada update/delete yang diizinkan pada `msg_delivery_events` di lapisan aplikasi —
  aturan integritas-audit yang sama yang diterapkan DMS pada `access_logs`, diterapkan di
  sini pada riwayat pengiriman pesan alih-alih riwayat akses dokumen.

---

# 4. Penyimpanan

**Database (schema `WNE`, DB tenant — konsisten dengan `CLAUDE.md` §7A; tanpa kolom
`tenant_id`, DB-per-tenant adalah batas isolasi, sesuai DMS/CRM/SCHEDULE dan setiap modul
Core lainnya):**

**Tabel master / lookup**
- `WNE.wrkflow_categories` — lookup pengelompokan opsional untuk definisi.
- `WNE.msg_categories` — dapat diedit tenant (security, reminder, marketing, ...),
  `is_mandatory` (flag diblokir-opt-out), `digestible` (flag Future Version),
  `default_channels`.
- `WNE.channel_types` — lookup: `email` / `sms` / `push` / `in_app` / `webhook`.
- `WNE.msg_channel_configs` — per tenant × channel, credential provider (terenkripsi saat
  disimpan), kebijakan retry (maksimum attempt, jadwal backoff), flag enabled.
- `WNE.msg_templates` — `category_id`, channel, locale, subject, body, daftar variabel.

**Tabel transaksi workflow** (prefix `wrkflow_`)
- `WNE.wrkflow_definitions` — header: code (unik per tenant), name, category, status
  saat ini.
- `WNE.wrkflow_versions` — snapshot immutable per-publish: `definition_id`, nomor versi,
  published_at, published_by.
- `WNE.wrkflow_steps` — `version_id`, type, config (JSON), posisi (`x`,`y`, untuk canvas
  masa depan).
- `WNE.wrkflow_transitions` — `from_step_id`, `to_step_id`, `condition_expression`
  (nullable).
- `WNE.wrkflow_sla_rules` — `step_id` (atau `version_id` untuk default definisi),
  `sla_hours`, aksi eskalasi, target eskalasi.
- `WNE.wrkflow_instances` — `definition_version_id` (tetap saat mulai), `subject_type`/
  `subject_id`, status, snapshot payload (JSON), started_at, ended_at.
- `WNE.wrkflow_instance_steps` — `instance_id`, `step_id`, status,
  `assigned_to`/`assigned_role`, `due_at`, started_at, completed_at, decision, comment.
- `WNE.wrkflow_tasks` — baris view "pekerjaan saya" yang didenormalisasi (atau query view)
  di atas `wrkflow_instance_steps` untuk query inbox yang cepat.
- `WNE.wrkflow_escalation_log` — append-only, `instance_step_id`, aturan yang diterapkan,
  escalated_to, escalated_at.
- `WNE.wrkflow_webhooks` — subscription/config webhook outbound yang direferensikan step
  `webhook_call`.
- `WNE.wrkflow_callbacks` — `instance_step_id`, token bertanda tangan, expires_at,
  consumed_at.
- `WNE.wrkflow_audit_logs` — append-only, satu baris per event instance/step/decision,
  actor, timestamp — pola immutable yang sama seperti `dms.access_logs`.

**Tabel transaksi notifikasi** (prefix `msg_`)
- `WNE.msg_user_preferences` — `user_id`, `category_id`, channel yang dipilih
  (array/JSON), opted_out (bool, diblokir jika kategori `is_mandatory`), quiet_hours_start,
  quiet_hours_end.
- `WNE.msg_notifications` — header: `category_id`, `subject_type`/`subject_id` (link
  sumber polimorfik opsional), penerima, payload (JSON), priority, created_at.
- `WNE.msg_notification_deliveries` — `notification_id`, channel, status,
  provider_message_id, attempt_count, next_retry_at, sent_at, delivered_at, error_detail.
- `WNE.msg_delivery_events` — log siklus hidup append-only per delivery (3O), tanpa
  update/delete.
- `WNE.msg_dead_letters` — delivery yang exhausted, pesan lengkap + riwayat kegagalan, log
  aksi resend/discard.
- `WNE.msg_digests` — **Future Version**, distub kosong saat peluncuran (pelacakan
  antrean/batch digest per-user).

**Penyimpanan file objek:** tidak ada yang dimiliki langsung WNE. Jika sebuah step workflow
atau notifikasi pernah butuh melampirkan file (misalnya sebuah keputusan approval dengan
dokumen pendukung), ia melampirkan via `DocumentService::attach()` milik **DMS** dengan
`subject_type = 'wne.wrkflow_instances'` dll., disiplin penggunaan-ulang yang sama seperti
setiap modul lain — WNE tidak mengimplementasikan penyimpanan file paralel.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, pertama dari empat modul fondasional dalam urutan
pembangunan (`CLAUDE.md` §5), di `app/Modules/WNE/` — bentuk yang sama seperti setiap modul
Core berikutnya (`Controllers/`, `Models/`, `Requests/`, `Services/`, `Data/`, `Enums/`,
`Routes/`). Tidak ada ekstraksi microservice pada MVP: eksekusi workflow dan dispatch
notifikasi adalah CRUD + orkestrasi job terantre, bukan beban kerja runtime-berbeda atau
scaling-independen sesuai kriteria ekstraksi `CLAUDE.md` §2. Dua bagian yang ditandai
sebagai **ekstraksi masa depan yang terjustifikasi**, sesuai aturan yang sama, adalah
(a) message broker sungguhan (Kafka/RabbitMQ) jika volume notifikasi pernah melampaui
queue berbasis Redis di seluruh basis tenant, dan (b) armada notification-worker khusus
jika volume panggilan provider butuh scaling independen dari app utama — tidak satu pun
menjadi concern hari pertama.

- **Facade internal** (disukai, same-process) — `WorkflowService::start(...)`,
  `::completeTask(...)`, `::cancel(...)`; `MessagingService::notify(...)`,
  `::getPreferences(...)`, `::updatePreferences(...)` — titik integrasi yang dipanggil
  setiap modul Core/Vertical lain, persis seperti yang sudah diasumsikan setiap
  spesifikasi modul berikutnya.
- **Event bus internal** — menerbitkan `WorkflowInstanceStarted`, `WorkflowStepCompleted`,
  `WorkflowInstanceCompleted`, `WorkflowInstanceFailed`, `NotificationSent`,
  `NotificationFailed`, `NotificationDeadLettered`; mengonsumsi `WorkflowRequested` dan
  `NotificationRequested` dari modul pemanggil mana pun — inilah seam persis yang
  direferensikan setiap spesifikasi berikutnya (retensi DMS §3F, SLA CRM §3E, approval
  cuti HCM §3F, approval posting Accounting §3C, exception Purchase §3K, approval quote
  Sales §3E, deed signing Legal §3C) sebagai "fires into WNE" — event-event itu adalah
  kontrak pemanggilan yang harus dihormati secara presisi oleh modul ini.
- **Cross-schema read, tidak pernah write**: WNE meresolusi user/role/tim dari tabel auth
  platform dan, jika relevan, `CRM.partners` (misalnya memberi tahu titik kontak seorang
  partner) — selalu sebuah read via facade modul pemilik, tidak pernah FK cross-schema
  langsung ke modul Vertical (WNE adalah Core; Core tidak pernah bergantung pada Vertical,
  dan di sini secara khusus WNE tidak boleh bahkan mengasumsikan modul Core lain mana yang
  terinstal — lihat catatan feature-flag di bawah).
- **Independensi feature-flag**: WNE tidak boleh mengasumsikan modul lain mana pun
  terinstal — ia adalah modul *pertama* yang mungkin dimiliki sebuah tenant, tanpa apa pun
  yang lain hadir. Setiap titik integrasi (resolusi partner CRM, attachment DMS) bersifat
  opsional dan dijaga (guarded), mencerminkan postur "tidak boleh throw jika X tidak ada"
  yang secara eksplisit diadopsi setiap spesifikasi modul berikutnya *terhadap WNE* — WNE
  sendiri harus mengadopsi postur yang sama terhadap segala hal lainnya, karena tidak ada
  yang lain dijamin ada lebih dulu.

**Idempotensi & toleransi-kesalahan (keputusan desain inti untuk Workflow Engine):**
setiap eksekusi step dan setiap pengiriman notifikasi dirancang untuk aman dipicu ulang.
Sebuah step membawa idempotency key yang diturunkan dari
`(instance_id, step_id, attempt)`; sebuah job queue yang berjalan ulang setelah crash
memeriksa state yang dipersistensikan lebih dulu dan no-op jika step sudah `completed`.
Inilah yang memenuhi kebutuhan "eksekusi toleran-kesalahan jika sistem restart" tanpa
membutuhkan framework durable-execution yang lebih eksotis — state DB-persisted biasa +
handler job idempoten sudah cukup pada skala ini dan menjaga seluruh engine tetap dapat
dipahami oleh satu solo dev.

**Kenapa queue Redis, bukan Kafka/RabbitMQ, untuk v1:** Redis sudah disediakan di stack
(`CLAUDE.md` §3) untuk penggunaan cache dan queue. Queue `notifications` khusus di
instance Redis yang sudah ada dengan nyaman menangani volume SaaS DB-per-tenant-per-tenant
tunggal yang realistis (traffic notifikasi setiap tenant secara alami terisolasi oleh
database, sehingga tidak ada masalah noisy-neighbor lintas-tenant yang perlu diselesaikan
dengan broker yang lebih berat). Memperkenalkan Kafka/RabbitMQ sekarang akan menjadi persis
jenis ekstraksi "clean architecture" prematur yang secara eksplisit diperingatkan
`CLAUDE.md` §2 — biaya operasional nyata (layanan baru untuk dijalankan, dipantau, dan
dipahami) tanpa justifikasi saat ini. Tinjau kembali hanya jika volume tenant tertentu atau
kebutuhan nyata akan durabilitas/replay lintas-layanan menuntutnya.

**Detail implementasi version pinning:** `wrkflow_instances.definition_version_id` diset
sekali, saat `start()`, dengan meresolusi `wrkflow_definitions` → baris `wrkflow_versions`
yang saat ini `published`-nya, dan tidak pernah diresolusi ulang selama masa hidup instance
tersebut — bahkan jika definisi di-republish (versi baru) sementara instance masih
berjalan. Ini adalah satu kolom `NOT NULL`, immutable-setelah-insert; menegakkannya adalah
disiplin lapisan-service (tidak pernah ada statement update yang menyentuh kolom ini),
bukan trigger DB, menjaganya tetap sederhana dan eksplisit untuk future-you yang membaca
ulang kode nanti.

**Interaksi quiet hours + kategori-mandatory:** pemeriksaan Preference Center (3J)
berjalan *sebelum* dispatch channel, bukan sebelum pembuatan notifikasi — sebuah baris
`msg_notifications` selalu dibuat segera (sehingga tidak ada yang hilang/tertunda di
tingkat source-of-truth), tapi baris `msg_notification_deliveries` per-channel untuk
kategori non-mandatory selama quiet hours dibuat dengan `status = deferred` dan
`next_retry_at` diset ke akhir jendela quiet-hours, menggunakan ulang mekanisme
penjadwalan-retry yang sama (3M) alih-alih menciptakan jalur kode deferred-send kedua.

**Batas lingkup MVP (eksplisit, agar tidak ada yang di bawah menghambat ship pertama yang
cepat):**
- Desainer canvas visual, DSL rules-engine penuh, Kafka/RabbitMQ, batching digest, dan
  analitik SLA lanjutan semuanya adalah Future Version (§2) dengan placeholder tingkat-
  schema yang sudah dicadangkan (`digestible`, `priority`, posisi `x`/`y` step) sehingga
  tidak satu pun membutuhkan migration yang breaking nanti — disiplin "hanya migration
  aditif" yang sama yang diterapkan DMS pada `extracted_text`/`pgvector`.
- Driver SMS/Push dikirim sebagai implementasi `ChannelDriverInterface` nyata dan
  berfungsi di v1 (tidak ditunda) persis karena *biaya pola* menambah channel nanti hampir
  nol begitu Email/In-App membuktikan interface tersebut — tapi mereka digerbang
  credential, sehingga tenant yang belum siap membayar Twilio/FCM sekadar tidak pernah
  melihat opsinya diekspos.

**Queue:** Eksekusi step workflow dan dispatch notifikasi keduanya async via queue
`notifications` bersama (berbasis Redis) — queue yang sama yang digunakan ulang secara
eksplisit oleh spesifikasi setiap modul berikutnya (DMS, CRM, HCM, Accounting, Purchase,
Sales), yang hanya mungkin karena WNE menetapkannya lebih dulu, di sini, sebagai satu queue
berdekatan-notifikasi milik platform.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3C (workflow instance engine —
persistensi state adalah fondasi yang menjadi tempat bergantung semua hal lain, termasuk
3D/3F/3G) → 3B (definition builder, berbasis form + preview) → 3D (routing/branching,
dikawatkan ke 3C) → 3E (version pinning — sebagian besar logika penegakan begitu 3B/3C
ada) → 3H (My Approvals inbox, murah begitu 3C ada, nilai tinggi — inilah yang akan
ditautkan dashboard setiap modul lain) → 3I (delivery multi-channel — driver Email +
In-App dulu) → 3L (template, dibutuhkan sebelum konten notifikasi nyata mana pun bisa
dirender) → 3K (pengkawatan queue) → 3J (preference center) → 3M (retry + DLQ) → 3O
(observability/tracking) → 3F (SLA & escalation, sekarang task workflow dan notifikasi
sama-sama ada untuk dieskalasikan) → 3G (ekstensibilitas webhook, menggunakan ulang
mekanisme retry 3M) → **ship — inilah titik di mana pembangunan setiap modul Core lain
bisa dimulai terhadap WNE yang nyata** → tinjau kembali item Future Version (desainer
canvas, batching digest, migrasi broker) begitu ada penggunaan multi-tenant nyata untuk
menjustifikasinya.

**Catatan kelayakan jual (marketability)**
- WNE adalah infrastruktur, bukan layar demo dengan sendirinya — kelayakan jualnya tidak
  langsung: ia adalah yang membuat janji "Status Rail," "My Approvals," dan "Anda akan
  diberi tahu" milik setiap modul lain benar-benar nyata. Percakapan penjualan sebaiknya
  memimpin dengan demo modul *hilir*, bukan WNE itu sendiri, tapi kebenaran WNE-lah yang
  menjaga demo itu kredibel.
- Kelayakan jual berdiri-sendiri (otomasi rantai-approval generik + notifikasi, tanpa modul
  lain yang dibutuhkan) membuka motion go-to-market kedua yang ringan — sebuah bisnis
  kecil yang menginginkan "hanya" approval/reminder otomatis tanpa membeli vertikal ERP
  lengkap, tuas validasi berbiaya-rendah yang sama yang sudah dipakai untuk cerita
  berdiri-sendiri DMS/Schedule/Inventory.
- Observabilitas pengiriman (read receipt, pelacakan bounce, riwayat retry lengkap)
  adalah poin kepercayaan "apakah ini benar-benar berhasil" yang konkret untuk audiens
  pembeli legal yang konservatif — layak dimunculkan dalam demo ("ini buktinya client
  Anda menerima reminder"), postur yang sama yang sudah dipakai jejak audit DMS dan
  buku protokol Legal sebagai poin jual.
