<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeadActivity extends Model
{
    protected $table = 'CRM.lead_activities';

    public $timestamps = false;

    protected $fillable = ['lead_id', 'activity_type', 'body', 'logged_by', 'logged_at'];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
