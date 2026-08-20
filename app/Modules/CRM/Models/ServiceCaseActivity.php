<?php

namespace App\Modules\CRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ServiceCaseActivity extends Model
{
    protected $table = 'CRM.svc_case_activities';

    public $timestamps = false;

    protected $fillable = ['case_id', 'activity_type', 'body', 'logged_by', 'logged_at'];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function loggedBy()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
