<?php

namespace App\Modules\PP\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PP_SPECS.md §3E — `resource_ref_id` is polymorphic by `resource_type`: informational for the
 * `mes_*` types (MES isn't built yet), and `PP.pp_resources.id` for `pp_resource` — resolved and
 * validated at the app layer, never a DB FK (the column's meaning shifts by type).
 */
class ResourceGroupMember extends Model
{
    protected $table = 'PP.pp_resource_group_members';

    public $timestamps = false;

    public const TYPE_MES_WORK_CENTER = 'mes_work_center';

    public const TYPE_MES_MACHINE = 'mes_machine';

    public const TYPE_MES_STATION = 'mes_station';

    public const TYPE_PP_RESOURCE = 'pp_resource';

    protected $fillable = ['resource_group_id', 'resource_type', 'resource_ref_id'];

    public function group()
    {
        return $this->belongsTo(ResourceGroup::class, 'resource_group_id');
    }

    /** Only meaningful when resource_type === TYPE_PP_RESOURCE — see class docblock. */
    public function ppResource()
    {
        return $this->belongsTo(Resource::class, 'resource_ref_id');
    }
}
