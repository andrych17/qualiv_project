<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\ApBillLine;
use App\Modules\Accounting\Models\ArInvoice;
use App\Modules\Accounting\Models\ArInvoiceLine;
use App\Modules\Accounting\Models\ArPayment;
use App\Modules\Accounting\Models\ArPaymentApplication;
use App\Modules\Accounting\Models\BankAccount;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\RecurringJournalTemplate;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListLine;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigGroupUser;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class QualivMigrateExcelSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = 'qualiv';
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant) {
            $tenant->delete();
        }

        $tenant = Tenant::create([
            'id' => $tenantId,
            'name' => 'Qualiv AI',
            'plan' => 'enterprise',
        ]);

        // Central User Lookups
        $userAccounts = [
            ['email' => 'andry@qualiv.id', 'name' => 'Andry Huang'],
            ['email' => 'henry@qualiv.id', 'name' => 'Henry Sebastian'],
        ];

        foreach ($userAccounts as $acc) {
            TenantUserLookup::query()->updateOrCreate(
                ['email' => $acc['email'], 'tenant_id' => $tenantId],
                []
            );
        }

        // Run within Tenant Database
        $tenant->run(function () use ($userAccounts) {
            $this->seedSysConfigAndAccounting();
            $users = $this->seedUsers($userAccounts);
            $this->seedCrmPartners();
            $this->seedProjectsAndIssues($users);
            $this->seedSalesPricing();
            $this->seedAccountingExpenses($users);
        });
    }

    private function seedSysConfigAndAccounting(): void
    {
        $this->call(SysConfigSeeder::class);
        $this->call(AccountingSeeder::class);
    }

    private function seedUsers(array $userAccounts): array
    {
        $createdUsers = [];
        $adminGroup = ConfigGroup::query()->where('code', 'ADMIN')->first();

        foreach ($userAccounts as $acc) {
            $user = User::query()->updateOrCreate(
                ['email' => $acc['email']],
                [
                    'name' => $acc['name'],
                    'password' => 'password123',
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            $createdUsers[$acc['email']] = $user;

            // Grant Admin Group
            if ($adminGroup) {
                ConfigGroupUser::query()->updateOrCreate(
                    ['group_id' => $adminGroup->id, 'user_id' => $user->id],
                    ['group_code' => $adminGroup->code]
                );
            }
        }

        return $createdUsers;
    }

    private function seedCrmPartners(): void
    {
        $customerRoleType = PartnerRoleType::query()->firstOrCreate(
            ['code' => 'customer'],
            ['name' => 'Customer', 'is_active' => true]
        );

        $vendorRoleType = PartnerRoleType::query()->firstOrCreate(
            ['code' => 'vendor'],
            ['name' => 'Vendor', 'is_active' => true]
        );

        // Bank of Sampoerna (Customer)
        $sampoerna = Partner::query()->updateOrCreate(
            ['name' => 'Bank of Sampoerna'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'trade_name' => 'Bank of Sampoerna',
                'is_active' => true,
                'source' => 'Pilot Client',
            ]
        );
        ContactPoint::query()->updateOrCreate(
            ['partner_id' => $sampoerna->id, 'value' => '+62 812-8618-4902'],
            ['type' => 'phone', 'is_primary' => true]
        );
        ContactPoint::query()->updateOrCreate(
            ['partner_id' => $sampoerna->id, 'value' => '+62 878-7846-6041'],
            ['type' => 'phone', 'is_primary' => false]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $sampoerna->id,
            'role_type_id' => $customerRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);

        // Knitto MERR (Customer)
        $knitto = Partner::query()->updateOrCreate(
            ['name' => 'Knitto MERR'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'trade_name' => 'Knitto',
                'is_active' => true,
                'source' => 'Pilot Client',
            ]
        );
        ContactPoint::query()->updateOrCreate(
            ['partner_id' => $knitto->id, 'value' => '+62 853-1817-0486'],
            ['type' => 'phone', 'is_primary' => true]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $knitto->id,
            'role_type_id' => $customerRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);

        // Spectrum Darmo (Vendor Percetakan)
        $spectrum = Partner::query()->updateOrCreate(
            ['name' => 'Spectrum Darmo'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'trade_name' => 'Spectrum Darmo',
                'is_active' => true,
                'source' => 'Vendor Percetakan',
            ]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $spectrum->id,
            'role_type_id' => $vendorRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);

        // IndoSMM (Vendor Social Media)
        $indosmm = Partner::query()->updateOrCreate(
            ['name' => 'IndoSMM'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'trade_name' => 'IndoSMM',
                'is_active' => true,
                'source' => 'Vendor Marketing',
            ]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $indosmm->id,
            'role_type_id' => $vendorRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);

        // Hostinger (Vendor Hosting & Domain)
        $hostinger = Partner::query()->updateOrCreate(
            ['trade_name' => 'Hostinger'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'name' => 'Hostinger International Ltd.',
                'tags' => ['vendor', 'hosting', 'vps', 'domain'],
                'source' => 'Server & Infra',
                'is_active' => true,
            ]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $hostinger->id,
            'role_type_id' => $vendorRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);

        // Meta Platforms (Vendor Ads & Subscription)
        $meta = Partner::query()->updateOrCreate(
            ['trade_name' => 'Meta Platforms'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'name' => 'Meta Platforms Ireland Limited',
                'tags' => ['vendor', 'marketing', 'meta-ads', 'subscription'],
                'source' => 'Marketing & Ads',
                'is_active' => true,
            ]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $meta->id,
            'role_type_id' => $vendorRoleType->id,
        ], ['assigned_at' => now(), 'is_active' => true]);
    }

    private function seedProjectsAndIssues(array $users): void
    {
        $andry = $users['andry@qualiv.id'] ?? User::first();
        $henry = $users['henry@qualiv.id'] ?? User::skip(1)->first() ?? $andry;

        $project = Project::query()->updateOrCreate(
            ['code' => 'QLV'],
            [
                'name' => 'Qualiv.id Platform Launch & Operation',
                'description' => 'Project manajemen peluncuran platform Qualiv.id, integrasi AI, UAT bug fixes, dan marketing campaign.',
                'status' => 'active',
                'lead_id' => $andry->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-12-31',
                'next_issue_seq' => 1,
            ]
        );

        $issues = [
            // Settlement & Finance
            ['title' => 'Settlement Talangan Operasional: Henry vs Andry (Selisih Rp22.000)', 'desc' => 'Rekonsiliasi pengeluaran: Follower IG (Andry ditalangi Rp100.000) & Cetak Kartu Nama (Henry ditalangi Rp56.000). Total pengeluaran bersama = Rp156.000 (Beban 50:50 = Rp78.000/orang). Selisih: Henry transfer ke Andry sebesar Rp22.000.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'urgent', 'assignee' => $henry->id],

            // Timeline Production Tasks
            ['title' => 'Flow CV screening di AI interview chat', 'desc' => 'Perbaiki alur CV screening dan integrasi dengan chat interview.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'urgent', 'assignee' => $andry->id],
            ['title' => 'Testing logic test', 'desc' => 'Testing komprehensif logic test scoring dan transisi soal.', 'type' => 'task', 'status' => 'done', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'Perbaiki AI interview video', 'desc' => 'Optimalisasi video interview AI, STT/TTS transcript latency.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'urgent', 'assignee' => $andry->id],
            ['title' => 'Shortlist candidate ranking algorithm', 'desc' => 'Algoritma peringkat dan komparasi shortlist pelamar otomatis.', 'type' => 'task', 'status' => 'todo', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'Payment Gateway - Midtrans integration', 'desc' => 'Integrasi checkout paket & top up token kuota via Midtrans/QRIS.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'urgent', 'assignee' => $henry->id],
            ['title' => 'Review landing page (copywriting & wording)', 'desc' => 'Review akurasi teks, copywriting, dan call to action landing page.', 'type' => 'task', 'status' => 'done', 'prio' => 'medium', 'assignee' => $henry->id],
            ['title' => 'Costing and pricing setup', 'desc' => 'Analisis biaya unit cost per kandidat vs margin harga paket.', 'type' => 'task', 'status' => 'done', 'prio' => 'high', 'assignee' => $henry->id],
            ['title' => 'Marketing di social media - ngonten + Info Surabaya', 'desc' => 'Jadwal posting reels Instagram dan kampanye media partner.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'high', 'assignee' => $henry->id],
            ['title' => 'Pilot testing - internal Bank of Sampoerna & Knitto', 'desc' => 'Uji coba pilot run dengan klien korporat awal.', 'type' => 'story', 'status' => 'todo', 'prio' => 'urgent', 'assignee' => $andry->id],

            // UAT Issues
            ['title' => 'UAT-01: Gagal kirim email di website (Failed to fetch)', 'desc' => 'Tidak bisa kirim email di website qualiv - muncul alert failed to fetch.', 'type' => 'bug', 'status' => 'done', 'prio' => 'urgent', 'assignee' => $andry->id],
            ['title' => 'UAT-02: Tulisan di picture banner terpotong', 'desc' => 'Perlu wording yang lebih ringkas dan responsive.', 'type' => 'bug', 'status' => 'done', 'prio' => 'medium', 'assignee' => $henry->id],
            ['title' => 'UAT-03: Revisi wording "tanpa bayar apapun"', 'desc' => 'Ganti wording benefit promo pada onboarding.', 'type' => 'task', 'status' => 'done', 'prio' => 'low', 'assignee' => $henry->id],
            ['title' => 'UAT-04: Logo header perlu direvisi resolusinya', 'desc' => 'Perbaiki kualitas vektor logo di navbar.', 'type' => 'bug', 'status' => 'done', 'prio' => 'low', 'assignee' => $andry->id],
            ['title' => 'UAT-05: Penjelasan STAR methodology scoring', 'desc' => 'Tambahkan penjelasan metode evaluasi STAR pada laporan AI.', 'type' => 'task', 'status' => 'todo', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-06: Ganti wording "progress" menjadi "insight"', 'desc' => 'Konsistensi istilah pada dashboard recruiter.', 'type' => 'task', 'status' => 'done', 'prio' => 'low', 'assignee' => $henry->id],
            ['title' => 'UAT-07: Penjelasan bridging 200 CV ke 16 shortlist', 'desc' => 'Visual funnel konversi pelamar di UI.', 'type' => 'story', 'status' => 'in_progress', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-08: Revisi estimasi 21-28 jam kerja HR', 'desc' => 'Koreksi perbandingan waktu manual vs AI screening.', 'type' => 'task', 'status' => 'done', 'prio' => 'low', 'assignee' => $henry->id],
            ['title' => 'UAT-09: Sinkronisasi nama & deskripsi paket harga', 'desc' => 'Pastikan harga landing page sama persis dengan tabel New Pricing.', 'type' => 'bug', 'status' => 'done', 'prio' => 'high', 'assignee' => $henry->id],
            ['title' => 'UAT-10: Email verifikasi nomor masuk folder spam', 'desc' => 'Setting SPF, DKIM, DMARC agar email verifikasi masuk ke Inbox utama.', 'type' => 'bug', 'status' => 'in_progress', 'prio' => 'urgent', 'assignee' => $andry->id],
            ['title' => 'UAT-11: Definisi workflow proses verifikasi recruiter', 'desc' => 'Standard operating procedure verifikasi user baru.', 'type' => 'task', 'status' => 'todo', 'prio' => 'medium', 'assignee' => $henry->id],
            ['title' => 'UAT-12: Background navbar & header ganti putih', 'desc' => 'Clean UI standar minimalis modern.', 'type' => 'task', 'status' => 'done', 'prio' => 'low', 'assignee' => $andry->id],
            ['title' => 'UAT-13: Isolasi menu email log antar recruiter', 'desc' => 'Pastikan log email terfilter per-recruiter / company.', 'type' => 'bug', 'status' => 'done', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-14: Konsistensi wording durasi akses (14 hari vs 1 bulan)', 'desc' => 'Standardisasi durasi langganan menjadi 1 bulan.', 'type' => 'bug', 'status' => 'done', 'prio' => 'medium', 'assignee' => $henry->id],
            ['title' => 'UAT-15: Domain email konfirmasi pakai qualiv.id bukan qualiv.ai', 'desc' => 'Update seluruh link konfirmasi ke https://app.qualiv.id.', 'type' => 'bug', 'status' => 'done', 'prio' => 'urgent', 'assignee' => $andry->id],
            ['title' => 'UAT-16: Fitur share job link ke LinkedIn & Jobstreet', 'desc' => 'Generator social share meta tag dan shortlink publik.', 'type' => 'story', 'status' => 'in_progress', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-17: Konfigurasi limit waktu chat interview', 'desc' => '2 soal = 10 min, 3 soal = 15 min, reset interval = 3 min.', 'type' => 'task', 'status' => 'done', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-18: Validasi upload file CV saat submit', 'desc' => 'Cegah kandidat lanjut sebelum file CV tersimpan di storage.', 'type' => 'bug', 'status' => 'done', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-19: Transisi link screening ke tes logic', 'desc' => 'Perbaiki direct redirect agar tidak muncul thank-you page premature.', 'type' => 'bug', 'status' => 'done', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-20: Penyesuaian bobot scoring university tier', 'desc' => 'Kalibrasi rubrik penilaian CV ranking model AI.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-21: Hide nilai ujian mentah dari tampilan pelamar', 'desc' => 'Nilai mentah hanya untuk pertimbangan recruiter.', 'type' => 'task', 'status' => 'done', 'prio' => 'low', 'assignee' => $andry->id],
            ['title' => 'UAT-22: Format ribuan otomatis (titik) pada input gaji', 'desc' => 'Number masking otomatis per 3 digit pada form post job.', 'type' => 'task', 'status' => 'done', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-23: Fitur Draft Job Posting', 'desc' => 'Simpan draft lowongan kerja saat kuota belum cukup/topup.', 'type' => 'story', 'status' => 'in_progress', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-24: Fitur tanya-jawab balik kandidat di video interview', 'desc' => 'Kandidat bisa ajukan 1-2 pertanyaan seputar job di akhir sesi.', 'type' => 'story', 'status' => 'todo', 'prio' => 'medium', 'assignee' => $andry->id],
            ['title' => 'UAT-25: Ekstraksi range gaji yang diinginkan & relocasi di chat', 'desc' => 'AI parsing nominal gaji dan kesediaan relokasi kandidat.', 'type' => 'bug', 'status' => 'done', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-26: Natural voice TTS English interview', 'desc' => 'Tingkatkan kualitas aksen dan intonasi AI untuk video interview bahasa Inggris.', 'type' => 'task', 'status' => 'in_progress', 'prio' => 'high', 'assignee' => $andry->id],
            ['title' => 'UAT-27: Separasi bahasa tes logic (Inggris vs Indonesia)', 'desc' => 'Hindari pencampuran bahasa dalam 1 sesi tes logic.', 'type' => 'bug', 'status' => 'done', 'prio' => 'medium', 'assignee' => $andry->id],

            // Backlog Ideas
            ['title' => 'Idea: Rekomendasi paket yang cocok saat registrasi (Quiz Picker)', 'desc' => 'Rekomendasi paket (Starter/Pro/Enterprise) berdasarkan jumlah hiring per bulan.', 'type' => 'story', 'status' => 'todo', 'prio' => 'low', 'assignee' => $henry->id],
            ['title' => 'Idea: Custom branding & value template khusus customer KAM', 'desc' => 'Template kustomisasi khusus akun enterprise Key Account.', 'type' => 'story', 'status' => 'todo', 'prio' => 'low', 'assignee' => $henry->id],
            ['title' => 'Idea: Fitur latihan interview kandidat & AI matching', 'desc' => 'Mock interview untuk pelamar dan auto-match ke job listing yang sesuai.', 'type' => 'story', 'status' => 'todo', 'prio' => 'low', 'assignee' => $andry->id],
            ['title' => 'Idea: Agent otomatis pencatatan invoice & receipt income', 'desc' => 'Integrasi bot akuntansi untuk rekonsiliasi payment gateway instan.', 'type' => 'story', 'status' => 'todo', 'prio' => 'medium', 'assignee' => $andry->id],
        ];

        $seq = 1;
        foreach ($issues as $item) {
            Issue::query()->updateOrCreate(
                ['project_id' => $project->id, 'title' => $item['title']],
                [
                    'code' => 'QLV-'.$seq,
                    'description' => $item['desc'],
                    'type' => $item['type'],
                    'status' => $item['status'],
                    'priority' => $item['prio'],
                    'assignee_id' => $item['assignee'],
                    'reporter_id' => $andry->id,
                    'due_date' => Carbon::now()->addDays($seq * 3)->toDateString(),
                ]
            );
            $seq++;
        }

        $project->update(['next_issue_seq' => $seq]);
    }

    private function seedSalesPricing(): void
    {
        $priceList = PriceList::query()->updateOrCreate(
            ['name' => 'Qualiv.id Standard Pricing 2026'],
            [
                'currency' => 'IDR',
                'customer_segment' => 'all',
                'effective_from' => '2026-01-01',
                'is_tenant_default' => true,
                'is_active' => true,
            ]
        );

        $packages = [
            ['code' => 'PKG-STARTER', 'name' => 'Starter Package (100 CV, 1 Job, 10 Chat, 15 Logic, 3 Video)', 'price' => 199000],
            ['code' => 'PKG-PRO', 'name' => 'Professional Package (500 CV, 5 Jobs, 50 Chat, 50 Logic, 15 Video)', 'price' => 699000],
            ['code' => 'PKG-ENT', 'name' => 'Enterprise Package (1.200 CV, 25 Jobs, 120 Chat, 100 Logic, 25 Video)', 'price' => 1999000],
            ['code' => 'ADDON-BUNDLE-A', 'name' => 'Add-on Bundle A (100 CV Screening + Job + Chat + Logic)', 'price' => 100000],
            ['code' => 'ADDON-BUNDLE-B', 'name' => 'Add-on Bundle B (25 Video Interviews)', 'price' => 150000],
        ];

        foreach ($packages as $pkg) {
            PriceListLine::query()->updateOrCreate(
                ['price_list_id' => $priceList->id, 'description' => $pkg['name']],
                [
                    'unit_price' => $pkg['price'],
                ]
            );
        }
    }

    private function seedAccountingExpenses(array $users): void
    {
        $company = Company::query()->first();
        if (! $company) {
            $company = Company::create([
                'legal_name' => 'Qualiv AI',
                'base_currency' => 'IDR',
                'fiscal_year_start_month' => 1,
                'is_active' => true,
            ]);
            app(\App\Modules\Accounting\Services\AccountService::class)->seedStarterCoa($company);
        }

        if ($company && \App\Modules\Accounting\Models\FiscalYear::where('company_id', $company->id)->where('year', 2026)->doesntExist()) {
            app(\App\Modules\Accounting\Services\FiscalYearService::class)->create($company->id, 2026, '2026-01-01');
        }

        $spectrum = Partner::query()->where('name', 'Spectrum Darmo')->first();
        $indosmm = Partner::query()->where('trade_name', 'IndoSMM')->first();
        $hostinger = Partner::query()->where('trade_name', 'Hostinger')->first();
        $meta = Partner::query()->where('trade_name', 'Meta Platforms')->first();
        $expenseAccount = Account::query()->where('company_id', $company->id)->where('account_type', Account::TYPE_EXPENSE)->first();

        // Ensure no dummy sales/income (Qualiv hasn't launched yet)
        ArInvoice::query()->where('company_id', $company->id)->delete();

        // Specific expense accounts
        $serverExp = Account::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_code' => '61300'],
            ['account_name' => 'Beban Server VPS (Hostinger)', 'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT, 'is_active' => true]
        );
        $toolsExp = Account::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_code' => '61400'],
            ['account_name' => 'Beban Domain & Software Tools', 'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT, 'is_active' => true]
        );
        $adsExp = Account::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_code' => '61200'],
            ['account_name' => 'Beban Iklan & Social Media', 'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT, 'is_active' => true]
        );
        $printExp = Account::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_code' => '62100'],
            ['account_name' => 'Beban Cetak & Promosi Kantor', 'account_type' => Account::TYPE_EXPENSE, 'normal_balance' => Account::BALANCE_DEBIT, 'is_active' => true]
        );
        $founderPayable = Account::query()->firstOrCreate(
            ['company_id' => $company->id, 'account_code' => '21200'],
            ['account_name' => 'Utang Talangan Pendiri (Henry & Andry)', 'account_type' => Account::TYPE_LIABILITY, 'normal_balance' => Account::BALANCE_CREDIT, 'is_active' => true]
        );

        $bankAcc = Account::query()->where('company_id', $company->id)->where('account_code', '10200')->first() ?? Account::first();
        $user = $users['andry@qualiv.id'] ?? User::first();

        // Bank Account record
        if ($bankAcc) {
            BankAccount::query()->updateOrCreate(
                ['company_id' => $company->id, 'gl_account_id' => $bankAcc->id],
                [
                    'name' => 'BCA Bisnis Qualiv Operasional',
                    'bank_name' => 'Bank Central Asia (BCA)',
                    'account_number' => '8290192831',
                    'account_holder_name' => 'Qualiv AI',
                    'currency_code' => 'IDR',
                    'is_active' => true,
                ]
            );
        }

        $billsData = [
            ['vendor' => $spectrum, 'bill_no' => 'BILL-2026-08-001', 'date' => '2026-08-03', 'desc' => 'Cetak Kartu Nama Qualiv (Spectrum Darmo - Talangan Henry)', 'amount' => 56000.00, 'acc' => $printExp, 'credit_acc' => $founderPayable],
            ['vendor' => $indosmm, 'bill_no' => 'BILL-2026-08-002', 'date' => '2026-08-10', 'desc' => 'Top Up Followers Instagram Qualiv (IndoSMM - Talangan Andry)', 'amount' => 100000.00, 'acc' => $adsExp, 'credit_acc' => $founderPayable],
            ['vendor' => $hostinger, 'bill_no' => 'H_43289679', 'date' => '2026-05-15', 'desc' => 'Domain qualiv.id (.ID Domain Registration 1 Tahun)', 'amount' => 234099.00, 'acc' => $toolsExp, 'credit_acc' => $bankAcc],
            ['vendor' => $hostinger, 'bill_no' => 'H_43289639', 'date' => '2026-05-15', 'desc' => 'Hostinger KVM 4 VPS Server (srv1673152.hstgr.cloud) 1 Tahun', 'amount' => 3262068.00, 'acc' => $serverExp, 'credit_acc' => $bankAcc],
            ['vendor' => $meta, 'bill_no' => 'BILL-META-2026-09', 'date' => '2026-09-29', 'desc' => 'Meta Instagram & FB Ads / Subscription (Start 29 Sep)', 'amount' => 300000.00, 'acc' => $adsExp, 'credit_acc' => $bankAcc],
        ];

        // Delete any dummy bills outside real list
        $realBillNos = array_column($billsData, 'bill_no');
        ApBill::query()->where('company_id', $company->id)->whereNotIn('bill_no', $realBillNos)->delete();

        foreach ($billsData as $index => $b) {
            if (! $b['vendor'] || ! $b['acc']) {
                continue;
            }

            $bill = ApBill::query()->updateOrCreate(
                ['company_id' => $company->id, 'bill_no' => $b['bill_no']],
                [
                    'uuid' => (string) Str::uuid(),
                    'partner_id' => $b['vendor']->id,
                    'currency_code' => 'IDR',
                    'fx_rate' => 1.0,
                    'issue_date' => $b['date'],
                    'due_date' => $b['date'],
                    'status' => ApBill::STATUS_PAID,
                    'subtotal' => $b['amount'],
                    'tax_amount' => 0.00,
                    'total_amount' => $b['amount'],
                    'paid_amount' => $b['amount'],
                    'created_by' => $user?->id,
                ]
            );

            $bill->lines()->delete();
            $bill->lines()->create([
                'line_no' => 1,
                'description' => $b['desc'],
                'qty' => 1,
                'unit_price' => $b['amount'],
                'discount_amount' => 0.00,
                'expense_account_id' => $b['acc']->id,
                'line_amount' => $b['amount'],
                'tax_amount' => 0.00,
            ]);

            // Post into GL Journals so Profit & Loss, Balance Sheet & GL Reports are fully populated
            $period = \App\Modules\Accounting\Models\FiscalPeriod::query()
                ->where('company_id', $company->id)
                ->where('start_date', '<=', $b['date'])
                ->where('end_date', '>=', $b['date'])
                ->first();

            if ($period && $bankAcc) {
                $journal = \App\Modules\Accounting\Models\GlJournal::query()->updateOrCreate(
                    ['company_id' => $company->id, 'memo' => $b['desc']],
                    [
                        'uuid' => (string) Str::uuid(),
                        'fiscal_period_id' => $period->id,
                        'journal_date' => $b['date'],
                        'currency_code' => 'IDR',
                        'memo' => $b['desc'],
                        'source' => 'ap',
                        'subject_type' => 'accounting.ap_bills',
                        'subject_id' => (string) $bill->id,
                        'status' => \App\Modules\Accounting\Models\GlJournal::STATUS_POSTED,
                        'posted_at' => now(),
                        'posted_by' => $user?->id,
                        'created_by' => $user?->id,
                    ]
                );

                $journal->lines()->delete();
                // Debit Expense Account
                $journal->lines()->create([
                    'line_no' => 1,
                    'account_id' => $b['acc']->id,
                    'debit' => $b['amount'],
                    'credit' => 0.00,
                    'description' => $b['desc'],
                ]);
                // Credit Cash / Bank / Talangan Account
                $journal->lines()->create([
                    'line_no' => 2,
                    'account_id' => $b['credit_acc']->id ?? $bankAcc->id,
                    'debit' => 0.00,
                    'credit' => $b['amount'],
                    'description' => 'Pembayaran Kas / Talangan Pendiri',
                ]);
            }
        }
    }
}
