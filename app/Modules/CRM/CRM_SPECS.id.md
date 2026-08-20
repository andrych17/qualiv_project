# Modul CRM
## Modul Bersama Inti — Registry Partner, Leads, After Sales Service, Helpdesk

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal (Legal hari ini; Property, dan lainnya nanti) bertransaksi dengan orang dan
organisasi — klien, tenant, vendor, mitra referral, pengacara lawan, penyedia layanan.
Jika dibiarkan diselesaikan oleh masing-masing modul vertikal, "siapa orang ini" akan
diselesaikan secara independen:

- Setiap vertikal menyimpan record kontak versinya sendiri — tabel "Client" milik Legal
  terlihat sama sekali berbeda dari tabel "Tenant" milik Property, padahal keduanya hanya
  sekadar nama, alamat, nomor telepon, dan sekumpulan dokumen/riwayat.
- Orang atau perusahaan nyata yang sama berakhir terduplikasi di berbagai modul tanpa cara
  untuk melihat bahwa itu adalah entitas yang sama — tidak ada tampilan 360°, tidak ada satu
  tempat untuk memperbarui nomor telepon, tidak ada cara untuk menandai "vendor ini juga
  klien" atau "lead ini menjadi klien enam bulan lalu."
- Manajemen hubungan pasca-transaksi (permintaan dukungan, tindak lanjut layanan, pertanyaan
  umum) tidak punya tempat untuk berada — bukan Sales Order, bukan Legal Case, tapi tetap
  merupakan pekerjaan yang terkait dengan seorang partner yang perlu dilacak, ditugaskan, dan
  diselesaikan.
- Penangkapan lead (minat pra-partner) juga tidak punya rumah, sehingga aktivitas
  penjualan/intake sebelum ada transaksi nyata akan hilang atau ditempel begitu saja pada
  vertikal mana pun yang lebih dulu sampai ke sana.

**Kebutuhan klien:**
- Satu registry Partner terpadu, dapat digunakan ulang oleh setiap vertikal — Sales, Legal,
  Property, dan apa pun yang datang berikutnya — tanpa vertikal mana pun memiliki atau
  menduplikasi data kontak.
- Membedakan **orang perorangan** dari **organisasi**, dan merepresentasikan bahwa seseorang
  dapat bekerja untuk / mewakili sebuah organisasi.
- Merepresentasikan bahwa satu partner dapat memegang **beberapa peran sekaligus** (sebuah
  perusahaan bisa menjadi Vendor sekaligus Client), dan kosakata peran harus
  **dapat dikonfigurasi per-tenant** — sebuah firma hukum menyebutnya "Client," pengelola
  properti menyebutnya "Tenant" atau "Owner" — tanpa migration inti per vertikal.
- Menangkap **Leads** sebelum mereka menjadi partner sungguhan, dengan pipeline kualifikasi,
  dan mengonversi lead yang sudah terkualifikasi menjadi Partner tanpa memasukkan ulang data.
- Melacak kasus **After Sales Service** dan tiket **Helpdesk** terhadap seorang partner,
  opsional ditautkan kembali ke record vertikal mana pun yang memicunya (sebuah Sales Order,
  sebuah Legal Case), tanpa CRM pernah bergantung pada modul-modul itu.
- Mendukung **deduplikasi/merge** — kualitas data CRM terus-menerus menurun (typo, entri
  duplikat lintas kanal intake); harus ada cara aman untuk menggabungkan dua record partner
  tanpa kehilangan riwayat.
- Sadar multi-tenant, sama seperti setiap modul Core lainnya: terlingkup-tenant, dan terbuka
  terhadap custom field per-tenant (via schema `CUSTOMFIELDS`) tanpa migration inti.
- Terpisah (decoupled) dari setiap vertikal: CRM menerbitkan event (`PartnerCreated`,
  `LeadConverted`, `TicketCreated`, ...) dan mengekspos sebuah facade; ia tidak pernah
  menjangkau ke dalam tabel Legal/Sales/Property, dan mereka hanya pernah menjangkau *ke
  dalam* CRM (tidak pernah sebaliknya — Core tidak punya pengetahuan tentang Vertical, sesuai
  konvensi proyek).

# 2. Tujuan (Goals)

> Fitur yang ditetapkan yang menyelesaikan Latar Belakang di atas.

