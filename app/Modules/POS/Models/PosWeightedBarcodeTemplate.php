<?php

namespace App\Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * POS_SPECS.md §3E / §4 — Scale-Labeled Produce & Price Embedded Barcode Template.
 */
class PosWeightedBarcodeTemplate extends Model
{
    protected $table = 'POS.pos_weighted_barcode_templates';

    public $timestamps = false;

    public const VALUE_TYPE_WEIGHT = 'weight';

    public const VALUE_TYPE_PRICE = 'price';

    protected $fillable = [
        'name',
        'prefix_from',
        'prefix_to',
        'item_code_start',
        'item_code_length',
        'value_start',
        'value_length',
        'value_type',
        'decimal_places',
        'is_active',
    ];

    protected $casts = [
        'item_code_start' => 'integer',
        'item_code_length' => 'integer',
        'value_start' => 'integer',
        'value_length' => 'integer',
        'decimal_places' => 'integer',
        'is_active' => 'boolean',
    ];
}
