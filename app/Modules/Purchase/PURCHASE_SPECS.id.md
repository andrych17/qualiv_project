# Modul Purchase
## Sistem Purchase & Procurement — Modul Inti Bersama (Core Shared Module) (dapat berdiri sendiri)

# 1. Latar Belakang

> Titik masalah dan nilai bisnis.

Setiap vertikal yang akan pernah dijual oleh platform ini — Legal hari ini (alat tulis kantor,
retainer saksi ahli, vendor pengajuan berkas pengadilan, belanja IT/software), Property besok
(vendor maintenance, kontraktor, utilitas) — pada akhirnya membeli sesuatu dari seseorang. Jika
dibiarkan tidak diselesaikan secara sentral, ini mengulangi anti-pola yang sama yang sudah
dihindari di WNE/DMS/CRM/Schedule:

- Setiap vertikal menciptakan daftar "kita beli dari siapa" sendiri alih-alih menggunakan ulang
  registry Partner terpadu yang sudah dibangun di **CRM** (seorang Vendor hanyalah `partner`
  dengan role `Vendor` — tidak ada alasan untuk menduplikasi konsep ini di sini).
- Tidak ada jejak approval yang konsisten untuk belanja — keputusan pembelian dibuat lewat
  email/WhatsApp, tanpa record audit, tanpa pemeriksaan budget, dan tanpa cara membuktikan
  kepatuhan kepada auditor atau klien vertikal-legal yang peduli soal konflik kepentingan
  vendor.
- Tidak ada visibilitas atas "apa yang sebenarnya kita belanjakan, dengan siapa, dan apakah itu
  berada di bawah kontrak" — belanja bocor lewat maverick buying (pembelian di luar katalog,
  di luar kontrak).
- Tidak ada cara terstruktur untuk membandingkan supplier sebelum berkomitmen — sourcing
  terjadi secara ad hoc, sehingga leverage harga/kualitas hilang.
- Tidak ada tempat bersama untuk pencocokan tiga arah Purchase Order → Goods Receipt → Invoice,
  sehingga kesalahan invoice dan pembayaran duplikat tidak terdeteksi sampai Finance
  menemukannya (atau tidak).
- Tanggal perpanjangan kontrak hidup di kepala seseorang atau di spreadsheet, bukan di sistem
  yang mengingatkan siapa pun — auto-renewal terlewat atau kelalaian kepatuhan tidak disadari.

**Kebutuhan klien:**
- Harus bekerja **standalone** — sebuah tenant bisa menjalankan Purchase tanpa apa pun yang lain
  terpasang (entri vendor manual, PO sederhana, pencocokan receipt/invoice manual) karena ini
  dapat dijual sebagai item lini sendiri, postur yang sama dengan DMS dan Schedule.
- Harus terintegrasi dengan bersih ketika modul Core lain tersedia: **CRM** untuk record
  vendor/partner, **Inventory** (jika terpasang) sehingga Goods Receipt benar-benar memposting
  pergerakan stok fisik dan lapisan biaya — tenant yang menjalankan Purchase tanpa Inventory
  tetap mendapat disiplin procurement penuh (PR → PO → GR → Invoice → match), hanya saja GR
  berfungsi sebagai record penerimaan/pencocokan saja, tanpa efek stok fisik, **WNE** untuk
  setiap langkah approval dan notifikasi, **DMS** untuk penyimpanan/retensi dokumen kontrak,
  **Schedule** untuk pengingat perpanjangan/audit/review dan tenggat RFx.
- Traceability penuh dari Requisition → Sourcing (RFx) → Purchase Order → Goods Receipt →
  Invoice → **pembayaran, dieksekusi oleh Accounting** — jejak audit nyata, bukan sekadar field
  status. Purchase memiliki intake dan pencocokan tiga arah; ia tidak memelihara ledger AP kedua
  atau mengeksekusi disbursement sendiri, sesuai aturan "satu ledger, banyak requester" yang
  sama yang sudah diterapkan `ACCOUNTING_SPECS.md` pada Billing Engine milik Sales.
- Kontrol budget/approval sebelum komitmen, bukan sesudahnya — PO di atas ambang batas tidak
  boleh bisa diterbitkan tanpa sign-off yang tepat, menggunakan engine workflow yang sama
  dipakai setiap modul lain.
- Sourcing strategis (RFI/RFQ/RFP dengan scoring berbobot) untuk buyer yang ingin menjalankan
  proses kompetitif sungguhan, tanpa memaksa setiap tenant memakainya untuk order alat tulis
  $50.
- Self-service supplier (portal) agar vendor mengirim quote, mengonfirmasi order, mem-posting
  data pengiriman dan invoice sendiri — mengurangi beban admin buyer, dan merupakan poin demo
  yang kuat.
- Manajemen hubungan supplier: kedaluwarsa sertifikasi/asuransi, riwayat audit, corrective
  action, scorecard periodik — manajemen risiko procurement, bukan sekadar pemrosesan
  transaksi.
- Visibilitas belanja: kategori (direct/indirect, CAPEX/OPEX), konsentrasi supplier, belanja vs.
  kontrak — pelaporan yang benar-benar diminta stakeholder Finance.
- Flag ESG/kepatuhan (% konten lokal, dokumen keberlanjutan, sertifikasi) — semakin menjadi
  persyaratan kontraktual dari *klien-nya klien* kita, jadi butuh tempat meski logika scoring
  tetap sederhana saat peluncuran.
- Procurement berbantuan AI (forecasting, rekomendasi supplier, deteksi anomali harga, deteksi
  PR duplikat) secara eksplisit diinginkan, tetapi — sesuai desain AIInsights Core milik proyek
  ini — sebaiknya menggunakan ulang infrastruktur "ask your data" bersama itu alih-alih Purchase
  membangun pipeline ML bespoke sendiri.
- Dapat dipakai di mobile untuk dua tugas yang benar-benar dilakukan orang di ponsel: menyetujui
  sesuatu, dan memindai pengiriman di dok/gudang. Aplikasi native offline tidak diperlukan untuk
  peluncuran.

# 2. Tujuan (Goals)

> Fitur yang ditetapkan. MVP-first — luncurkan core procure-to-pay yang benar-benar bisa
> dipakai dengan cepat; tunda mesin sourcing/SRM/AI/ESG berat ke Future Version begitu ada
> penggunaan nyata yang menjustifikasinya. Ini mencerminkan bias MVP yang sudah ditetapkan di
> `DMS_SPECS.md` (OCR/pencarian semantik ditunda) dan `CRM_SPECS.md` (Kanban/merge dipangkas
> untuk peluncuran pertama).