- **Registry Partner terpadu.** Satu tabel `partners` merepresentasikan baik **Companies**
  (organisasi) maupun **Contacts** (individu), dengan link `parent_partner_id` sehingga
  sebuah Contact bisa "dipekerjakan oleh / mewakili" sebuah Company. Disajikan sebagai dua
  Form yang berbeda (3B, 3C) untuk UX yang bersih, didukung oleh satu schema — tidak ada
  field terduplikasi, satu tempat untuk dedup, satu jejak audit.
- **Sistem Role yang dapat dikonfigurasi per-tenant.** Role (Customer, Vendor, Client,
  Employee, Referral, Other, ...) berada di tabel lookup yang dapat diedit tenant, ditugaskan
  many-to-many ke partner. Inilah yang memungkinkan CRM inti yang *sama* dijual dengan
  kosakata berbeda per vertikal tanpa menyentuh kode.
- **Pipeline Lead.** Menangkap minat inbound (source, owner, stage), mengkualifikasi, dan
  konversi satu-klik ke Partner (+ Role awal) — tanpa mengetik ulang, tanpa data lead yang
  terlantar.
- **After Sales Service.** Pelacakan case untuk pekerjaan layanan pasca-transaksi, opsional
  menunjuk kembali ke record vertikal asalnya via referensi longgar `subject_type` /
  `subject_id` (mencerminkan pola yang dipakai WNE) — CRM tidak pernah foreign-key ke dalam
  schema vertikal.
- **Helpdesk.** Ticketing tujuan umum (dukungan, pertanyaan, keluhan) — tidak harus terkait
  transaksi sebelumnya. Berbagi kategori tiket, prioritas, dan mesin SLA dengan After Sales
  Service, tapi tetap sebagai engine-nya sendiri karena siklus hidup dan audiensnya (partner
  mana pun, bahkan sebuah lead) berbeda.
- **Alat Deduplikasi / Merge.** Mendeteksi partner yang kemungkinan duplikat (kemiripan
  nama/email/telepon) dan merge dengan aman, dengan log audit yang reversibel.
- **Dukungan multi-alamat / multi-titik-kontak.** Partner dapat membawa lebih dari satu alamat
  (billing, shipping, kantor) dan lebih dari satu telepon/email, masing-masing ditandai
  dengan tipe dan flag primary.
- **Integrasi WNE.** Menerbitkan event yang bisa disubscribe modul lain — termasuk WNE
  sendiri: notifikasi penugasan lead, eskalasi SLA-breach tiket via Workflow, notifikasi
  status tiket via Messaging. CRM sendiri tidak mengimplementasikan pengiriman notifikasi;
  ia menerbitkan event dan membiarkan aturan routing WNE yang memutuskan apa yang dipicu.
- **Custom field.** Setiap entitas di sini (`partners`, `leads`, `hd_tickets`, `svc_cases`)
  dapat diperluas per tenant via `CUSTOMFIELDS`, sehingga "field ekstra" khusus-vertikal yang
  diinginkan tenant tidak membutuhkan migration inti.

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis,
> desain DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Gambaran sekilas kesehatan CRM: total partner aktif berdasarkan role, lead terbuka
  berdasarkan stage, tiket terbuka berdasarkan status SLA (on-track / due soon / breached),
  kasus After Sales terbuka.
- Permukaan "pekerjaan saya": lead yang ditugaskan kepada saya, tiket yang ditugaskan kepada
  saya, case yang ditugaskan kepada saya — terlepas dari engine mana asalnya, disatukan dalam
  satu antrean.
- Aksi cepat dari dashboard: menugaskan, mengubah stage/status, membuka record lengkap.

**Layout**
- Atas: 4 kartu ringkasan — Open Leads, Open Tickets, Open Service Cases, Partners Added
  (30d).
- Utama: tabel bertab — "My Leads" | "My Tickets" | "My Service Cases" | "Recent Partners".
- Setiap baris menggunakan **Status Rail** bersama (per DESIGN.md) diwarnai berdasarkan
  state — ini adalah motif visual yang sama yang dipakai di Scheduler/Workflows/Notifications,
  sehingga CRM terasa sebagai bagian dari satu platform.
- Klik baris membuka drawer dengan record lengkap + timeline aktivitas.

