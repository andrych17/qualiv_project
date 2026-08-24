<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3G — sale/write-off of one asset. One row per asset (unique asset_id). */
class AssetDisposal extends Model
{
    protected $table = 'ACCOUNTING.fa_disposals';

    protected $fillable = [
        'asset_id', 'disposal_date', 'proceeds', 'proceeds_gl_account_id', 'gain_loss_gl_account_id',
        'commercial_nbv_at_disposal', 'fiscal_nbv_at_disposal', 'gain_loss_amount', 'notes', 'journal_id', 'created_by',
    ];

    protected $casts = [
        'disposal_date' => 'date',
        'proceeds' => 'decimal:2',
        'commercial_nbv_at_disposal' => 'decimal:2',
        'fiscal_nbv_at_disposal' => 'decimal:2',
        'gain_loss_amount' => 'decimal:2',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'journal_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
