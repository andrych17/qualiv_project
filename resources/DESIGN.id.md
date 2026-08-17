# DESIGN.md

Referensi sistem desain untuk Claude Code, Claude Design, dan Google Stitch saat membangun UI apa pun di proyek ini. Setiap komponen atau layar baru harus disusun dari sistem ini, bukan diberi gaya secara ad hoc. File ini adalah satu-satunya sumber kebenaran sampai ada paket design-tokens sungguhan di codebase.

## 1. Brief Desain

**Subjek:** ERP SaaS multi-tenant, dijual per-modul ke pasar vertikal. Audiens pertama: **profesional hukum** (pengacara, paralegal, admin firma) yang mengelola case, tenggat waktu, dokumen, dan komunikasi klien.

**Kebutuhan audiens:** kepercayaan, presisi, beban kognitif rendah di bawah tekanan waktu, dan tool yang terasa dibangun *untuk* profesi mereka, bukan dashboard generik yang di-reskin dengan logo mereka. Pembeli di sektor hukum konservatif soal software — UI harus terbaca dapat diandalkan dan matang, bukan sekadar tren.

**Tugas tunggal setiap layar, dinyatakan ulang per konteks:** membantu satu orang menggerakkan satu pekerjaan (sebuah case, task, notifikasi) maju dengan friksi seminim mungkin, selalu menunjukkan *di mana posisi sesuatu berada* — karena ERP hidup dan mati berdasarkan visibilitas status (apa yang overdue, apa yang menunggu saya, apa yang selesai).

Ini menyingkirkan estetika playful/konsumer dan default umum hasil-AI (cream hangat + terracotta; hitam-nyaris-pekat + aksen acid; layout broadsheet dengan garis-tipis). Tak satu pun dari itu cocok untuk produk profesional, padat data, dan mengutamakan kepercayaan.

## 2. Token Desain

### Warna — "Ink & Signal"
Basis nyaris-netral, saturasi rendah (agar data yang padat tidak berbenturan dengan UI) dengan satu aksen percaya diri yang dicadangkan untuk aksi, dan set semantik kecil untuk status — karena komunikasi status adalah tugas inti sebuah ERP.

| Token | Hex | Penggunaan |
|---|---|---|
| `--color-ink-900` | `#12181F` | Teks utama, heading |
| `--color-ink-600` | `#4A5563` | Teks sekunder, label |
| `--color-surface-0` | `#FFFFFF` | Card, panel |
| `--color-surface-50` | `#F4F6F8` | Background aplikasi |
| `--color-border` | `#DEE3E8` | Garis rambut, divider, garis tabel |
| `--color-accent` | `#1F5FBF` | Aksi utama, link, focus ring — biru yang percaya diri dan tidak ambigu; sengaja *bukan* aksen terracotta/violet default-AI |
| `--color-signal-success` | `#1E7F5C` | Selesai, on-track, terselesaikan |
| `--color-signal-warning` | `#B5760A` | Segera jatuh tempo, butuh perhatian |
| `--color-signal-danger` | `#C1332B` | Overdue, terblokir, error |
| `--color-signal-info` | `#5B4FCF` | Notifikasi sistem/otomatis (sengaja dibedakan dari accent agar "aplikasi yang melakukan ini" terbaca berbeda dari "kamu bisa bertindak di sini") |

Dark mode adalah fase berikutnya — nama token terstruktur (skala numerik) agar palet gelap bisa ditambahkan tanpa mengganti nama apa pun.

### Tipografi
- **Display / heading:** *Source Serif 4* — serif membawa kewibawaan yang diharapkan pengguna hukum (kontrak, brief, kop surat) tanpa jatuh ke dekoratif. Dipakai hanya untuk H1/H2 dan angka kunci (statistik dashboard), tidak pernah untuk chrome UI.
- **UI / body:** *Inter* — netral, sangat terbaca di ukuran kecil, kuda beban untuk tabel, form, nav, tombol.
- **Data / mono:** *IBM Plex Mono* — nomor case, timestamp, ID, log audit. Apa pun yang mungkin perlu di-copy-paste atau dipindai pengguna untuk karakter yang persis.

Skala tipe (rem, basis 16px): `12 / 14 / 16 / 18 / 22 / 28 / 36`. Teks UI default 14. Konten body/bacaan default 16. Jangan pernah turun di bawah 12.