**Aturan / logika**
- Semua query terlingkup-tenant secara otomatis (global tenant scope pada setiap tabel CRM).
- "Pekerjaan saya" diresolusi via penugasan langsung **dan** keanggotaan tim/role, pola
  resolusi yang sama seperti "My Approvals" milik WNE.
- Tiket/case yang SLA-breach muncul lebih dulu terlepas dari urutan, dengan flag visual yang
  persisten.

## 3B. Contacts

**Tujuan:** mengelola orang perorangan — "siapa," baik terkait dengan sebuah Company atau
tidak.

- Field: nama, field khusus-individu (title/posisi jika dipekerjakan oleh sebuah Company),
  alamat primary, titik kontak primary (email/telepon), `parent_partner_id` (nullable —
  Company mana, jika ada, yang mereka wakili), role (banyak, via `partner_roles`), tag,
  owner (user internal yang bertanggung jawab atas hubungan tersebut), source (bagaimana
  mereka masuk ke CRM — manual, konversi lead, import).
- List view: tabel data yang dapat difilter/diurutkan (komponen bersama), Status Rail
  mencerminkan "active/inactive" atau warna berbasis-role jika hanya ada satu role.
- Detail view: tab — Overview, Addresses, Contact Points, Roles & Tags, Related Leads,
  Related Tickets/Cases, Activity Timeline, Custom Fields.
- Contact tanpa `parent_partner_id` adalah individu berdiri sendiri (misalnya vendor
  praktisi tunggal, atau klien Legal perorangan) — Company tidak wajib ada.

**Aturan / logika**
- Menghapus/menonaktifkan Contact tidak pernah mengalir ke modul vertikal — ia hanya
  menandai partner sebagai inactive; apakah vertikal masih menampilkan record historis yang
  mereferensikannya adalah keputusan vertikal itu sendiri.
- Mengubah `parent_partner_id` dicatat (siapa memindahkan contact ini ke company lain,
  kapan).

## 3C. Companies

**Tujuan:** mengelola organisasi — payung yang bisa diikuti sebuah Contact.

- Tabel `partners` yang sama dengan Contacts, difilter ke `type = organization`. Field: nama
  legal, nama dagang, ID registrasi/pajak, industri, alamat primary, role, tag, owner.
- Detail view menambahkan tab **Contacts**: setiap Contact yang `parent_partner_id`-nya
  menunjuk ke sini, dengan flag primary/decision-maker.
- Tab Related Leads / Related Tickets/Cases / Activity Timeline / Custom Fields yang sama
  dengan Contacts, karena keduanya adalah Partner di baliknya.

**Aturan / logika**
- Sebuah Company sendiri bisa punya `parent_partner_id` (anak perusahaan dari Company lain)
  — mekanisme self-referencing yang sama, digunakan ulang alih-alih menambah konsep
  hubungan kedua.
- Menggabungkan dua Company memindahkan kembali (re-parent) semua Contact mereka ke record
  yang bertahan (lihat 3G).

## 3D. Leads

**Tujuan:** melacak minat pra-partner melalui pipeline kualifikasi; konversi ke Partner
sungguhan begitu terkualifikasi — tanpa memasukkan ulang data.

- **Record lead:** nama/company (teks bebas hingga konversi), source (lookup
  `lead_sources` — referral, web, event, cold outreach, ...), stage (New → Contacted →
  Qualified → Converted / Disqualified), owner, estimated value (opsional, bebas format —
  tanpa asumsi logika mata uang/ukuran deal karena itu milik Sales, bukan CRM), tanggal
  next action, notes.
- **Board view:** Kanban berdasarkan stage (drag untuk memajukan), menggunakan motif Status
  Rail berdasarkan warna stage. **List view:** tabel yang dapat diurutkan, komponen bersama
  yang sama seperti di tempat lain.
- **Konversi:** satu aksi — "Convert to Partner" — membuat (atau menautkan ke, jika
  ditemukan kecocokan dedupe) sebuah record `partners`, menugaskan Role awal yang dipilih,
  menyalin notes lead ke Activity Timeline partner baru, dan menandai lead `Converted`
  dengan link ke partner hasilnya. Inilah titik di mana sebuah Lead berhenti menjadi
  masalah CRM dan menjadi Partner yang bisa ditransaksikan oleh vertikal.
