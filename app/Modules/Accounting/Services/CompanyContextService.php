<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * §3K — the one place "which company is the user looking at" gets resolved, so
 * every Accounting screen (global top-bar switcher and each page's own
 * company selector alike) agrees on the same session-backed state instead of
 * drifting independently. An explicit ?company_id= always wins (and is
 * persisted for the next request without one — this is how both the header
 * switcher and a page's own selector end up writing the same source of
 * truth); otherwise the session value survives if it's still a company the
 * caller has in front of them; otherwise the first one.
 */
class CompanyContextService
{
    private const SESSION_KEY = 'accounting.current_company_id';

    /** @param  Collection<int, Company>  $companies  the caller's already-scoped, already-ordered active companies */
    public function resolve(Request $request, Collection $companies): ?int
    {
        $explicit = $request->integer('company_id');
        if ($explicit && $companies->contains('id', $explicit)) {
            $request->session()->put(self::SESSION_KEY, $explicit);

            return $explicit;
        }

        $sessionId = (int) $request->session()->get(self::SESSION_KEY);
        if ($sessionId && $companies->contains('id', $sessionId)) {
            return $sessionId;
        }

        return $companies->first()?->id;
    }

    /** @return array{companies: list<array{id:int, legal_name:string}>, currentCompanyId: ?int} */
    public function contextFor(Request $request): array
    {
        $companies = Company::query()->where('is_active', true)->orderBy('legal_name')->get(['id', 'legal_name']);

        return [
            'companies' => $companies->map(fn (Company $c) => ['id' => $c->id, 'legal_name' => $c->legal_name])->all(),
            'currentCompanyId' => $this->resolve($request, $companies),
        ];
    }
}