### Spacing & Layout
- Unit spacing dasar 8px (`4, 8, 12, 16, 24, 32, 48, 64`).
- Border radius: `4px` (input, tombol, chip kecil), `8px` (card, modal). Tidak ada chrome "pill" bulat penuh kecuali status badge — pill bulat dicadangkan *khusus* untuk status, sehingga bentuknya jadi bermakna, bukan dekoratif.
- App shell standar: sidebar kiri tetap (navigasi modul) + top bar (pengalih tenant/konteks, notifikasi, pencarian) + area konten. Shell ini dipakai bersama oleh setiap modul dan setiap vertical — konsistensi di sini yang membuat produk terasa seperti satu platform, bukan tool-tool yang disambung-sambung.

### Elemen ciri khas: **Status Rail**
Batang warna vertikal tipis (2–3px) di tepi kiri baris/card mana pun yang mewakili item yang bisa dilacak (case, task, scheduled job, langkah workflow), diwarnai sesuai state semantiknya (`success` / `warning` / `danger` / `info` / warna border netral jika tidak ada). Muncul identik di Scheduler, Workflows, Notifications, dan daftar case Legal — inilah satu motif visual yang mengikat setiap modul bersama-sama dan secara langsung mengkodekan nilai inti produk: *selalu tahu di mana posisi sesuatu sekilas pandang*, bisa dipindai ke bawah daftar panjang tanpa membaca teks.

## 3. Inventaris Komponen (bangun dalam urutan ini)

Primitif dasar dulu, baru komposit. Masing-masing harus jadi satu komponen reusable yang dipakai di mana-mana — bukan implementasi ulang per modul.

**Primitif**
- Button (primary / secondary / ghost / destructive; dengan loading state)
- Input, Textarea, Select, Combobox, Date/time picker
- Checkbox, Radio, Toggle
- Status Badge (pill, memakai warna semantik di atas)
- Avatar / chip inisial
- Tooltip, Popover

**Komposit**
- Data table (sortable, filterable, dengan Status Rail per-baris; expansi baris opsional lewat `expandable` + `#row-detail`; Group/Outline gaya Excel opsional lewat `groupBy` pada `Column[]` dengan `footer: 'sum'|'avg'|'count'|'min'|'max'` per-kolom untuk subtotal grup + `<tfoot>` grand-total yang dipin — lihat `resources/js/Components/tables/DataTable.vue`. Dalam mode `groupBy`, `items` selalu hanya halaman ter-paginasi-server saat ini, sehingga grand total mencerminkan halaman itu kecuali host mengirim `footerTotals` yang dihitung backend di seluruh result set.)
- Card (dengan Status Rail opsional di tepinya)
- Kanban / Board view (kolom drag-to-advance berdasarkan status — lihat spesifikasi
  khusus di bawah; dipakai oleh pipeline Lead CRM, pipeline Opportunity Sales, dan board OKR
  Performance)
- Modal / drawer
- Toast / banner notifikasi inline
- Empty state (lihat panduan penulisan di bawah — selalu actionable, tidak pernah sekadar "no data")
- Tabs, breadcrumb, pagination
- Item nav sidebar (dengan ikon modul + hitungan badge opsional)
- Calendar / timeline view (untuk Schedule)
- Thread comment/activity (reusable lintas Workflows dan catatan case Legal)

### Kanban / Board View

Pipeline stage/status yang dirender sebagai kolom (satu per stage) berisi card yang bisa
di-drag — dipakai di mana pun sebuah record bergerak melalui urutan state yang linear atau
nyaris-linear sebelum selesai: pipeline Lead CRM (New → Contacted → Qualified →
Converted/Disqualified), pipeline Opportunity Sales (New → Qualifying → Quoted → Won/Lost), dan
board OKR Performance (berdasarkan status: On Track / At Risk / Off Track / Completed).

- **Header kolom**: nama stage, jumlah card, nilai agregat opsional (mis. total estimasi nilai
  Lead di stage ini) memakai *Source Serif 4*, sesuai konvensi sistem ini yang mencadangkan
  serif untuk angka kunci.
