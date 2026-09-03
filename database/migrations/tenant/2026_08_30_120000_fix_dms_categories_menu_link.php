<?php

use App\Modules\SysConfig\Models\ConfigMenu;
use Illuminate\Database\Migrations\Migration;

// DMS_CATEGORIES was seeded pointing at /dms/categories (2026_08_29_113000), but "categories"
// in DMS are Folders — FolderController's routes live at dms.folders.* -> /dms/folders (see
// DMS/Routes/web.php's "§3D Folder / Category Management" comment). Same class of typo'd-link
// bug as CRM_CUSTOMERS/SALES_PROFILES; code/caption were already right, only the link was
// wrong, so an in-place update is enough — no rights to re-seed.
return new class extends Migration
{
    private const APP = 'NUSAEVO';

    public function up(): void
    {
        ConfigMenu::query()
            ->where(['app_code' => self::APP, 'code' => 'DMS_CATEGORIES'])
            ->update(['menu_link' => '/dms/folders']);
    }

    public function down(): void
    {
        // Keep non-destructive
    }
};
