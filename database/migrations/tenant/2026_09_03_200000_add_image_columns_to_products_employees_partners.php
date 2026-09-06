<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('INVENTORY.products')) {
            Schema::table('INVENTORY.products', function (Blueprint $table) {
                if (! Schema::hasColumn('INVENTORY.products', 'image_url')) {
                    $table->text('image_url')->nullable();
                }
            });
        }

        if (Schema::hasTable('HCM.employees')) {
            Schema::table('HCM.employees', function (Blueprint $table) {
                if (! Schema::hasColumn('HCM.employees', 'avatar_url')) {
                    $table->text('avatar_url')->nullable();
                }
            });
        }

        if (Schema::hasTable('CRM.partners')) {
            Schema::table('CRM.partners', function (Blueprint $table) {
                if (! Schema::hasColumn('CRM.partners', 'logo_url')) {
                    $table->text('logo_url')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('INVENTORY.products') && Schema::hasColumn('INVENTORY.products', 'image_url')) {
            Schema::table('INVENTORY.products', function (Blueprint $table) {
                $table->dropColumn('image_url');
            });
        }

        if (Schema::hasTable('HCM.employees') && Schema::hasColumn('HCM.employees', 'avatar_url')) {
            Schema::table('HCM.employees', function (Blueprint $table) {
                $table->dropColumn('avatar_url');
            });
        }

        if (Schema::hasTable('CRM.partners') && Schema::hasColumn('CRM.partners', 'logo_url')) {
            Schema::table('CRM.partners', function (Blueprint $table) {
                $table->dropColumn('logo_url');
            });
        }
    }
};
