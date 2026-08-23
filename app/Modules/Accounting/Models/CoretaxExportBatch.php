<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** §3M — a log row per generated Coretax XML batch (for traceability), not the file itself (that's in object storage). */
class CoretaxExportBatch extends Model
{
    protected $table = 'ACCOUNTING.tax_coretax_export_batches';

    public $timestamps = false;

    public const TYPE_FAKTUR_KELUARAN = 'faktur_keluaran';

    public const TYPE_FAKTUR_MASUKAN = 'faktur_masukan';

    public const TYPE_BUKTI_POTONG = 'bukti_potong';

    public const TYPES = [self::TYPE_FAKTUR_KELUARAN, self::TYPE_FAKTUR_MASUKAN, self::TYPE_BUKTI_POTONG];

    protected $fillable = ['company_id', 'batch_type', 'tax_period_id', 'object_key', 'record_count', 'generated_by', 'generated_at'];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function taxPeriod()
    {
        return $this->belongsTo(TaxPeriod::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
