<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\CRM\Models\Partner;
use Illuminate\Database\Eloquent\Model;

class CustomerSalesProfile extends Model
{
    protected $table = 'SALES.customer_sales_profiles';

    protected $fillable = [
        'partner_id',
        'sales_team_id',
        'price_list_id',
        'assigned_rep_id',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    public function salesTeam()
    {
        return $this->belongsTo(SalesTeam::class, 'sales_team_id');
    }

    public function priceList()
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function assignedRep()
    {
        return $this->belongsTo(User::class, 'assigned_rep_id');
    }
}