**MVP (luncurkan bersama/tak lama setelah peluncuran vertikal Legal)**
- **Alur inti Requisition → PO.** Purchase Requisition (PR) diajukan oleh user/modul mana pun →
  approval via **WNE** → dikonversi menjadi Purchase Order (PO) → dikirim ke supplier → Goods
  Receipt (memposting pergerakan stok fisik ke **Inventory**, via
  `InventoryService::receive()`, ketika Inventory terpasang untuk tenant — lihat §3E) → Invoice
  → **pencocokan tiga arah** (PO / GR / Invoice, selalu dievaluasi terhadap record penerimaan
  milik Purchase sendiri terlepas dari status instalasi Inventory) dengan toleransi yang dapat
  dikonfigurasi. Pencocokan yang berhasil menyerahkan ke **Accounting** (`BillRequested`) untuk
  pencatatan AP, withholding PPh, dan pembayaran — lihat §3F.
- **Vendor = CRM Partner.** Tidak ada tabel vendor terpisah — Purchase menggunakan ulang
  `CRM.partners` yang difilter ke partner yang memegang role `Vendor`, hanya menambahkan atribut
  spesifik procurement (payment terms, incoterms, status pajak) dalam tabel ekstensi
  `PURCHASE.vendor_profiles` (1:1 dengan `CRM.partners`), pola "extend, don't duplicate" yang
  sama yang sudah ditetapkan CRM untuk hubungan modul lain terhadap registry Partner.
- **RFQ sederhana.** Permintaan quote multi-supplier dengan tanggal jatuh tempo dan tabel
  perbandingan datar (harga, lead time, catatan berdampingan). Ini saja sudah mencakup kebutuhan
  sourcing untuk sebagian besar pembelian tanpa membangun engine RFI/RFP berbobot penuh dulu.
- **Katalog dasar.** Daftar `pur_catalog_items` datar — item, supplier default/preferensi, harga
  yang dinegosiasikan, unit — cukup untuk mempercepat pembelian berulang dan menandai pembelian
  "off-catalog". Belum ada integrasi punch-out.
- **Pemeriksaan budget (lunak).** PR/PO diperiksa terhadap budget periode sederhana per
  kategori/cost center; melebihi budget menandai peringatan dan dirutekan untuk approval
  tambahan via WNE alih-alih hard-block — cukup baik untuk peluncuran tanpa membangun modul
  budgeting penuh.
- **Register kontrak (dasar).** Header kontrak + dokumen (disimpan via **DMS**, pola yang sama
  yang sudah dipakai DMS untuk retensi/kedaluwarsa) + tanggal mulai/selesai + flag auto-renewal.
  Pengingat perpanjangan dipicu lewat **Schedule** (item kalender berulang) → notifikasi
  **WNE** — tidak ada mekanisme pengingat baru yang diciptakan di sini.
- **Alert pengecualian (yang esensial).** Approval yang menunggak, keterlambatan pengiriman (GR
  melewati tanggal ekspektasi PO), variansi harga di luar toleransi, invoice tak tercocokkan —
  semuanya dipicu sebagai event `WorkflowRequested` / `NotificationRequested` ke **WNE**, persis
  seperti penanganan pengecualian setiap modul lain. Kelebihan budget menggunakan ulang
  pemeriksaan lunak yang sama di atas.
- **Dapat dipakai di mobile, bukan mobile-native.** Approval dan Goods Receipt (termasuk
  scan-to-receive barcode/QR via kamera perangkat, dan lampiran foto pada penerimaan) bekerja
  pada UI Vue/Inertia responsif yang sudah ada. Tidak ada mode offline, tidak ada aplikasi
  native khusus di v1.
- **Tampilan belanja dasar.** Belanja per supplier, per kategori (direct/indirect, CAPEX/OPEX —
  klasifikasi sederhana yang dapat diedit tenant pada baris PR/PO, bukan auto-classifier), dan
  belanja-vs-kontrak untuk kontrak yang tercatat. Tabel/chart biasa, belum ada analitik
  prediktif.
- **Pertukaran dokumen supplier (ringan).** Supplier bisa dikirimi tautan unggah aman lewat email
  (tanpa login portal penuh diperlukan untuk v1) untuk mengirim quote, konfirmasi order, atau
  invoice/dokumen — disimpan via **DMS** dengan `owning_module = Purchase`. Ini mendapatkan 80%
  nilai "supplier portal" tanpa membangun autentikasi/manajemen session supplier dulu.

**Future Version (pasca-peluncuran, begitu ada volume penggunaan/revenue nyata yang
menjustifikasi pembangunannya)**
- **Sourcing strategis penuh (RFI/RFQ/RFP)** dengan scoring kriteria-berbobot terstruktur
  (slider bobot harga/kualitas/pengiriman/ESG), bidding multi-ronde, dan penanganan sealed-bid.
- **Supplier portal penuh** — login/session supplier sungguhan, pengiriman quote self-service,
  konfirmasi order, update pelacakan pengiriman, pengiriman invoice dengan visibilitas status,
  pertukaran dokumen — evolusi dari pertukaran berbasis-tautan milik MVP, bukan rebuild (lihat
  Catatan Teknis).
- **Supplier Relationship Management (SRM)** — pelacakan sertifikasi/asuransi dengan alert
  kedaluwarsa, audit terjadwal, workflow CAPA (corrective action / preventive action), scorecard
  periodik (% tepat waktu, reject kualitas, responsivitas) dihitung dari data transaksi live.
- **Procurement berbantuan AI** — demand forecasting, rekomendasi supplier, deteksi anomali
  harga, deteksi PR duplikat — dibangun sebagai fitur baca/analisis di atas **AIInsights Core**
  (integrasi Claude API "ask your data" per-tenant yang sudah dirancang), bukan stack ML
  standalone di dalam Purchase. Lihat Catatan Teknis.
- **Scoring ESG & kepatuhan** — metrik keberlanjutan terstruktur, perhitungan/penegakan
  persentase konten lokal, pelacakan dokumen regulasi dengan kedaluwarsa — v1 hanya menyimpan
  dokumen dan flag free-form; logika scoring/penegakan ditunda.
- **Katalog punch-out** — punch-out cXML/OCI ke e-catalog supplier (mis. Amazon Business,
  gaya Grainger). Biaya integrasi nyata per supplier; belum terjustifikasi pra-revenue.
