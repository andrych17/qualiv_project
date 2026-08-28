<?php

use App\Modules\Performance\Controllers\AchievementController;
use App\Modules\Performance\Controllers\BadgeDefinitionController;
use App\Modules\Performance\Controllers\BudgetActualController;
use App\Modules\Performance\Controllers\BudgetCategoryAccountController;
use App\Modules\Performance\Controllers\BudgetController;
use App\Modules\Performance\Controllers\DashboardController;
use App\Modules\Performance\Controllers\ForecastController;
use App\Modules\Performance\Controllers\KpiDefinitionController;
use App\Modules\Performance\Controllers\KpiValueController;
use App\Modules\Performance\Controllers\OkrCycleController;
use App\Modules\Performance\Controllers\OkrObjectiveController;
use App\Modules\Performance\Controllers\PeriodController;
use App\Modules\Performance\Controllers\PerspectiveController;
use App\Modules\Performance\Controllers\ScorecardController;
use App\Modules\Performance\Controllers\TargetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/performance', '/performance/dashboard');

Route::middleware(['auth', 'verified', 'module:PERFORMANCE', 'menu.perm:PERFORMANCE'])
    ->prefix('performance')
    ->name('performance.')
    ->group(function () {
        // §3A Main Dashboard — read-only aggregate over every other §3 engine below; no tables of its own.
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // §3C Targets & KPI Setup — perspectives and periods are the shared master data
        // (§4) every other Performance engine (Budgeting/Forecast/OKR/Scorecard) also reads from.
        Route::delete('perspectives/bulk-destroy', [PerspectiveController::class, 'bulkDestroy'])->name('perspectives.bulkDestroy');
        Route::resource('perspectives', PerspectiveController::class)->except(['show']);

        Route::delete('periods/bulk-destroy', [PeriodController::class, 'bulkDestroy'])->name('periods.bulkDestroy');
        Route::resource('periods', PeriodController::class)->except(['show']);

        Route::delete('kpi-definitions/bulk-destroy', [KpiDefinitionController::class, 'bulkDestroy'])->name('kpiDefinitions.bulkDestroy');
        Route::resource('kpi-definitions', KpiDefinitionController::class)->except(['show'])->names('kpiDefinitions');

        Route::resource('targets', TargetController::class)->except(['show']);

        // §3D KPI Actuals Capture — MVP manual entry; KpiValueService dispatches
        // KpiValueRecorded, currently listener-less until the Variance Analysis Engine (§3G) ships.
        Route::resource('kpi-values', KpiValueController::class)->except(['show'])->names('kpiValues');

        // §3B Budgeting — draft-only mutability; submit/approve/lock walk the status ladder,
        // new-version is the only way to revise a submitted/approved/locked budget.
        Route::resource('budgets', BudgetController::class)->except(['show']);
        Route::patch('budgets/{budget}/submit', [BudgetController::class, 'submit'])->name('budgets.submit');
        Route::patch('budgets/{budget}/approve', [BudgetController::class, 'approve'])->name('budgets.approve');
        Route::patch('budgets/{budget}/lock', [BudgetController::class, 'lock'])->name('budgets.lock');
        Route::post('budgets/{budget}/new-version', [BudgetController::class, 'newVersion'])->name('budgets.newVersion');

        // §3B — manual actual entry for a budget line not covered by a category → GL mapping.
        Route::post('budget-lines/{budgetLine}/actual', [BudgetActualController::class, 'store'])->name('budgetLines.actual.store');

        // §3B — tenant-editable category → GL account mapping (optional, additive).
        Route::delete('budget-category-accounts/bulk-destroy', [BudgetCategoryAccountController::class, 'bulkDestroy'])->name('budgetCategoryAccounts.bulkDestroy');
        Route::resource('budget-category-accounts', BudgetCategoryAccountController::class)->except(['show'])->names('budgetCategoryAccounts');

        // §3H Forecast — immutable once created; "revise" always creates a new version rather
        // than an update, so there's no PUT route.
        Route::resource('forecasts', ForecastController::class)->except(['show', 'update']);
        Route::get('forecasts/{forecast}/revise', [ForecastController::class, 'reviseForm'])->name('forecasts.revise.form');
        Route::post('forecasts/{forecast}/revise', [ForecastController::class, 'revise'])->name('forecasts.revise');

        // §3E OKR Management — cycles are tenant-editable master data; objectives carry Board/List/Alignment views.
        Route::delete('okr-cycles/bulk-destroy', [OkrCycleController::class, 'bulkDestroy'])->name('okrCycles.bulkDestroy');
        Route::resource('okr-cycles', OkrCycleController::class)->except(['show'])->names('okrCycles');

        Route::resource('okr-objectives', OkrObjectiveController::class)->except(['show'])->names('okrObjectives');
        Route::patch('okr-objectives/{okrObjective}/status', [OkrObjectiveController::class, 'updateStatus'])->name('okrObjectives.updateStatus');

        // §3F Scorecard — the one Performance resource that keeps `show` (Viewer) distinct from
        // `edit` (Builder); see ScorecardController's own docblock.
        Route::resource('scorecards', ScorecardController::class);

        // §3I Achievements Engine — badge definitions are tenant-editable master data;
        // achievements is an append-only log (auto-awarded by listeners, or manually via store).
        Route::delete('badge-definitions/bulk-destroy', [BadgeDefinitionController::class, 'bulkDestroy'])->name('badgeDefinitions.bulkDestroy');
        Route::resource('badge-definitions', BadgeDefinitionController::class)->except(['show'])->names('badgeDefinitions');

        Route::resource('achievements', AchievementController::class)->only(['index', 'store']);
    });