- **Disqualifikasi:** membutuhkan kode alasan (kalah dari kompetitor, tidak ada budget,
  tidak cocok, ...) — memberi makan laporan alasan-kehilangan sederhana, berguna bahkan di
  MVP untuk demo kesehatan pipeline.

**Aturan / logika**
- Sebuah Lead *bukan* Partner dan tidak punya role — ia tidak bisa direferensikan oleh
  transaksi vertikal mana pun. Batas ini yang menjaga "noise pipeline" tetap di luar data
  partner sungguhan.
- Opsional: kualifikasi lead besar/bernilai-tinggi bisa melalui Workflow WNE
  (`WorkflowRequested` dengan `workflow_code = crm.lead_qualification`) jika tenant
  menginginkan persetujuan manager sebelum konversi — CRM sendiri tidak mengimplementasikan
  logika approval, ia hanya memicu WNE seperti modul lain mana pun.

## 3E. After Sales Service

**Tujuan:** melacak pekerjaan layanan yang mengikuti transaksi selesai di modul lain
(sebuah matter Legal yang butuh pengajuan susulan, unit Property yang butuh layanan
pasca-pindah, sales order masa depan yang butuh dukungan garansi) — tanpa CRM mengetahui apa
transaksi itu.

- Field: partner (wajib), subject (deskripsi singkat), category (lookup
  `ticket_categories`, dibagi dengan Helpdesk), priority, status (Open → In Progress →
  Waiting on Partner → Resolved → Closed), agen/tim yang ditugaskan, tanggal jatuh tempo SLA,
  `subject_type` + `subject_id` (nullable — misalnya `subject_type = 'legal.case'`,
  `subject_id = 4821`; murni informasional, **bukan** foreign key, karena CRM tidak bisa
  menjangkau ke schema modul lain).
- Detail view: header case + log aktivitas berulir (notes, perubahan status, attachment),
  komponen Comment/Activity Thread yang sama yang dipakai Workflows dan notes case Legal
  per DESIGN.md.
- List view: dapat difilter berdasarkan status/priority/status SLA/agen yang ditugaskan,
  Status Rail diwarnai berdasarkan status SLA (on-track/due-soon/breached) menggunakan
  warna semantik yang sama seperti di tempat lain.

**Aturan / logika**
- SLA breach memicu event internal `ServiceCaseSLABreached` — WNE Messaging mengambilnya
  sesuai aturan routing tenant (misalnya notifikasi supervisor via email + in-app), pola
  decoupled yang sama seperti setiap integrasi modul-ke-WNE lainnya.
- Menutup case bersifat final untuk tujuan pelaporan tapi bisa dibuka kembali dalam jendela
  grace yang dapat dikonfigurasi (default 7 hari) — menutup karena kesalahan seharusnya
  tidak membutuhkan pembuatan case duplikat.

## 3F. Helpdesk

**Tujuan:** ticketing tujuan umum — permintaan dukungan, pertanyaan, keluhan — untuk partner
mana pun (termasuk contact tahap-Lead, sebelum konversi), tidak harus terkait transaksi
sebelumnya.

- Field: requester (Partner, atau teks bebas jika pra-CRM/penelepon tidak dikenal), subject,
  category (lookup `ticket_categories` bersama dengan After Sales), priority, status, agen/tim
  yang ditugaskan, channel asal (email/telepon/web-form/in-app), tanggal jatuh tempo SLA.
- Pesan berulir (`hd_ticket_messages`) — bolak-balik yang sebenarnya, berbeda dari log
  aktivitas internal After Sales karena Helpdesk mengutamakan percakapan (lebih mirip
  email/chat).
- List/detail view mencerminkan After Sales Service (komponen bersama yang sama), tapi
  dipertahankan sebagai engine terpisah karena: (a) sebuah tiket bisa ada tanpa Partner
  yang diketahui, (b) sifat percakapan/berulirnya berbeda dari log kerja internal sebuah
  case, (c) memungkinkan tenant melisensikan Helpdesk secara terpisah dari After Sales
  Service sebagai add-on berbeda jika berguna secara komersial.

**Aturan / logika**
- Jika requester sebuah tiket kemudian teridentifikasi sebagai (atau dikonversi dari)
  Lead/Contact, tiket bisa ditautkan ulang ke Partner hasilnya tanpa kehilangan thread
  pesan.