- **Penegakan budget keras**, budget multi-level/multi-currency, aturan carry-forward budget.
- **Mobile offline native** (antre scan/approval secara lokal, sinkronisasi saat online kembali)
  — v1 mengasumsikan konektivitas intermiten-tapi-hadir; offline sejati adalah pembangunan
  terpisah dan berbiaya lebih tinggi.
- **Pembelajaran toleransi pencocokan tiga arah otomatis** dan analitik belanja lanjutan
  (scoring risiko konsentrasi supplier, benchmarking kategori, forecasting tren).

# 3. Form / Engine

> Setiap Form dan Engine (Entry, View, Report, Service) — layout, logika, aturan bisnis, desain
> DB.

## 3A. Dashboard Utama

**Fungsi / fitur**
- Kesehatan procurement sekilas: PR terbuka menunggu approval, PO terbuka per status, GR
  menunggu pencocokan, invoice menunggu pencocokan/approval, kontrak kedaluwarsa dalam
  30/60/90 hari ke depan.
- Antrean "pekerjaan saya": PR/PO/invoice yang ditugaskan ke atau menunggu saya — pola antrean
  terpadu yang sama yang dipakai dashboard CRM untuk lead/tiket/case.
- Strip pengecualian: approval yang menunggak, keterlambatan pengiriman, variansi harga, flag
  budget, invoice tak tercocokkan — diambil langsung dari Exception Engine (3K), tidak dihitung
  ulang di sini.

**Layout**
- Atas: kartu ringkasan — PR Terbuka, PO Terbuka, Penerimaan Tertunda, Pencocokan Invoice
  Tertunda, Kontrak Segera Kedaluwarsa.
- Utama: tabel bertab — "Approval Saya" | "PO Saya" | "Requisition Saya" | "Pengecualian" —
  setiap baris menggunakan **Status Rail** bersama (sesuai `DESIGN.md`), motif visual yang sama
  seperti setiap modul lain.
- Klik baris membuka drawer: header, baris item, dokumen tertaut (via DMS), timeline
  audit/aktivitas, status workflow terkait (via WNE).

**Aturan / logika**
- Terlingkup-tenant oleh batas DB-per-tenant (tanpa kolom `tenant_id`).
- "Pekerjaan saya" diresolusi via penugasan langsung **dan** keanggotaan role/tim, pola resolusi
  yang sama yang sudah dipakai di WNE ("Approval Saya") dan CRM ("Pekerjaan saya").

## 3B. Purchase Requisition (PR)

- Field: requester, departemen/cost center, tanggal dibutuhkan, baris (ref item/katalog atau
  deskripsi free-text, kuantitas, estimasi harga satuan, kategori — direct/indirect,
  CAPEX/OPEX), `subject_type`/`subject_id` (tautan polimorfik opsional kembali ke record yang
  memicunya, mis. sebuah case Legal yang membutuhkan saksi ahli — pola seam opsional yang sama
  seperti setiap modul lain).
- Pemeriksaan budget lunak saat submit (catatan budget 3F) — memperingatkan, tidak hard-block,
  di MVP.
- Pemeriksaan PR duplikat di MVP adalah aturan sederhana (requester sama + item katalog sama +
  PR terbuka dalam N hari) ditandai sebagai peringatan lunak; versi berbasis AI adalah Future
  Version (3L).
- Submission memicu `WorkflowRequested` (`workflow_code = purchase.pr_approval`) ke **WNE** —
  Purchase tidak mengimplementasikan logika approval sendiri, persis seperti hubungan setiap
  modul lain dengan WNE.
- PR yang disetujui bisa langsung dikonversi menjadi PO, atau dirutekan ke Sourcing (3C) dulu
  jika requester/buyer menandainya untuk quote kompetitif.

## 3C. Engine Sourcing / RFx

**Cakupan MVP: RFQ saja, perbandingan datar.**
- Header RFQ: PR tertaut (opsional — juga bisa berdiri sendiri), tanggal jatuh tempo, supplier
  yang diundang (dari `CRM.partners` dengan role = Vendor), baris item disalin dari PR atau
  dimasukkan langsung.
- Penangkapan respons supplier: harga, lead time, catatan — per baris, per supplier — via
  pertukaran dokumen/tautan-respons ringan yang dijelaskan di §2 MVP (belum ada login portal).
- **Tampilan perbandingan**: tabel berdampingan datar, satu kolom per supplier yang merespons,
  sel termurah disorot per baris — dukungan keputusan yang cukup tanpa model scoring.
- Aksi award: memilih supplier pemenang per baris (split award diperbolehkan) → menghasilkan PO
  (3D) yang sudah pre-filled dari award RFQ.

**Penambahan Future Version (RFI/RFP + evaluasi berbobot):**
- RFI (pra-kualifikasi, tanpa harga) dan RFP (solusi + harga) sebagai tipe RFx tambahan pada
  bentuk header yang sama, dibedakan oleh kolom `type` — bukan skema terpisah, sehingga tabel
  MVP tidak butuh breaking change nantinya.
- Evaluasi berbobot: kriteria + bobot yang dapat dikonfigurasi tenant (harga/kualitas/
  pengiriman/ESG/kepatuhan), scorecard per respons supplier, composite score auto-ranked.
- Bidding multi-ronde dan aturan visibilitas sealed-bid (respons disembunyikan dari bidder lain
  dan dari viewer internal sampai ronde ditutup).

**Aturan / logika**
- Pengingat tanggal jatuh tempo RFx dan nudge "supplier belum merespons" adalah item kalender di
  **Schedule**, dinotifikasi via **WNE** — tidak ada kode pengingat bespoke di Purchase.

## 3D. Purchase Order (PO)

- Field: supplier (`CRM.partners`), header (ship-to, bill-to, currency, incoterms, payment
  terms — di-default dari `PURCHASE.vendor_profiles`, dapat di-override), baris (ref
  item/katalog atau free-text, kuantitas, harga satuan, tanggal pengiriman ekspektasi, pajak),
  PR/award RFQ tertaut (opsional), status (`draft → pending_approval → approved → sent →
  acknowledged → partially_received → received → closed → cancelled`).
- Approval dirutekan lewat **WNE** (`workflow_code = purchase.po_approval`), biasanya
  berbasis-ambang (jumlah di atas X membutuhkan approver kedua) — aturan ambang itu sendiri
  hidup di konfigurasi workflow WNE, bukan di-hardcode dalam Purchase.
