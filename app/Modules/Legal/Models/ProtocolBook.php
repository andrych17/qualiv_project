<?php

namespace App\Modules\Legal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProtocolBook extends Model
{
    protected $table = 'LEGAL.protocol_books';

    public const TYPE_REPERTORIUM = 'repertorium';

    public const TYPE_LEGALISASI = 'legalisasi';

    public const TYPE_WAARMERKING = 'waarmerking';

    public const TYPE_PROTES = 'protes';

    public const TYPE_DAFTAR_WASIAT = 'daftar_wasiat';

    public const TYPE_LAIN_LAIN = 'lain_lain';

    public const TYPES = [
        self::TYPE_REPERTORIUM, self::TYPE_LEGALISASI, self::TYPE_WAARMERKING,
        self::TYPE_PROTES, self::TYPE_DAFTAR_WASIAT, self::TYPE_LAIN_LAIN,
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_HANDED_OVER = 'handed_over';

    protected $fillable = [
        'book_type', 'year', 'volume', 'status', 'notary_user_id',
        'opened_at', 'closed_at', 'handed_over_to', 'handed_over_at',
    ];

    protected $casts = [
        'opened_at' => 'date',
        'closed_at' => 'date',
        'handed_over_at' => 'date',
    ];

    public function notary()
    {
        return $this->belongsTo(User::class, 'notary_user_id');
    }

    public function entries()
    {
        return $this->hasMany(ProtocolEntry::class, 'book_id')->orderBy('sequence_number');
    }

    /** Short code used inside a deed_number, e.g. "12/Rep/2026". */
    public function abbreviation(): string
    {
        return match ($this->book_type) {
            self::TYPE_REPERTORIUM => 'Rep',
            self::TYPE_LEGALISASI => 'Leg',
            self::TYPE_WAARMERKING => 'Wmk',
            self::TYPE_PROTES => 'Prt',
            self::TYPE_DAFTAR_WASIAT => 'DW',
            default => 'LL',
        };
    }
}