- Pola SLA-breach → event WNE yang sama seperti After Sales Service.

## 3G. Partner Merge / Deduplikasi

**Tujuan:** menjaga registry tetap bersih tanpa pernah kehilangan data secara diam-diam.

- **Deteksi:** tampilan laporan/background memunculkan partner yang kemungkinan duplikat
  berdasarkan kemiripan nama/email/telepon — antrean tinjauan, bukan merge otomatis.
- **Aksi merge:** admin memilih record "yang bertahan"; alat ini memindahkan kembali
  (re-parent) semua yang mereferensikan record yang kalah (role, contact di bawah Company
  yang di-merge, lead, tiket, case, entri activity timeline) ke record yang bertahan, dan
  menulis entri `partner_merge_log` yang mencatat persis apa yang di-merge dan konflik
  tingkat-field mana pun (sehingga reversibel dalam semangatnya meski tidak secara literal
  one-click undo).
- Modul vertikal yang FK ke `partner_id` yang kini di-merge **tidak** disentuh langsung oleh
  CRM (CRM tidak punya akses ke schema mereka) — sebaliknya CRM menyimpan baris partner lama
  sebagai tombstone (`merged_into_partner_id` diset, `is_active = false`) sehingga FK apa pun
  yang sudah ada di Sales/Legal/Property tetap resolve, alih-alih merusak integritas
  referensial lintas modul.

**Aturan / logika**
- Merge hanya untuk admin, terlingkup-tenant, dan selalu dicatat — ini adalah operasi
  sensitif-kepercayaan untuk audiens pembeli legal (DESIGN.md: "trust, precision" adalah
  keseluruhan brief-nya), sehingga merge yang diam-diam atau ireversibel secara eksplisit
  dihindari.

---

# 4. Penyimpanan

> Tabel dan penyimpanan objek yang dipakai modul ini. Schema: `CRM` (per DB tenant, sesuai
> §7 CLAUDE.md). Penamaan: tabel master satu kata; tabel transaksi/log diberi prefix domain
> (`lead_*`, `svc_*`, `hd_*`), sesuai konvensi yang dipakai di `WNE_SPECS.md`.

**Tabel master / lookup**
- `CRM.partners` — record Company + Contact terpadu. Field kunci: `type`
  (individual/organization), `parent_partner_id` (self-referencing, nullable), field nama,
  `is_active`, `merged_into_partner_id` (nullable, tombstone untuk 3G), `uuid` (referensi
  yang menghadap eksternal untuk klien REST di masa depan).
- `CRM.partner_role_types` — lookup yang dapat diedit tenant (Customer, Vendor, Client,
  Employee, Referral, Other, ...).
- `CRM.addresses` — `partner_id`, type (billing/shipping/office/other), field alamat
  lengkap, `is_primary`.
- `CRM.contact_points` — `partner_id`, type (email/phone/mobile/fax), value, `is_primary`,
  `opt_out` (menghormati "jangan hubungi via X," dikonsumsi oleh aturan routing WNE).
- `CRM.industries` — lookup untuk Company (klasifikasi opsional).
- `CRM.lead_sources` — lookup (referral, web, event, cold outreach, ...).
- `CRM.ticket_categories` — lookup bersama, dipakai baik oleh After Sales Service maupun
  Helpdesk.

**Tabel transaksi / log**
- `CRM.partner_roles` — `partner_id`, `role_type_id`, `assigned_at`, `assigned_by`,
  `is_active`. (many-to-many, dengan riwayat)
- `CRM.partner_relationships` — `partner_id`, `related_partner_id`, `relationship_type`
  (works_at / subsidiary_of / referred_by / other) — menggeneralisasi afiliasi di luar kolom
  `parent_partner_id` sederhana, untuk kasus yang bukan hierarki ketat.
- `CRM.leads` — header: nama/company (teks bebas), `source_id`, `stage`, `owner_id`,
  `estimated_value`, `next_action_at`, `converted_partner_id` (nullable, diset saat
  konversi), `disqualify_reason`.
- `CRM.lead_activities` — `lead_id`, tipe aktivitas (call/email/meeting/note), body,
  `logged_by`, `logged_at`.