- "Kirim ke supplier" mengirimkan PDF PO (dibuat, disimpan via **DMS**) via channel pilihan
  supplier (email di MVP, menggunakan pola channel-driver WNE yang sudah ada alih-alih yang
  baru).
- Konfirmasi supplier (terima / terima-dengan-perubahan / tolak) ditangkap via mekanisme
  tautan-respons ringan yang sama seperti respons RFQ di MVP; self-service portal penuh adalah
  Future Version.

**Aturan / logika**
- PO tidak bisa diedit setelah `sent` kecuali melalui amendment terlacak (nomor revisi baru,
  versi lama dipertahankan) — mencerminkan filosofi versioning "jangan pernah menimpa diam-diam"
  milik DMS.
- Membatalkan PO dengan receipt/invoice yang sudah ada terhadapnya diblokir; harus ditutup
  (closed) sebagai gantinya, untuk menjaga jejak audit pencocokan tiga arah.

## 3E. Goods Receipt (GR)

- Field: PO tertaut, baris yang diterima (kuantitas, catatan kondisi), penerima, waktu diterima,
  lampiran foto (disimpan via **DMS**), input scan barcode/QR (memindai PO atau barcode item
  untuk pre-fill baris dan kuantitas — berbasis kamera, tidak butuh hardware scanner khusus
  untuk MVP), gudang/lokasi tujuan (hanya ditampilkan ketika **Inventory** terpasang untuk
  tenant — default dari aturan Put-away milik Inventory, `INVENTORY_SPECS.md` §3R, dapat
  di-override manual; disembunyikan sepenuhnya jika Inventory tidak terpasang).
- **Posting ke Inventory (ketika terpasang):** saat GR diposting, Purchase memanggil
  `InventoryService::receive(...)` (`INVENTORY_SPECS.md` §3D/§5) dengan baris yang diterima,
  lokasi tujuan, dan biaya satuan (di-default dari harga baris PO, dapat diedit saat penerimaan
  untuk variansi landed-cost) — Inventory membuat entry `goods_receipts`/`stock_ledger` dan
  lapisan valuasinya sendiri lalu mengembalikan id-nya, disimpan pada
  `pur_receipt_hdrs.inventory_goods_receipt_id` (referensi informasional, bukan FK yang
  ditegakkan, karena Inventory adalah instalasi opsional) untuk traceability. Jika Inventory
  tidak terpasang, GR diposting normal tanpa efek fisik/valuasi — record penerimaan milik
  Purchase sendiri tidak terpengaruh baik dalam kasus mana pun.
- Penerimaan parsial didukung — sebuah PO bisa memiliki banyak GR sampai diterima penuh atau
  ditutup manual secara kurang (short).
- Over-receipt (kuantitas diterima > kuantitas dipesan) melebihi toleransi yang dapat
  dikonfigurasi menandai pengecualian (3K) alih-alih diam-diam menerimanya.
- Ketidaksesuaian (rusak, pengiriman kurang) ditangkap sebagai catatan + foto, dan secara
  opsional bisa memicu case Service/Helpdesk di **CRM** terhadap supplier (tautan
  `subject_type`/`subject_id` informasional, sama seperti setiap referensi lintas-modul lain di
  platform ini) — bukan dependensi keras, tetap berfungsi baik jika Helpdesk CRM tidak dipakai.

**Aturan / logika**
- `pur_receipt_lines.quantity_received` selalu menjadi angka otoritatif untuk pencocokan tiga
  arah (3F), terlepas dari apakah Inventory terpasang — "apakah pengiriman ini sah dan apakah
  cocok dengan yang kita pesan/tagih" adalah pertanyaan procurement yang dijawab Purchase
  sendiri. Begitu Inventory memposting entry ledger-nya sendiri dari receipt yang sama itu,
  `stock_ledger` milik Inventory menjadi angka otoritatif untuk kuantitas on-hand dan valuasi
  sejak titik itu dan seterusnya — dua pertanyaan berbeda, dijawab oleh modul yang benar-benar
  memiliki masing-masing, bukan pembukuan ganda.

## 3F. Penangkapan Invoice & Pencocokan Tiga Arah

**Tujuan:** menangkap dan memvalidasi tagihan vendor terhadap apa yang dipesan dan diterima.
Logika pencocokan ini — toleransi kuantitas/harga, penanganan ketidaksesuaian — benar-benar
pekerjaan Purchase dan tetap di sini; apa yang terjadi *setelah* tagihan divalidasi (pencatatan
AP, withholding pajak, pembayaran) adalah pekerjaan **Accounting**, sesuai resolusi di bawah.

- Field: PO tertaut, nomor/tanggal invoice supplier, baris, jumlah, currency, dokumen invoice
  terlampir (via **DMS**), channel pengiriman (entri manual, atau tautan unggah supplier ringan
  dari §2).
