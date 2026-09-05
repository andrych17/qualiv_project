<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TenantUserLookup;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\ApBill;
use App\Modules\Accounting\Models\Company;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\CRM\Models\ContactPoint;
use App\Modules\CRM\Models\Partner;
use App\Modules\CRM\Models\PartnerRole;
use App\Modules\CRM\Models\PartnerRoleType;
use App\Modules\Projects\Models\Issue;
use App\Modules\Projects\Models\Project;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListLine;
use App\Modules\SysConfig\Models\ConfigGroup;
use App\Modules\SysConfig\Models\ConfigRight;
use App\Modules\SysConfig\Models\ConfigUserGroup;
use App\Modules\SysConfig\Models\ConfigUserRight;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'name' => 'Qualiv.id',
            'plan' => 'enterprise',
        ]);

        // Central User Lookups
        $userAccounts = [
            ['email' => 'andryhuang@qualiv.id', 'name' => 'Andry Huang'],
            ['email' => 'andry@qualiv.id', 'name' => 'Andry Huang'],
            ['email' => 'henry.sebastian@qualiv.id', 'name' => 'Henry Sebastian'],
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
            $this->seedAccountingExpenses();
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
                    'password' => Hash::make('password123'),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            $createdUsers[$acc['email']] = $user;

            // Grant Admin Group
            if ($adminGroup) {
                \App\Modules\SysConfig\Models\ConfigGroupUser::query()->updateOrCreate(
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

        // Bank of Sampoerna
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

        // Knitto MERR
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

        // Spectrum Darmo (Vendor)
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

        // IndoSMM (Vendor)
        $indosmm = Partner::query()->updateOrCreate(
            ['name' => 'IndoSMM'],
            [
                'type' => Partner::TYPE_ORGANIZATION,
                'trade_name' => 'IndoSMM Platform',
                'is_active' => true,
                'source' => 'Vendor Marketing',
            ]
        );
        PartnerRole::query()->updateOrCreate([
            'partner_id' => $indosmm->id,
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

    private function seedAccountingExpenses(): void
    {
        $company = Company::query()->first();
        if (! $company) {
            $company = Company::create([
                'legal_name' => 'PT Qualiv Integra Indonesia',
                'base_currency' => 'IDR',
                'fiscal_year_start_month' => 1,
                'is_active' => true,
            ]);
            app(\App\Modules\Accounting\Services\AccountService::class)->seedStarterCoa($company);
        }

        $spectrum = Partner::query()->where('name', 'Spectrum Darmo')->first();
        $indosmm = Partner::query()->where('name', 'IndoSMM')->first();
        $expenseAccount = Account::query()->where('company_id', $company->id)->where('account_type', Account::TYPE_EXPENSE)->first();

        // 1. Spectrum Darmo Bill
        if ($spectrum && $expenseAccount) {
            ApBill::query()->updateOrCreate(
                ['company_id' => $company->id, 'bill_no' => 'BILL-2026-08-001'],
                [
                    'uuid' => (string) Str::uuid(),
                    'partner_id' => $spectrum->id,
                    'currency_code' => 'IDR',
                    'fx_rate' => 1.0,
                    'issue_date' => '2026-08-03',
                    'due_date' => '2026-08-03',
                    'status' => ApBill::STATUS_PAID,
                    'subtotal' => 56000,
                    'tax_amount' => 0,
                    'total_amount' => 56000,
                    'paid_amount' => 56000,
                ]
            );
        }

        // 2. IndoSMM Bill
        if ($indosmm && $expenseAccount) {
            ApBill::query()->updateOrCreate(
                ['company_id' => $company->id, 'bill_no' => 'BILL-2026-08-002'],
                [
                    'uuid' => (string) Str::uuid(),
                    'partner_id' => $indosmm->id,
                    'currency_code' => 'IDR',
                    'fx_rate' => 1.0,
                    'issue_date' => '2026-08-10',
                    'due_date' => '2026-08-10',
                    'status' => ApBill::STATUS_PAID,
                    'subtotal' => 100000,
                    'tax_amount' => 0,
                    'total_amount' => 100000,
                    'paid_amount' => 100000,
                ]
            );
        }
    }
}