- `CRM.svc_cases` — header: `partner_id`, subject, `category_id`, priority, status,
  `assigned_to`, `sla_due_at`, `subject_type`, `subject_id` (keduanya nullable, hanya
  informasional).
- `CRM.svc_case_activities` — `case_id`, tipe aktivitas (note/status_change/attachment),
  body, `logged_by`, `logged_at`.
- `CRM.hd_tickets` — header: `partner_id` (nullable jika requester tidak teridentifikasi),
  subject, `category_id`, priority, status, `assigned_to`, `sla_due_at`, channel asal.
- `CRM.hd_ticket_messages` — `ticket_id`, direction (inbound/outbound/internal-note), body,
  `sender_id` atau sender teks bebas, `sent_at`.
- `CRM.partner_merge_log` — `merged_from_partner_id`, `merged_into_partner_id`,
  `merged_by`, `merged_at`, `field_conflicts` (snapshot JSON dari apa yang berbeda antara
  kedua record).

**Penyimpanan file objek** (per §7B — path bucket `tenant_{n}/CRM/`)
- Attachment tiket/case (pesan Helpdesk, aktivitas case After Sales) disimpan di bawah
  `tenant_{n}/CRM/tickets/{ticket_id}/` dan `tenant_{n}/CRM/cases/{case_id}/`, konvensi
  penamaan konsisten dengan modul lain untuk kemampuan-restore per tenant.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core, postur monolitik-modular yang sama seperti WNE. Mengekspos:
