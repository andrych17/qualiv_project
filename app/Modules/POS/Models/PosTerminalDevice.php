<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * POS_SPECS.md §3Q / §4 — POS Terminal Device Configuration.
 */
class PosTerminalDevice extends Model
{
    protected $table = 'POS.pos_terminal_devices';
    public $timestamps = false;

    public const TYPE_RECEIPT_PRINTER = 'receipt_printer';
    public const TYPE_KITCHEN_PRINTER = 'kitchen_printer';
    public const TYPE_CASH_DRAWER = 'cash_drawer';
    public const TYPE_CUSTOMER_DISPLAY = 'customer_display';
    public const TYPE_WEIGHING_SCALE = 'weighing_scale';
    public const TYPE_CARD_TERMINAL = 'card_terminal';

    protected $fillable = [
        'terminal_id',
        'device_type',
        'adapter_code',
        'connection_config',
        'is_active',
    ];

    protected $casts = [
        'connection_config' => 'array',
        'is_active' => 'boolean',
    ];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