- **Pencocokan tiga arah**: baris PO vs. baris GR vs. baris Invoice, pada kuantitas dan harga,
  dalam toleransi yang dapat dikonfigurasi (%, atau jumlah flat) per tenant/kategori.
  - Cocok penuh → dirutekan untuk validasi internal via **WNE** (`workflow_code =
    purchase.invoice_approval` — "apakah tagihan ini sah dan apakah cocok dengan yang kita
    pesan/terima," sebuah pertanyaan procurement). Saat disetujui, Purchase memicu
    `BillRequested` ke **Accounting** (`subject_type = 'purchase.pur_invoice_hdrs'`) dengan
    header/baris yang sudah dicocokkan. Accounting membuat baris `ap_bills`/`ap_bill_lines`,
    menghitung withholding PPh jika berlaku, membuat Bukti Potong, dan memposting jurnal akun
    kontrol AP (`ACCOUNTING_SPECS.md` §3E/§3M) — **ini menutup item "siapa yang memiliki
    AP/pembayaran" yang sebelumnya ditandai di §5**: Accounting yang memilikinya, via Payment
    Scheduling & Approval engine miliknya sendiri (§3E), sebuah gerbang yang sengaja terpisah
    dari `invoice_approval` milik Purchase di atas — "apakah tagihan ini valid" (pertanyaan
    Purchase/procurement) dan "apakah kita mendisbursement uang sekarang" (pertanyaan
    Accounting/Finance) adalah keputusan berbeda, bukan birokrasi berganda.
  - Ketidaksesuaian melebihi toleransi → pengecualian (3K), dirutekan untuk review/approval
    manual alih-alih diam-diam memblokir, diam-diam membayar, atau diteruskan ke Accounting
    dalam status belum terselesaikan — Accounting tidak pernah melihat tagihan yang belum
    divalidasi Purchase.
- **Umpan balik status**: Accounting memicu `BillPosted` (saat pencatatan AP) dan
  `PaymentRecorded` (saat disbursement aktual) kembali dengan `subject_type`/`subject_id` yang
  sama — Purchase berlangganan untuk memperbarui status `pur_invoice_hdrs.status` (mis. "dikirim
  untuk pembayaran" → "dibayar") dan menutup PO asal untuk pelaporan, tanpa memelihara ledger
  utang/aging sendiri.
- **Catatan budget vs. aktual**: konsumsi budget diakui pada komitmen PO (soft-check, 3B), dan
  direkonsiliasi terhadap jumlah invoice aktual di sini — tidak perlu engine budgeting terpisah
  untuk visibilitas tingkat-MVP. "Belanja aktual" untuk tujuan pelaporan (3J) pada akhirnya
  harus direkonsiliasi ke data AP milik Accounting; angka Purchase sendiri adalah tampilan
  komitmen/intake, bukan sumber kebenaran finansial kedua.

**Aturan / logika**
- Kaki "GR" pada pencocokan tiga arah selalu membaca `pur_receipt_lines` milik Purchase sendiri
  (`quantity_received`), tidak pernah `stock_ledger` milik Inventory — ini menjaga logika
  pencocokan tetap sepenuhnya berfungsi terlepas dari apakah Inventory terpasang, dan menghindari
  definisi kedua "berapa banyak yang tiba" ada di mana pun di platform ini.
- `pur_invoice_hdrs`/`pur_invoice_lines`/`pur_invoice_matches` (§4) tetap dimiliki Purchase —
  mereka merepresentasikan tagihan vendor *sebagaimana diterima dan dicocokkan*, sebuah record
  procurement, bukan ledger AP. `ap_bills` milik Accounting adalah record utang/ledger,
  dibuat dari permintaan Purchase dan ditautkan kembali via `subject_type`/`subject_id` —
  pembagian "satu ledger, banyak requester" yang sama yang sudah diterapkan pada Sales
  (`SALES_SPECS.md` §3I) dan Inventory (`ACCOUNTING_SPECS.md` §3H), masing-masing dibentuk untuk
  apa yang benar-benar dimiliki modul itu.
- Jika Accounting tidak terpasang/aktif untuk tenant, Purchase tetap bisa menangkap dan
  mencocokkan invoice (3F tetap sepenuhnya berfungsi) tetapi tidak bisa menghasilkan utang yang
  nyata dan benar-pajak atau mengeksekusi pembayaran — pola dependensi-keras-untuk-satu-aksi-
  spesifik yang sama seperti Billing Engine milik Sales (`SALES_SPECS.md` §5), bukan persyaratan
  menyeluruh pada seluruh modul.

## 3G. Profil Vendor (memperluas CRM Partner)

- `PURCHASE.vendor_profiles`: ekstensi 1:1 dari `CRM.partners` (di mana partner memegang role
  `Vendor`) — payment terms, incoterms, currency preferensi, referensi pajak/registrasi, detail
  bank (dienkripsi saat disimpan — ditandai eksplisit sebagai field sensitif-keamanan), flag
  status preferensi, status onboarding.
- Tidak ada duplikat "master" vendor dari data CRM (nama, alamat, titik kontak) — itu tetap di
  `CRM.partners`/`CRM.addresses`/`CRM.contact_points` dan hanya dibaca/di-join, pola FK
  lintas-schema yang sama yang sudah ditetapkan spesifikasi CRM sendiri untuk hubungan
  Vertical → Core.
- **Sertifikasi/kepatuhan MVP**: daftar `pur_vendor_documents` datar (tipe, dokumen via DMS,
  tanggal kedaluwarsa) — sertifikat asuransi, izin usaha, sertifikat pajak. Pengingat
  kedaluwarsa via **Schedule** → **WNE**, pola pengingat yang sama seperti kontrak.
- **Future Version (SRM penuh)**: record audit terstruktur, workflow CAPA (ketidaksesuaian →
  corrective action → verifikasi, itu sendiri hanyalah definisi workflow **WNE** lain, bukan
  kode engine baru), scorecard periodik dihitung dari riwayat GR/invoice/RFx (% tepat waktu,
  tingkat reject, responsivitas), field metrik ESG/keberlanjutan dengan scoring nyata.

## 3H. Manajemen Kontrak

- Field: supplier tertaut, judul, tipe (framework/blanket/project), nilai, currency, tanggal
  mulai/selesai, flag auto-renewal + periode pemberitahuan, dokumen kontrak tertaut (via
  **DMS**, yang sudah menangani versioning/retensi — Purchase tidak mengimplementasikan ulang
  penanganan dokumen), status (`draft → active → expiring_soon → expired → renewed →
  terminated`).
- **Belanja-terhadap-kontrak**: total berjalan jumlah PO/invoice yang merujuk kontrak ini vs.
  nilai/ceiling kontrak — query rollup datar di MVP, tanpa forecasting.
- Pengingat perpanjangan: item **Schedule** berulang (mis. "90/60/30 hari sebelum end_at") →
  notifikasi **WNE** ke pemilik kontrak — mekanisme yang sama yang dipakai DMS untuk
  kedaluwarsa retensi, digunakan ulang alih-alih diciptakan ulang ketiga kalinya.
- **Future Version**: pelacakan klausul kepatuhan terstruktur (mis. % SLA yang diperlukan,
  sertifikat yang diperlukan tercatat) dengan flagging otomatis jika dokumen kepatuhan vendor
  tertaut kedaluwarsa di tengah kontrak.

## 3I. Manajemen Katalog

- `pur_catalog_items`: kode item, deskripsi, kategori, unit ukuran, supplier preferensi
  (`CRM.partners`), harga dinegosiasikan, harga berlaku-dari/sampai, sumber (manual / dari RFQ
  yang di-award di 3C).
- Entri baris PR/PO dapat mencari katalog untuk pre-fill harga/supplier; baris PR/PO yang
  merujuk item non-katalog ditandai (informasional, tidak memblokir) sebagai "off-catalog" untuk
  pelaporan.
- **Future Version**: integrasi katalog punch-out (cXML/OCI) untuk supplier yang mengekspos
  satu, harga multi-tier/spesifik-kontrak, daftar item yang membutuhkan approval.

## 3J. Analitik Belanja

- **MVP**: tampilan yang dapat difilter — belanja per supplier, per kategori (direct/indirect,
  CAPEX/OPEX — diatur pada baris PR/PO, lookup yang dapat diedit tenant, bukan
  auto-classifier), per cost center, per periode waktu; rollup belanja-vs-kontrak (dari 3H);
  tampilan konsentrasi supplier sederhana (% dari total belanja oleh N supplier teratas) —
  sebuah query/report, bukan engine pemodelan.
- **Future Version**: forecasting tren, benchmarking kategori, scoring *risiko* konsentrasi
  (bukan sekadar %), dan fitur anomali/forecast berbantuan AI yang dijelaskan di 3L memberi
  makan tampilan yang lebih kaya ke dashboard yang sama ini begitu dibangun.

## 3K. Engine Manajemen Pengecualian

**Tujuan:** satu-satunya tempat setiap sinyal "sesuatu butuh perhatian" di Purchase muncul —
mencerminkan bagaimana WNE mensentralisasi pengiriman notifikasi sehingga tidak ada modul yang
menciptakan ulang alerting.

- Tipe pengecualian (MVP): approval yang menunggak (PR/PO/invoice melewati SLA), keterlambatan
  pengiriman (GR belum diterima pada tanggal ekspektasi PO), variansi harga (harga
  invoice/PO di luar toleransi), flag budget (PR/PO melebihi ambang budget lunak), invoice tak
  tercocokkan (gagal pencocokan tiga arah).
- Setiap tipe pengecualian memicu event `WorkflowRequested` atau `NotificationRequested` ke
  **WNE**, dengan aturan routing/eskalasi dikonfigurasi di WNE seperti modul lain mana pun —
  Purchase tidak membangun mekanisme alerting/eskalasi paralel.
- Strip pengecualian dashboard (3A) membaca dari satu log `pur_exceptions` (gaya append-only,
  satu baris per pengecualian terdeteksi + status resolusi) alih-alih menghitung ulang di
  seluruh tabel secara live pada setiap pemuatan halaman.
- **Future Version**: deteksi PR duplikat dan anomali harga (3L, berbantuan AI) memberi makan
  log/set tipe pengecualian yang sama ini, bukan permukaan alert terpisah.

## 3L. Procurement Berbantuan AI — **Future Version**

- Dibangun sebagai aplikasi di atas **AIInsights Core** (fitur tenant-facing "ask your data"
  Claude API yang sudah dirancang untuk platform ini) alih-alih stack ML bespoke di dalam
  Purchase — menjaga biaya/kompleksitas tersentralisasi di satu tempat, dan mewarisi scoping DB
  read-only per-tenant, anotasi skema, dan model usage-metering/entitlement yang sudah ada milik
  AIInsights.
- **Demand forecasting**: query pola/tren atas volume PR/PO historis per kategori — disajikan
  sebagai ringkasan/chart yang dihasilkan AIInsights, bukan model forecasting standalone yang
  dilatih per tenant.
- **Rekomendasi supplier**: diberikan baris PR/RFQ baru, menyarankan supplier berdasarkan
  riwayat award masa lalu, daya saing harga, dan (begitu scorecard 3G ada) performa.
- **Deteksi anomali harga**: menandai baris PO/invoice yang harganya menyimpang secara material
  dari band harga historis item itu — memberi makan Exception Engine (3K) sebagai tipe
  pengecualian baru begitu dibangun, bukan channel alert terpisah.
- **Pencegahan pembelian duplikat**: aturan berbasis-rule sederhana milik MVP (3B) adalah
  placeholder; versi AI menggeneralisasinya (deskripsi near-duplicate, requester berbeda,
  kebutuhan mendasar yang sama) menggunakan permukaan query AIInsights yang sama.

## 3M. Pelacakan ESG & Kepatuhan

- **MVP**: dokumen-dan-flag saja — `pur_vendor_documents` (3G) mencakup sertifikasi/izin/
  asuransi dengan pelacakan kedaluwarsa; field `local_content_pct` persentase/free-text
  sederhana pada baris PO di mana tenant perlu melaporkannya (mis. persyaratan regulasi di
  yurisdiksi tertentu), tanpa logika penegakan.
- **Future Version**: metrik keberlanjutan terstruktur (scorecard ESG per-supplier),
  *perhitungan dan penegakan* konten lokal (blokir/peringatkan pada PO jika di bawah ambang yang
  dikonfigurasi tenant), eskalasi kedaluwarsa dokumen regulasi yang terikat ke workflow CAPA SRM
  (3G).

---

# 4. Penyimpanan

**Database (schema `PURCHASE`, DB tenant):**

**Tabel master / lookup**
- `PURCHASE.vendor_profiles` — ekstensi 1:1 dari `CRM.partners` (field spesifik procurement).
- `PURCHASE.categories` — lookup kategori belanja yang dapat diedit tenant (direct/indirect,
  CAPEX/OPEX).
- `PURCHASE.cost_centers` — lookup cost center/departemen yang dapat diedit tenant, berfungsi
  sepenuhnya standalone; mencakup `accounting_cost_center_id` opsional nullable (referensi
  informasional ke `ACCOUNTING.cost_centers.id`, bukan FK yang ditegakkan, karena Accounting
  adalah instalasi opsional) sehingga tenant yang menjalankan kedua modul dapat memetakan
  dimensi pemeriksaan budget Purchase ke daftar cost center kanonik milik Accounting
  (`ACCOUNTING_SPECS.md` §3B/§3I) alih-alih memelihara dua daftar bernomor independen — lihat
  §5.
- `PURCHASE.rfx_criteria` — Future Version: lookup kriteria evaluasi berbobot.

**Tabel transaksi** (prefix `pur_` + level, sesuai konvensi `sched_`/`hd_`/`svc_` yang sudah
ditetapkan di `SCHEDULE_SPECS.md` / `CRM_SPECS.md`)
- `pur_vendor_documents` — sertifikat/izin/asuransi, dokumen via DMS, tanggal kedaluwarsa.
- `pur_catalog_items` — item disetujui, supplier preferensi, harga dinegosiasikan.
- `pur_requisition_hdrs`, `pur_requisition_lines`
- `pur_rfx_hdrs`, `pur_rfx_lines`, `pur_rfx_invitations`, `pur_rfx_responses`,
  `pur_rfx_response_lines` — (Future: `pur_rfx_scorecards` untuk evaluasi berbobot)
- `pur_order_hdrs`, `pur_order_lines`, `pur_order_revisions` (riwayat amendment)
- `pur_receipt_hdrs` (mencakup `inventory_goods_receipt_id` nullable — referensi informasional
  ke `INVENTORY.goods_receipts.id` ketika Inventory terpasang dan GR sudah diposting di sana;
  bukan FK yang ditegakkan, karena Inventory adalah instalasi opsional untuk Purchase),
  `pur_receipt_lines`
- `pur_invoice_hdrs`, `pur_invoice_lines` (tagihan vendor sebagaimana diterima/dicocokkan —
  record intake procurement, bukan ledger AP), `pur_invoice_matches` (hasil pencocokan tiga
  arah). Utang aktual — tanggal jatuh tempo, status pembayaran, aging — hidup di
  `ACCOUNTING.ap_bills`, dibuat dari permintaan `BillRequested` begitu dicocokkan. Lihat §3F/§5.
- `pur_contract_hdrs` (dokumen tertaut via DMS, supplier tertaut via CRM)
- `pur_exceptions` — log pengecualian gaya append-only memberi makan 3A/3K
- `pur_budgets` — angka soft-budget sederhana periode × cost-center × kategori (MVP)
- Future Version: `pur_capa_records`, `pur_audit_records`, `pur_esg_scores`,
  `pur_supplier_scorecards`

**Penyimpanan file objek** (sesuai `CLAUDE.md` §7B — folder `PURCHASE/` baru per tenant,
konvensi yang sama seperti modul lain):
```text
tenant_001/PURCHASE/
├── rfx/{rfx_id}/
├── orders/{po_id}/
├── receipts/{receipt_id}/
├── invoices/{invoice_id}/
└── contracts/{contract_id}/
```
- Dalam praktiknya, sebagian besar *konten* dokumen (PDF PO, kontrak, invoice, file sertifikat)
  disimpan dan diversikan lewat **DMS** (`owning_module = Purchase`), tidak diduplikasi di
  sini — struktur folder ini ada untuk konvensi bucket R2/konsistensi perencanaan-restore yang
  dijelaskan di `CLAUDE.md` §7B, mencerminkan bagaimana DMS sendiri mencadangkan folder per
  modul.

# 5. Catatan Teknis

> Semua detail teknis yang diperlukan untuk membantu Claude Code melakukan coding.

**Pola arsitektur:** Modul Core (Modular monolith, `app/Modules/Purchase/`), bentuk dan postur
yang sama seperti WNE/DMS/CRM/Schedule. Justifikasi sesuai `CLAUDE.md` §2: tidak ada apa pun di
sini yang punya kebutuhan skala berbeda, kebutuhan runtime berbeda, atau kasus reuse-standalone
yang cukup kuat untuk menjustifikasi ekstraksi — ini CRUD + orkestrasi workflow + engine
pencocokan/perbandingan, semuanya nyaman berbentuk monolith. Satu kandidat ekstraksi *masa
depan* adalah integrasi katalog punch-out (penanganan protokol eksternal, konektor per-supplier)
— ditandai, belum dibangun.

**Titik integrasi (facade + event, pola seam yang sama seperti setiap modul Core lain):**
- **CRM** — Purchase membaca/bergantung pada `CRM.partners` untuk record vendor (FK
  lintas-schema, arah Core-peer → Core, aturan yang sama yang didokumentasikan spesifikasi CRM
  sendiri untuk dirinya: Purchase tidak pernah menulis ke schema CRM, hanya mereferensikan
  `partner_id`). Jika vendor belum ada, Purchase memanggil `PartnerService::findOrCreate(...)`
  alih-alih menyisipkan langsung ke tabel CRM.
- **Accounting** — dependensi keras untuk satu aksi spesifik pencatatan/pembayaran invoice yang
  sudah dicocokkan (§3F): Purchase memicu `BillRequested`/membaca `BillPosted`/
  `PaymentRecorded`, dan tidak pernah memelihara ledger AP, withholding pajak, atau eksekusi
  pembayaran sendiri. Semua yang hulu dari itu (PR, Sourcing, PO, Goods Receipt, penangkapan dan
  pencocokan invoice) bekerja dengan Accounting tidak ada — bentuk dependensi-keras-terbatas
  yang sama seperti hubungan Sales dengan Accounting (`SALES_SPECS.md` §5), bukan persyaratan
  menyeluruh tingkat-modul. Secara terpisah, dan opsional: ketika Accounting terpasang, baris
  `PURCHASE.cost_centers` dapat dipetakan ke `ACCOUNTING.cost_centers` (§4) sehingga pemeriksaan
  budget lunak Purchase (§3B) dan budget-vs-aktual per cost center milik Accounting
  (`ACCOUNTING_SPECS.md` §3J) direkonsiliasi ke dimensi yang sama; tanpa pemetaan itu (atau
  tanpa Accounting sama sekali), cost center Purchase tetap menjadi daftar lokal yang sepenuhnya
  bisa dipakai sendiri.
- **WNE** — setiap approval (PR, PO, invoice) dan setiap notifikasi (pengecualian, pengingat,
  komunikasi supplier) dirutekan lewat `MessagingService::requestWorkflow(...)` /
  `::notify(...)`. Purchase mengimplementasikan nol logika approval atau pengiriman sendiri.
- **DMS** — setiap dokumen (PDF PO, kontrak, invoice, sertifikat vendor, lampiran RFx) disimpan
  via `DocumentService::upload()`/`::attach()`, mewarisi versioning, retensi, dan jejak audit
  DMS secara gratis alih-alih Purchase mengimplementasikan ulang salah satunya.
- **Inventory** — dependensi lunak, terbatas sempit pada penerimaan fisik: ketika Inventory
  diaktifkan untuk tenant, Goods Receipt (§3E) memanggil `InventoryService::receive(...)` untuk
  memposting pergerakan stock-ledger aktual dan lapisan valuasi. Setiap bagian lain Purchase
  (PR, Sourcing, PO, penangkapan invoice, pencocokan tiga arah) bekerja identik terlepas dari
  apakah Inventory terpasang, karena pencocokan tiga arah selalu membaca `pur_receipt_lines`
  milik Purchase sendiri, tidak pernah ledger milik Inventory — bentuk dependensi-lunak-terbatas
  yang sama seperti hubungan Purchase dengan WNE/DMS/Schedule, bukan persyaratan menyeluruh
  tingkat-modul.
- **Schedule** — setiap pengingat berbasis-tanggal (perpanjangan kontrak, kedaluwarsa
  sertifikat, tanggal jatuh tempo RFx) adalah item kalender yang dibuat via facade Schedule,
  bukan cron/pemeriksaan-tanggal bespoke di dalam Purchase.
- **AIInsights Core** — semua fitur AI Future Version (3L) adalah query/tool AIInsights yang
  terlingkup ke tabel Purchase, bukan integrasi AI terpisah.

**Batas cakupan MVP (eksplisit, sehingga tidak ada yang di bawah ini memblokir peluncuran
pertama yang cepat):**
- Hanya RFQ di sourcing v1 (3C); RFI/RFP + scoring berbobot bersifat aditif pada bentuk tabel
  `rfx_hdrs` yang sama (kolom `type` + tabel `pur_rfx_scorecards` masa depan), bukan breaking
  change nantinya.
- "Portal" supplier di v1 adalah pertukaran tautan-unggah bertanda tangan, bukan akun supplier
  terautentikasi — login portal sungguhan adalah Future Version, dan tabel respons/dokumen
  dibentuk sedemikian rupa sehingga menambahkan autentikasi supplier nyata nantinya tidak
  membutuhkan pemodelan ulang respons RFx atau pengiriman invoice, hanya menambahkan lapisan
  identitas di depannya.
- Pemeriksaan budget bersifat lunak (peringatkan + rutekan untuk approval tambahan) — tidak ada
  engine penegakan budget keras di v1, sesuai bias MVP.
- Tidak ada punch-out, tidak ada model AI, tidak ada logika scoring ESG, tidak ada engine
  workflow CAPA di v1 — semuanya terdaftar eksplisit di §2 Future Version dengan jalur reuse
  yang dinyatakan sehingga tidak ada yang membutuhkan penulisan ulang skema saat dibangun.
- **Eksekusi pembayaran di luar cakupan untuk Purchase — dan sekarang punya pemilik konkret.**
  Pekerjaan Purchase berhenti pada "invoice dicocokkan dan divalidasi" (§3F); **Accounting**
  memiliki pencatatan AP, withholding PPh, dan pemrosesan/disbursement pembayaran aktual via
  engine AP miliknya yang sudah ada (`ACCOUNTING_SPECS.md` §3E), dipicu oleh `BillRequested`.
  Ini sebelumnya adalah item terbuka yang menunjuk ke "modul Finance/AP" yang belum dirancang;
  Accounting sekarang mengisi peran itu.

**Catatan isolasi tenant:** Purchase dispesifikasikan **tanpa** kolom `tenant_id`, konsisten
dengan `CLAUDE.md` §4/§7 (DB-per-tenant adalah batas isolasi).

**Urutan pembangunan yang disarankan untuk Claude Code:** 3G (profil vendor, ekstensi tipis dari
CRM) → 3B/3D (alur inti PR → PO, tulang punggung tempat semua yang lain bergantung) → 3E (Goods
Receipt, termasuk penangkapan barcode/foto; hubungkan panggilan opsional
`InventoryService::receive()` jika Inventory sudah live, atau luncurkan 3E tanpanya dan tambahkan
panggilan itu nanti — bersifat murni aditif) → 3F (penangkapan invoice + pencocokan tiga arah —
konfirmasikan engine AP Accounting, §3E `ACCOUNTING_SPECS.md`, sudah live sebelum menghubungkan
handoff `BillRequested`) → 3K (log pengecualian, murah dan bernilai tinggi begitu 3B–3F ada) →
3I (katalog) → 3H (kontrak, hubungkan ke DMS + Schedule) → 3C (RFQ) → 3J (tampilan analitik
belanja) → 3M (flag dokumen ESG) — lalu tinjau ulang RFx berbobot 3C, SRM penuh 3G, dan 3L
(berbantuan AI) sebagai Future Version begitu MVP punya penggunaan nyata.

**Catatan kelayakan jual (marketability)**
- Pencocokan tiga arah + jejak audit penuh adalah pitch "kenapa tidak pakai email/spreadsheet
  saja" yang kuat untuk audiens pembeli legal konservatif yang sama yang ditargetkan spesifikasi
  CRM — disiplin procurement terbaca sebagai kematangan institusional. Karena pencatatan AP
  sekarang selalu dirutekan lewat Accounting, setiap tagihan vendor mendapat withholding PPh
  yang benar dan Bukti Potong secara konstruksi — cerita compliance-by-construction yang sama
  yang sudah diceritakan untuk sisi AR milik Sales.
- Menggunakan ulang registry Partner milik CRM untuk vendor (alih-alih master vendor terpisah)
  berarti Purchase murah untuk diaktifkan bagi tenant yang sudah punya CRM aktif, dan cerita
  "satu partner, banyak role" (landlord sebuah firma juga bisa menjadi vendor) adalah
  diferensiator sungguhan.
- Pendekatan tautan-unggah supplier ringan mendapatkan sebagian besar nilai marketing "supplier
  portal" dengan sebagian kecil biaya pembangunan — layak didemokan sebagai "supplier
  self-service" bahkan sebelum login portal sungguhan ada.
- Analitik belanja (bahkan versi MVP yang datar) adalah pemicu upsell alami menuju AIInsights
  Core begitu tenant punya cukup riwayat transaksi agar fitur AI terasa berharga.

**Item terbuka untuk diisi seiring pertumbuhan modul ini**
- [x] ~~Kepemilikan modul Finance/AP~~ — **terselesaikan**: Accounting mengeksekusi pembayaran
      via Payment Scheduling & Approval engine miliknya sendiri (`ACCOUNTING_SPECS.md` §3E),
      dipicu oleh `BillRequested` milik Purchase saat approval pencocokan tiga arah. Lihat §3F.
- [x] ~~Kepemilikan Goods Receipt ↔ stok fisik~~ — **terselesaikan**: GR milik Purchase tetap
      menjadi record procurement/pencocokan-tiga-arah; ketika Inventory terpasang, posting GR
      secara tambahan memanggil `InventoryService::receive()` untuk memposting pergerakan
      stock-ledger dan lapisan biaya aktual. Lihat §3E dan `INVENTORY_SPECS.md` §5.
- [ ] Kedalaman penanganan multi-currency (sumber kurs FX, revaluasi) — v1 mengasumsikan satu
      currency transaksi per PO/invoice, disimpan sebagaimana dimasukkan.
- [ ] Apakah Supplier Portal penuh yang akhirnya dibangun merupakan area terautentikasi terpisah
      dari aplikasi yang sama, atau frontend ringan yang berbeda — putuskan begitu penggunaan
      portal nyata tervalidasi.
