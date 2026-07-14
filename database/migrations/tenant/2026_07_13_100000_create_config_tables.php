<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core SysConfig (netapp1-shaped) — lives in tenant DB schema SYSCONFIG.
 * Skip config_users (use users) and config_appls (single app for now).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SYSCONFIG.config_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('app_code')->default('NUSAEVO');
            $table->text('descr')->nullable();
            $table->string('status_code', 5)->default('A');
            $table->timestamps();

            $table->unique(['app_code', 'code']);
        });

        Schema::create('SYSCONFIG.config_grpusers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->string('group_code');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('SYSCONFIG.config_menus', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('app_code')->default('NUSAEVO');
            $table->string('menu_header')->nullable();
            $table->string('menu_caption');
            $table->string('menu_link')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('seq')->default(0);
            $table->string('status_code', 5)->default('A');
            $table->timestamps();

            $table->unique(['app_code', 'code']);
            $table->index('parent_id');
        });

        Schema::create('SYSCONFIG.config_rights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('menu_id');
            $table->string('group_code');
            $table->string('menu_code');
            $table->integer('menu_seq')->default(0);
            $table->string('trustee', 8)->default('R');
            $table->string('app_code')->default('NUSAEVO');
            $table->timestamps();

            $table->unique(['group_id', 'menu_id']);
            $table->index(['app_code', 'group_code']);
        });

        Schema::create('SYSCONFIG.config_consts', function (Blueprint $table) {
            $table->id();
            $table->string('const_group')->nullable();
            $table->string('group_code')->nullable();
            $table->integer('seq')->default(0);
            $table->string('str1')->nullable();
            $table->string('str2')->nullable();
            $table->decimal('num1', 18, 4)->nullable();
            $table->decimal('num2', 18, 4)->nullable();
            $table->text('note1')->nullable();
            $table->timestamps();

            $table->index(['const_group', 'group_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SYSCONFIG.config_consts');
        Schema::dropIfExists('SYSCONFIG.config_rights');
        Schema::dropIfExists('SYSCONFIG.config_menus');
        Schema::dropIfExists('SYSCONFIG.config_grpusers');
        Schema::dropIfExists('SYSCONFIG.config_groups');
    }
};
