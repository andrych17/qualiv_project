<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

class AuditRoutesCommand extends Command
{
    protected $signature = 'erp:audit-routes {tenant=qualiv}';
    protected $description = 'Audit all active routes and detect broken pages or missing dependencies';

    public function handle(): int
    {
        $tenantId = $this->argument('tenant');
        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            $this->error("Tenant {$tenantId} not found.");
            return 1;
        }

        tenancy()->initialize($tenant);
        $user = User::where('email', 'like', '%@%')->first();

        if (!$user) {
            $this->error("No user found in tenant {$tenantId}.");
            return 1;
        }

        auth()->login($user);
        $this->info("Auditing routes as {$user->email} on tenant {$tenantId}...");

        $routes = [
            '/dashboard',
            '/projects',
            '/projects/create',
            '/accounting/ar-invoices',
            '/accounting/ar-invoices/create',
            '/accounting/ar-payments',
            '/accounting/ar-payments/create',
            '/accounting/ap-bills',
            '/accounting/ap-bills/create',
            '/accounting/ap-payments',
            '/accounting/ap-payments/create',
            '/accounting/bank-accounts',
            '/accounting/bank-accounts/create',
            '/accounting/cash-transfers/create',
            '/accounting/recurring-journal-templates',
            '/accounting/recurring-journal-templates/create',
            '/accounting/accounts',
            '/accounting/accounts/create',
            '/accounting/journals',
            '/accounting/journals/create',
            '/accounting/general-ledger',
            '/accounting/tax-periods',
            '/accounting/tax-periods/create',
            '/accounting/fiscal-years',
            '/accounting/fiscal-years/create',
            '/accounting/reports',
            '/accounting/reports/profit-loss',
            '/accounting/reports/balance-sheet',
            '/accounting/reports/cash-flow',
            '/accounting/reports/trial-balance',
            '/accounting/reports/budget-vs-actual',
            '/accounting/ar-aging',
            '/accounting/ap-aging',
            '/accounting/control-reconciliation',
            '/accounting/audit-log',
            '/config/users',
            '/config/groups',
            '/config/menus',
            '/config/theme',
        ];

        $kernel = app(Kernel::class);
        $failed = 0;

        foreach ($routes as $uri) {
            try {
                $req = Request::create($uri, 'GET');
                $res = $kernel->handle($req);
                $status = $res->getStatusCode();

                if ($status >= 400) {
                    $this->error("[FAIL {$status}] {$uri}");
                    $failed++;
                } else {
                    $this->line("<info>[OK {$status}]</info> {$uri}");
                }

                $kernel->terminate($req, $res);
            } catch (\Throwable $e) {
                $this->error("[EXCEPTION] {$uri}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");
                $failed++;
            }
        }

        $total = count($routes);
        $passed = $total - $failed;

        $this->newLine();
        if ($failed === 0) {
            $this->info("SUCCESS: All {$total} routes passed without error.");
            return 0;
        } else {
            $this->error("FAILED: {$failed}/{$total} routes had errors.");
            return 1;
        }
    }
}