- **Card**: komposit Card yang sama (di atas), dengan **Status Rail**-nya di tepi kiri diwarnai
  sesuai state semantik record itu sendiri — bukan posisi kolomnya. Card yang overdue atau
  at-risk tetap ditandai secara visual (`danger`/`warning`) bahkan sebelum siapa pun men-drag-nya,
  sehingga board tidak pernah menyembunyikan masalah hanya karena berada di kolom mana. Body
  card: judul, avatar/chip inisial pemilik, dan satu-dua field sekunder (mis. estimasi nilai +
  tanggal aksi berikutnya untuk sebuah Lead) — tidak pernah lebih. Card Kanban adalah ringkasan
  yang bisa dipindai, bukan record lengkap.
- **Drag-to-advance**: men-drag card ke kolom baru mengubah field status yang mendasarinya.
  Drop yang akan melanggar aturan bisnis keras (mis. order Sales yang credit-blocked, atau
  disqualifikasi CRM yang butuh kode alasan) membuka form inline terkait, bukan diam-diam
  menyelesaikan perpindahan — drag adalah pintasan untuk transisi yang valid, tidak pernah
  bypass aturan apa pun yang seharusnya menggerbangi perubahan itu.
- **Toggle list view**: setiap board Kanban punya padanan tampilan list/table yang bisa
  di-sort (memakai ulang komposit Data table) untuk pengguna yang lebih suka itu atau berada di
  viewport lebih sempit — Kanban adalah satu tampilan atas record, bukan satu-satunya cara
  berinteraksi dengannya, konsisten dengan persyaratan Quality Floor "responsif turun ke lebar
  mobile yang masih terpakai" (§6).
- **Empty state kolom** mengikuti suara undangan-untuk-bertindak yang sama seperti empty state
  lainnya (§5) — mis. *"Belum ada lead di Qualified — drag satu ke sini atau tambah lead
  baru,"* tidak pernah sekadar "No data."

## 4. Motion

Minimal dan hanya fungsional: transisi state (hover, focus, expand/collapse), toast enter/exit,
dan highlight baris halus saat Status Rail berubah warna secara live (mis. sebuah task jadi
overdue secara real time). Tidak ada koreografi page-load, tidak ada motion dekoratif — ini
adalah tool yang dipakai sepanjang hari; animasi yang menunda pengguna cepat jadi mengganggu.
Hormati `prefers-reduced-motion` di mana saja.

## 5. Penulisan & Voice

- Sasar aksi nyata pengguna, bukan mekanisme sistem: "Assign case," bukan "Update case record."
- Tombol dan konfirmasi hasilnya memakai kosakata yang sama: tombol yang bertuliskan "Send
  reminder" menghasilkan toast bertuliskan "Reminder sent," tidak pernah "Notification
  dispatched."
- Error menyatakan apa yang terjadi dan apa yang harus dilakukan berikutnya, tanpa
  meminta maaf atau samar-samar: "This case number already exists. Use a different number or
  open the existing case."
- Empty state adalah undangan untuk bertindak, disesuaikan per modul: Scheduler kosong
  berbunyi "No events scheduled — add your first one," bukan "No data."
- Nada keseluruhan: sederhana, presisi, tenang. Tidak ada tanda seru, tidak ada keramahan yang
  dipaksakan — ini mencerminkan bagaimana profesional hukum mengharapkan tool mereka
  terdengar.

## 6. Quality Floor (non-negotiable untuk setiap komponen)

- Responsif turun ke lebar mobile yang masih terpakai, meski penggunaan utama di desktop.
- Focus ring keyboard yang terlihat memakai `--color-accent` pada setiap elemen interaktif.
- Kontras cukup di semua ukuran teks (verifikasi terhadap `--color-surface-0` dan
  `--color-surface-50`).
- `prefers-reduced-motion` dihormati.
- Setiap elemen pembawa-status (badge, rail, icon) memasangkan warna dengan label teks atau
  bentuk icon — tidak pernah warna saja — untuk aksesibilitas buta warna.

## 7. Item Terbuka

- [ ] Finalisasi apakah Source Serif 4 / Inter / IBM Plex Mono di-hosting lokal atau dimuat
      lewat font CDN, mengingat setup VPS/Nginx.
- [x] Pilihan icon set (perlu mencakup ikonografi spesifik-hukum: palu hakim, case file,
      tanggal sidang, dll. — kemungkinan set umum + set kustom kecil untuk Legal).
- [ ] Palet dark mode (ditunda sampai komponen light-mode inti stabil).
- [x] Formalkan ini jadi file token sungguhan (CSS variables / Tailwind config) begitu
      scaffolding library komponen Vue.js dimulai. (`resources/css/app.css` +
      `tailwind.config.js`)
