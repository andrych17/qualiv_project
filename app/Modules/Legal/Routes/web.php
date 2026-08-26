<?php

use App\Modules\Legal\Controllers\BpnSubmissionController;
use App\Modules\Legal\Controllers\DeedController;
use App\Modules\Legal\Controllers\DeedPartyController;
use App\Modules\Legal\Controllers\DeedTaxController;
use App\Modules\Legal\Controllers\DueDiligenceCheckController;
use App\Modules\Legal\Controllers\FieldVisitController;
use App\Modules\Legal\Controllers\LandObjectController;
use App\Modules\Legal\Controllers\MatterController;
use App\Modules\Legal\Controllers\PpatDeedController;
use App\Modules\Legal\Controllers\ProtocolBookController;
use App\Modules\Legal\Controllers\WillController;
use Illuminate\Support\Facades\Route;

// Bare /legal → index (avoids Laravel 404 if bookmark/link drops /matters)
Route::redirect('/legal', '/legal/matters');

Route::middleware(['auth', 'verified', 'module:LEGAL', 'menu.perm:LEGAL'])
    ->prefix('legal')
    ->name('legal.')
    ->scopeBindings()
    ->group(function () {
        Route::delete('matters/bulk-destroy', [MatterController::class, 'bulkDestroy'])->name('matters.bulkDestroy');
        Route::resource('matters', MatterController::class)
            ->except(['show'])
            ->parameters(['matters' => 'matter']);

        Route::delete('deeds/bulk-destroy', [DeedController::class, 'bulkDestroy'])->name('deeds.bulkDestroy');
        Route::patch('deeds/{deed}/transition', [DeedController::class, 'transition'])->name('deeds.transition');
        Route::resource('deeds', DeedController::class)
            ->except(['show']);

        Route::delete('ppat-deeds/bulk-destroy', [PpatDeedController::class, 'bulkDestroy'])->name('ppatDeeds.bulkDestroy');
        Route::resource('ppat-deeds', PpatDeedController::class)
            ->parameters(['ppat-deeds' => 'deed'])
            ->except(['show']);

        Route::post('deeds/{deed}/parties', [DeedPartyController::class, 'store'])->name('deeds.parties.store');
        Route::put('deeds/{deed}/parties/{party}', [DeedPartyController::class, 'update'])->name('deeds.parties.update');
        Route::delete('deeds/{deed}/parties/{party}', [DeedPartyController::class, 'destroy'])->name('deeds.parties.destroy');

        Route::post('deeds/{deed}/taxes/generate', [DeedTaxController::class, 'generate'])->name('deeds.taxes.generate');
        Route::patch('deeds/{deed}/taxes/{tax}/amounts', [DeedTaxController::class, 'updateAmounts'])->name('deeds.taxes.updateAmounts');
        Route::patch('deeds/{deed}/taxes/{tax}/billing-code', [DeedTaxController::class, 'issueBillingCode'])->name('deeds.taxes.issueBillingCode');
        Route::patch('deeds/{deed}/taxes/{tax}/paid', [DeedTaxController::class, 'markPaid'])->name('deeds.taxes.markPaid');
        Route::patch('deeds/{deed}/taxes/{tax}/validated', [DeedTaxController::class, 'markValidated'])->name('deeds.taxes.markValidated');

        Route::patch('deeds/{deed}/bpn-submissions/{bpnSubmission}/submit', [BpnSubmissionController::class, 'submit'])->name('deeds.bpnSubmissions.submit');
        Route::patch('deeds/{deed}/bpn-submissions/{bpnSubmission}/in-process', [BpnSubmissionController::class, 'markInProcess'])->name('deeds.bpnSubmissions.markInProcess');
        Route::patch('deeds/{deed}/bpn-submissions/{bpnSubmission}/complete', [BpnSubmissionController::class, 'complete'])->name('deeds.bpnSubmissions.complete');
        Route::patch('deeds/{deed}/bpn-submissions/{bpnSubmission}/reject', [BpnSubmissionController::class, 'reject'])->name('deeds.bpnSubmissions.reject');
        Route::post('deeds/{deed}/bpn-submissions/{bpnSubmission}/resubmit', [BpnSubmissionController::class, 'resubmit'])->name('deeds.bpnSubmissions.resubmit');

        Route::post('deeds/{deed}/will', [WillController::class, 'store'])->name('deeds.will.store');
        Route::patch('deeds/{deed}/will/{will}/register-dpw', [WillController::class, 'registerDpw'])->name('deeds.will.registerDpw');
        Route::patch('deeds/{deed}/will/{will}/activate', [WillController::class, 'activate'])->name('deeds.will.activate');
        Route::patch('deeds/{deed}/will/{will}/open', [WillController::class, 'open'])->name('deeds.will.open');
        Route::patch('deeds/{deed}/will/{will}/revoke', [WillController::class, 'revoke'])->name('deeds.will.revoke');

        Route::get('protocol-books/{protocolBook}/manifest', [ProtocolBookController::class, 'manifest'])->name('protocolBooks.manifest');
        Route::patch('protocol-books/{protocolBook}/close', [ProtocolBookController::class, 'close'])->name('protocolBooks.close');
        Route::patch('protocol-books/{protocolBook}/handover', [ProtocolBookController::class, 'handover'])->name('protocolBooks.handover');
        Route::resource('protocol-books', ProtocolBookController::class)
            ->parameters(['protocol-books' => 'protocolBook'])
            ->except(['edit', 'update', 'destroy']);

        Route::delete('land-objects/bulk-destroy', [LandObjectController::class, 'bulkDestroy'])->name('landObjects.bulkDestroy');
        Route::resource('land-objects', LandObjectController::class)
            ->parameters(['land-objects' => 'landObject'])
            ->except(['show']);

        Route::post('land-objects/{landObject}/checks', [DueDiligenceCheckController::class, 'store'])->name('landObjects.checks.store');
        Route::patch('land-objects/{landObject}/checks/{check}/result', [DueDiligenceCheckController::class, 'recordResult'])->name('landObjects.checks.recordResult');
        Route::patch('land-objects/{landObject}/checks/{check}/override', [DueDiligenceCheckController::class, 'override'])->name('landObjects.checks.override');

        Route::delete('field-visits/bulk-destroy', [FieldVisitController::class, 'bulkDestroy'])->name('fieldVisits.bulkDestroy');
        Route::patch('field-visits/{fieldVisit}/check-in', [FieldVisitController::class, 'checkIn'])->name('fieldVisits.checkIn');
        Route::patch('field-visits/{fieldVisit}/complete', [FieldVisitController::class, 'complete'])->name('fieldVisits.complete');
        Route::resource('field-visits', FieldVisitController::class)
            ->parameters(['field-visits' => 'fieldVisit'])
            ->except(['show']);
    });
