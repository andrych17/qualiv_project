<?php

namespace App\Modules\WNE\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class WrkflowVersion extends Model
{
    protected $table = 'WNE.wrkflow_versions';

    public $timestamps = false;

    protected $fillable = ['definition_id', 'version_no', 'published_at', 'published_by'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function definition()
    {
        return $this->belongsTo(WrkflowDefinition::class, 'definition_id');
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function steps()
    {
        return $this->hasMany(WrkflowStep::class, 'version_id');
    }

    public function entryStep()
    {
        return $this->hasOne(WrkflowStep::class, 'version_id')->where('is_entry_step', true);
    }
}