- **Facade/service internal** — `PartnerService::findOrCreate(...)`,
  `PartnerService::assignRole(...)`, `LeadService::convert(...)`,
  `ServiceCaseService::open(...)`, `HelpdeskService::open(...)` — titik integrasi yang
  disukai untuk modul Core lain (misalnya WNE meresolusi "siapa yang dibicarakan notifikasi
  ini").
- **Event bus internal** — menerbitkan `PartnerCreated`, `PartnerRoleAssigned`,
  `LeadConverted`, `ServiceCaseSLABreached`, `TicketCreated`, `TicketStatusChanged`. Modul
  vertikal subscribe ke event ini; CRM tidak pernah subscribe ke atau memanggil modul
  vertikal. Dependensi satu-arah ini (Vertical → Core, tidak pernah sebaliknya) adalah
  aturan yang sama seperti di tempat lain di codebase ini.
- **Cross-schema FK, bukan pencocokan UUID lintas-tenant.** Karena CRM dan setiap vertikal
  berbagi database tenant yang sama (hanya schema Postgres yang berbeda), sebuah tabel
  vertikal (misalnya `LEGAL.case_hdrs`) bisa FK langsung ke `CRM.partners.id` (bigint) —
  ini aman karena ini adalah Vertical yang bergantung pada Core, arah yang diizinkan. Kolom
  `uuid` pada `partners` ada untuk penggunaan yang menghadap eksternal di masa depan (REST
  API untuk klien mobile sesuai CLAUDE.md §2), bukan untuk join internal.

**Keterkaitan vertikal tanpa coupling:** `svc_cases` dan, opsional, `hd_tickets` membawa
`subject_type` / `subject_id` sebagai kolom informasional biasa (misalnya
`subject_type = 'legal.case_hdrs'`), **bukan** foreign key. Meresolusi record sebenarnya
(untuk menampilkan link "view source") terjadi di lapisan frontend/controller modul mana pun
yang tahu cara mencarinya — pekerjaan CRM berhenti pada penyimpanan pointer. Ini mencerminkan
pola seam yang sama yang dipakai untuk `subject_type`/`subject_id` milik WNE pada workflow
instance.

**Custom field:** `partners`, `leads`, `svc_cases`, `hd_tickets` semuanya didaftarkan sebagai
entitas yang dapat diperluas terhadap schema `CUSTOMFIELDS` (sesuai CLAUDE.md §7A) — sebuah
tenant menambahkan "Bar Number" ke Contacts untuk vertikal Legal, atau "Unit Number" ke
Companies untuk Property, tidak pernah membutuhkan migration CRM.

**Partner type vs. Role — kenapa keduanya konsep berbeda:** `type` (individual/organization)
bersifat struktural dan tetap saat pembuatan (seseorang bukanlah sebuah organisasi). `role`
(Customer/Vendor/Client/...) adalah klasifikasi bisnis, many-to-many, dapat dikonfigurasi
tenant, dan berubah seiring waktu — sebuah Company bisa mendapat role "Vendor" hari ini dan
role "Client" kuartal depan tanpa menjadi jenis record yang berbeda. Menggabungkan keduanya
menjadi satu field adalah kesalahan pemodelan umum yang secara sengaja dihindari desain ini.

**Kenapa Contacts/Companies satu tabel tapi After Sales/Helpdesk dua tabel:** Contacts dan
Companies berbagi setiap field dan terus-menerus saling mereferensikan (employer/employee) —
satu tabel dengan diskriminator type adalah seam yang tepat. After Sales Service dan Helpdesk
hanya berbagi tabel lookup mereka (`ticket_categories`) dan mekanika SLA; siklus hidup, model
threading, dan bahkan cerita lisensinya (tenant mungkin menginginkan Helpdesk tanpa After
Sales) benar-benar berbeda — dua tabel, berbagi komponen di lapisan UI/service alih-alih
lapisan schema, adalah seam yang tepat di sana.

**Queue:** Pemeriksaan SLA-breach untuk `svc_cases`/`hd_tickets` berjalan pada job terjadwal
(misalnya setiap 5–15 menit) alih-alih real-time, menerbitkan event breach ke pola queue yang
berdekatan dengan `notifications` yang sudah dipakai WNE — tidak perlu queue baru, gunakan
ulang milik WNE.

**Ekstensibilitas:** Tipe Role, source Lead, dan kategori Ticket baru semuanya adalah tabel
lookup yang dapat diedit tenant — tidak perlu deploy kode untuk mengganti nama "Client"
menjadi "Tenant" untuk vertikal baru, yang merupakan tuas utama yang membuat modul Core ini
dapat digunakan ulang lintas vertikal alih-alih khusus-Legal.

**Urutan pembangunan yang disarankan untuk Claude Code:** 3B/3C (Contacts/Companies — registry
Partner yang menjadi tempat FK semua yang lain) → 3D (Leads, dikonversi menjadi Partner) →
3E/3F (After Sales Service + Helpdesk, berbagi lookup `ticket_categories` dan mekanika SLA,
membutuhkan Partner sudah ada lebih dulu) → 3G (Partner Merge/Dedup, membutuhkan data partner
nyata untuk beroperasi) → 3A (Main Dashboard, mengagregasi semua di atas) — rilis pada titik
ini.

**Catatan kelayakan jual (marketability)**
- Model Partner terpadu + Role yang dapat dikonfigurasi adalah yang memungkinkan modul CRM
  yang *sama* dijual ulang di bawah kosakata masing-masing vertikal (Client untuk Legal,
  Tenant/Owner untuk Property) — penghemat biaya nyata untuk peluncuran vertikal masa depan,
  bukan sekadar kerapian engineering.
- Tampilan partner 360° (semua role, semua lead, semua tiket, semua case dalam satu record)
  adalah poin demo yang kuat dan pitch "kenapa tidak pakai spreadsheet saja" yang alami untuk
  pembeli legal yang konservatif soal berganti alat.
- Dedup/merge adalah poin jual kualitas-data untuk firma yang bermigrasi dari sistem lama
  yang berantakan — layak dimunculkan secara eksplisit dalam demo penjualan, bukan hanya
  dibangun diam-diam.
- Helpdesk dan After Sales Service sebagai engine terpisah (hanya berbagi lookup/komponen)
  membuka peluang untuk melisensikan mereka sebagai add-on terpisah nanti, tanpa rebuild.

**Catatan bias MVP (Legal paling dekat dengan revenue):** untuk ship pertama, Leads Kanban,
tooling Merge, dan Helpdesk multi-channel (intake telepon/web-form di luar email) bisa
dipangkas — versi minimal yang dapat dijual adalah: Partners (Contacts+Companies) dengan
Roles, alur konversi list-lead sederhana (lewati board Kanban), dan Helpdesk/After Sales
sebagai list tiket datar tanpa otomasi SLA. Semua itu cocok dengan schema di atas tanpa
perubahan — ini adalah pengurangan UI/feature-flag, bukan re-arsitektur, sehingga membangun
versi yang lebih lengkap sekarang tidak menimbulkan biaya migrasi ekstra nanti.
