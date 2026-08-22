<?php

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * §3F — append-only, no update/delete permitted at the app layer. Same guard shape as
 * DMS.access_logs (DMS\Models\AccessLog) — an Eloquent-level guard, not a DB REVOKE, since
 * CreateModuleSchemas grants ALL PRIVILEGES schema-wide.
 */
class ProtocolEntry extends Model
{
    protected $table = 'LEGAL.protocol_entries';

    public $timestamps = false;

    protected $fillable = ['book_id', 'deed_id', 'sequence_number', 'entry_date', 'created_at'];

    protected $casts = [
        'entry_date' => 'date',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('LEGAL.protocol_entries is append-only — rows cannot be updated at the app layer.');
        });
    }

    public function delete()
    {
        throw new LogicException('LEGAL.protocol_entries is append-only — rows cannot be deleted at the app layer.');
    }

    public function book()
    {
        return $this->belongsTo(ProtocolBook::class, 'book_id');
    }

    public function deed()
    {
        return $this->belongsTo(Deed::class);
    }
}
