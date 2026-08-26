# Sales Module Implementation Progress Report

> **Nusaevo ERP - Sales Management Module**  
> **Status:** 100% Completed  
> **Specs Reference:** `app/Modules/Sales/SALES_SPECS.md`, `app/Modules/Sales/SALES_SPECS.id.md`, `app/Modules/Sales/SALES_SPECS.sql`  
> **Schema:** `"SALES"` (PostgreSQL Multi-Tenant DB-per-tenant architecture)

---

## 1. Executive Summary

Modul **Sales** telah selesai diimplementasikan secara menyeluruh sesuai dengan seluruh spesifikasi pada `SALES_SPECS.md`. Modul ini mengelola seluruh siklus **Quote-to-Cash**:
1. **Pipeline & Opportunity**: Dari kualifikasi prospek CRM hingga deal dimenangkan (`won`).
2. **Estimates & Immutable Quotations**: Pembuatan penawaran dengan branching revisi otomatis (immutable revision) tanpa menimpa histori revisi sebelumnya.
3. **Sales Orders & Credit Checks**: Konversi penawaran ke SO, pembuatan pesanan langsung, serta pengecekan limit kredit dan status akun customer secara real-time via integrasi `Accounting`.
4. **Deliveries & Fulfillment**: Siklus pick, pack, ship, deliver dengan integrasi pemotongan stok pada modul `Inventory` (`InventoryService::issue`).
5. **Billing Request Orchestration**: Pengiriman request pembuatan faktur ke modul `Accounting` (`AccountingService::createInvoice` / `InvoiceRequested`) tanpa duplikasi tabel invoice.
6. **Contracts & Recurring Billing**: Perjanjian kontrak langganan dengan penjadwalan otomatis penagihan berulang (*recurring billing schedules*).
7. **Sales Returns (RMA)**: Penanganan retur barang/jasa dengan opsi penerbitan *Credit Note* (refund) atau pembuatan *Replacement Sales Order*.
8. **Commission Plans & Settlements**: Perhitungan komisi sales representatif (skema flat dan berjenjang/tiered) berdasarkan pendapatan yang telah lunas/dibayar (*payment recorded*).
9. **Customer Self-Service Portal**: Halaman portal read-only berbasis signed token unik (`/portal/sales/{token}`) bagi pelanggan untuk melacak penawaran, order, pengiriman, dan tagihan.
10. **Database Seeder**: [`Database\Seeders\SalesSeeder`](file:///home/spil/projects/personal/nusaevo-erp/database/seeders/SalesSeeder.php) siap pakai untuk staging, demo, maupun production tenant initial seeding.

---

## 2. Database Schema & Migrations (`database/migrations/tenant/`)

Semua tabel dibuat dalam skema `"SALES"` pada tenant database:

| Migration File | Schema / Tables Created | Deskripsi |
|---|---|---|
| `2026_08_26_130000_create_sales_schema.php` | `CREATE SCHEMA "SALES"` | Inisialisasi skema isolasi modul Sales |
| `2026_08_26_130001_create_sales_master_tables.php` | `territories`, `sales_teams`, `sales_team_members`, `price_lists`, `price_list_lines`, `promo_codes`, `commission_plans`, `customer_sales_profiles`, `customer_credit_profiles`, `sales_portal_tokens` | Master data konfigurasi penjualan, tim, wilayah, promo, komisi, profil customer & portal tokens |
| `2026_08_26_130002_create_sales_opportunity_tables.php` | `opp_hdrs` | Pipeline peluang penjualan terintegrasi dengan CRM Leads dan Partners |
| `2026_08_26_130003_create_sales_quotation_tables.php` | `quot_hdrs`, `quot_lines` | Header & baris penawaran dengan immutable revision tracking (`quote_group_id`, `revision_no`) |
| `2026_08_26_130004_create_sales_order_tables.php` | `so_hdrs`, `so_lines` | Header & baris Sales Order dengan pelacakan qty terkirim (`qty_delivered`) dan tertagih (`qty_invoiced`) |
| `2026_08_26_130005_create_sales_delivery_tables.php` | `dlv_hdrs`, `dlv_lines` | Surat jalan & pengiriman fulfillment barang/jasa dengan referensi carrier & AWB tracking |
| `2026_08_26_130006_create_sales_contract_and_recurring_tables.php` | `contr_hdrs`, `contr_subscriptions`, `recurring_billing_schedules` | Kontrak perjanjian berkala & instansiasi jadwal penagihan otomatis |
| `2026_08_26_130007_create_sales_return_tables.php` | `ret_hdrs`, `ret_lines` | RMA Retur penjualan dengan link ke SO asli dan replacement SO |
| `2026_08_26_130008_create_sales_commission_tables.php` | `comm_settlements`, `comm_settlement_lines` | Batch settlement komisi rep dan perincian kalkulasi per line |

---

## 3. Eloquent Models (`app/Modules/Sales/Models/`)

Sebanyak 22 Model Eloquent telah dibangun lengkap dengan casting atribut, relasi antar modul, UUID generation, dan konstanta status:

1. **Master**:
   - `Territory`: Wilayah penjualan hierarkis
   - `SalesTeam`: Tim penjualan
   - `SalesTeamMember`: Anggota tim representatif
   - `PriceList`: Daftar harga (default tenant, segment, territory)
   - `PriceListLine`: Item harga per produk/jasa
   - `PromoCode`: Kode promo persentase / nominal tetap dengan kuota & masa berlaku
   - `CommissionPlan`: Skema komisi (*flat* atau *tiered base/excess*)
   - `CustomerSalesProfile`: Pengaturan tim & daftar harga default pelanggan
   - `CustomerCreditProfile`: Limit kredit, termin pembayaran (hari), dan status *hold*
   - `SalesPortalToken`: Token akses signed URL self-service pelanggan
2. **Opportunities**:
   - `Opportunity`: Pipeline deal (`new`, `qualified`, `proposal`, `negotiation`, `won`, `lost`)
3. **Quotations**:
   - `Quotation`: Header penawaran dengan immutable grouping
   - `QuotationLine`: Baris rincian kuotasi
4. **Sales Orders**:
   - `SalesOrder`: Header SO (`draft`, `confirmed`, `partially_fulfilled`, `fulfilled`, `cancelled`)
   - `SalesOrderLine`: Baris rincian SO
5. **Deliveries**:
   - `Delivery`: Surat jalan pengiriman (`pending`, `picked`, `packed`, `shipped`, `delivered`, `cancelled`)
   - `DeliveryLine`: Item pengiriman
6. **Contracts & Subscriptions**:
   - `Contract`: Kontrak perjanjian (`draft`, `active`, `expired`, `cancelled`)
   - `ContractSubscription`: Baris layanan berkala
   - `RecurringBillingSchedule`: Jadwal penagihan berulang (`pending`, `invoiced`, `skipped`)
7. **Returns**:
   - `SalesReturn`: Pengajuan RMA (`pending`, `approved`, `received`, `completed`, `rejected`)
   - `SalesReturnLine`: Rincian barang/jasa yang diretur
8. **Commissions**:
   - `CommissionSettlement`: Batch settlement komisi per periode (`draft`, `approved`, `paid`, `cancelled`)
   - `CommissionSettlementLine`: Rincian perolehan komisi per SO Line

---

## 4. Domain Services (`app/Modules/Sales/Services/`)

1. **`PricingService.php`**: Resolusi hierarki harga (Price List khusus pelanggan $\rightarrow$ default tenant $\rightarrow$ harga standar), validasi kode promo, dan kalkulasi subtotal/diskon/pajak.
2. **`CreditService.php`**: Pengecekan eksposur piutang real-time (`AccountingService::getOpenARBalance()`), validasi limit kredit, dan pemblokiran konfirmasi SO jika melebihi plafon atau akun *on hold*.
3. **`QuotationService.php`**: Pembuatan draft, revisi immutabel berantai (*branching* revisi baru), konversi ke Sales Order, dan *cloning* penawaran kedaluwarsa.
4. **`SalesOrderService.php`**: Penomoran format `SO-YYYYMM-XXXX`, CRUD, konversi eksternal via event, konfirmasi dengan verifikasi kredit, serta pembatalan terkontrol.
5. **`DeliveryService.php`**: Alur fulfillment (pick $\rightarrow$ pack $\rightarrow$ ship $\rightarrow$ deliver), integrasi pemotongan stok fisik (`InventoryService::issue`), dan pembaruan akumulasi `so_lines.qty_delivered`.
6. **`BillingService.php`**: Integrasi orkestrasi faktur penjualan ke Accounting (`AccountingService::createInvoice`), pemrosesan *sweep* penagihan berulang berkala, dan verifikasi piutang jatuh tempo.
7. **`ReturnService.php`**: Alur RMA, penerbitan *Credit Note*, pembuatan *Replacement Sales Order* bernilai 0, serta pencatatan pembalikan komisi (*commission reversal*).
8. **`CommissionService.php`**: Kalkulasi komisi flat dan berjenjang dari pembayaran lunas (*PaymentRecorded*), pembuatan batch settlement, approval, dan penandaan pembayaran.
9. **`ContractService.php`**: Pembuatan kontrak, aktivasi dengan *auto-seeding* jadwal penagihan berkala, perpanjangan masa berlaku (*renew*), dan terminasi.
10. **`OpportunityService.php`**: Pengelolaan status pipeline deal, pencatatan alasan kekalahan (*loss reason*), dan *trigger* event saat deal dimenangkan.
11. **`PortalService.php`**: Pembuatan signed token aman 64-karakter dan resolusi data komprehensif untuk halaman self-service pelanggan.
12. **`SalesDashboardService.php`**: Agregasi KPI real-time (Open Quotes, Open Orders, Revenue MTD, Overdue AR), pipeline funnel analytics, dan antrean kerja personal (*My Work Queues*).

---

## 5. Domain Events & Listeners (`app/Modules/Sales/Events/` & `Listeners/`)

- **Domain Events**:
  - `OpportunityWon`
  - `QuotationSent`
  - `QuotationConverted`
  - `SalesOrderConfirmed`
  - `SalesOrderRequested`
  - `DeliveryShipped`
  - `InvoiceOverdue`
  - `ContractRenewed`
  - `ContractExpiring`
  - `CreditBlocked`
- **Domain Listeners**:
  - `UpdateSalesOrderOnInvoicePosted`: Memperbarui `so_lines.qty_invoiced` saat faktur di-post di modul Accounting.
  - `ProcessCommissionOnPaymentRecorded`: Menghitung komisi sales rep saat pembayaran pelanggan dicatat di Accounting.
  - `CreateSalesOrderFromRequested`: Mengonversi request pesanan dari modul lain / portal menjadi Sales Order.

---

## 6. Form Requests & Controllers (`app/Modules/Sales/Requests/` & `Controllers/`)

- **Form Requests**: `StoreOpportunityRequest`, `StoreQuotationRequest`, `StoreSalesOrderRequest`, `StoreDeliveryRequest`, `StoreContractRequest`, `StoreReturnRequest`, `StoreCommissionSettlementRequest`, `StorePriceListRequest`, `StoreSalesTeamRequest`, `StoreTerritoryRequest`, `StorePromoCodeRequest`, `StoreCommissionPlanRequest`, `StoreCustomerProfileRequest`.
- **Controllers**:
  - `SalesDashboardController`: Render dashboard utama, funnel, dan antrean kerja.
  - `OpportunityController`: CRUD, Kanban drag-and-drop / stage update.
  - `QuotationController`: CRUD, kirim ke pelanggan, revisi, konversi ke SO, kloning expired.
  - `SalesOrderController`: CRUD, konfirmasi, pembatalan, request faktur Accounting.
  - `DeliveryController`: CRUD surat jalan dan pembaruan status logistik.
  - `ContractController`: CRUD perjanjian, aktivasi jadwal penagihan, perpanjangan.
  - `ReturnController`: CRUD retur RMA, approval, penerimaan fisik, refund, replacement order.
  - `CommissionSettlementController`: Batch settlement komisi, persetujuan, pembayaran.
  - `PriceListController`, `SalesTeamController`, `TerritoryController`, `PromoCodeController`, `CommissionPlanController`, `CustomerProfileController`: Master configuration controllers.
  - `CustomerPortalController`: Controller portal publik terverifikasi token.

---

## 7. Routes & System Configuration

1. **Routes (`app/Modules/Sales/Routes/web.php`)**:
   - Didaftarkan dengan middleware `['auth', 'module:SALES', 'menu.perm:SALES']` di bawah prefix `/sales/*`.
   - Rute portal publik: `/portal/sales/{token}`.
   - Dimuat di `routes/web.php`.
2. **SysConfigSeeder (`database/seeders/SysConfigSeeder.php`)**:
   - Menu `SALES` diaktifkan (`status_code = 'A'`), diarahkan ke `/sales/dashboard`, icon `ShoppingCart`.
   - Matriks hak akses: `ADMIN` $\rightarrow$ `CRUD`, `STAFF` $\rightarrow$ `CRUD`, `VIEWER` $\rightarrow$ `R`.
3. **AppServiceProvider (`app/Providers/AppServiceProvider.php`)**:
   - Mendaftarkan listener modul Sales untuk event Accounting (`InvoicePosted`, `PaymentRecorded`) dan Sales (`SalesOrderRequested`).

---

## 8. Frontend Components & Views (`resources/js/`)

Seluruh halaman dibangun menggunakan **Vue 3**, **Inertia.js**, dan styling konsisten dengan `DESIGN.md`:

- **Sub-Navigations**:
  - `resources/js/Components/sales/SalesSubNav.vue` (Navigasi modul Sales)
  - `resources/js/Components/sales/SalesMasterSubNav.vue` (Navigasi master data)
- **Pages**:
  - **Dashboard**: `resources/js/Pages/Sales/Dashboard/Index.vue`
  - **Opportunities**: `Index.vue` (Kanban & List toggle), `Create.vue`, `Edit.vue`
  - **Quotations**: `Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue` (Immutable revisions visualizer)
  - **Sales Orders**: `Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue` (Live credit exposure, linked deliveries & invoices)
  - **Deliveries**: `Index.vue`, `Create.vue`, `Show.vue` (Status progression: pick/pack/ship/deliver)
  - **Contracts**: `Index.vue`, `Create.vue`, `Edit.vue`, `Show.vue` (Subscription lines & schedules)
  - **Returns**: `Index.vue`, `Create.vue`, `Show.vue` (RMA workflow: approve, receive, refund, replace)
  - **Commissions**: `Index.vue`, `Show.vue` (Settlement batch breakdown & approval)
  - **Master Config**:
    - `PriceLists/Index.vue`, `Create.vue`, `Edit.vue`
    - `Teams/Index.vue`
    - `Territories/Index.vue`
    - `PromoCodes/Index.vue`
    - `CommissionPlans/Index.vue`
    - `CustomerProfiles/Index.vue`, `Edit.vue`
  - **Customer Portal**: `resources/js/Pages/Sales/Portal/Show.vue`

---

## 9. Verification & Automated Test Suite

- Dibuat suite pengujian komprehensif pada `tests/Feature/Sales/SalesModuleLifecycleTest.php`:
  1. `test_full_sales_quote_to_order_to_delivery_lifecycle()`: Menguji alur lengkap dari Quotation $\rightarrow$ Revisi immutabel $\rightarrow$ Konversi ke SO $\rightarrow$ Konfirmasi SO $\rightarrow$ Pengiriman barang (Delivery Shipped) $\rightarrow$ Pembaruan `qty_delivered`.
  2. `test_credit_check_blocks_confirmation_when_limit_exceeded()`: Memverifikasi pencegahan konfirmasi pesanan ketika eksposur piutang melebihi limit kredit customer.
  3. `test_recurring_contracts_and_schedules_lifecycle()`: Menguji pembuatan kontrak, aktivasi, dan pembentukan instansiasi jadwal penagihan berkala bulanan.
  4. `test_sales_return_replacement_and_refund_lifecycle()`: Menguji alur retur RMA dan penerbitan *replacement sales order* bernilai 0.
  5. `test_customer_portal_token_verification()`: Menguji resolusi data dan verifikasi token pada self-service portal pelanggan.

---

## 10. Database Seeder untuk Staging & Production (`database/seeders/SalesSeeder.php`)

Telah dibuat seeder lengkap [**`SalesSeeder.php`**](file:///home/spil/projects/personal/nusaevo-erp/database/seeders/SalesSeeder.php) yang siap dijalankan mandiri maupun terintegrasi dengan `DatabaseSeeder`:
- **Wilayah Penjualan (Territories)**: DKI Jakarta, Jawa Barat, Jawa Timur, Bali, Sumatera.
- **Tim Sales**: Enterprise & Corporate Sales, Commercial & SMB Solutions dengan penugasan user anggota.
- **Price Lists & Pricing Matrix**: Standard Commercial Rates 2026 (Tenant Default) dan Strategic Enterprise Tier.
- **Promo Codes**: Diskon persentase (`NUSAEVO2026` 10%) dan diskon nominal tetap (`DIRECT5M` 5 Juta).
- **Commission Plans**: Flat 5% dan Tiered (Base 3% + Excess 7% di atas 100 Juta).
- **Profil Customer & Plafon Kredit**: Pengaturan limit kredit (500 Juta, 150 Juta, 50 Juta), termin pembayaran (30 hari, 14 hari), dan token signed URL Customer Portal.
- **Sample Pipeline Opportunities**: Tahapan `negotiation`, `proposal`, `won` dengan taksiran nilai transaksi.
- **Sample Quotations**: Draft dan penawaran terkirim dengan rincian line item.
- **Sample Sales Orders & Fulfillment**: SO terkonfirmasi dan pengiriman (Delivery Note) dengan nomor AWB/tracking logistik nyata.
- **Sample Kontrak & Langganan**: Perjanjian retainer aktif 1 tahun lengkap dengan 12 instansiasi jadwal penagihan berkala bulanan.
- **Sample RMA Returns**: Pengajuan retur barang diterima dengan alasan kerusakan transit.
- **Sample Commission Settlements**: Batch settlement komisi perwakilan berstatus `approved`.

### Cara Menjalankan Seeder:
```bash
# Menjalankan seeder sales langsung pada tenant aktif:
php artisan db:seed --class=SalesSeeder

# Atau via database refresh/seed tenant standar:
php artisan db:seed
```
