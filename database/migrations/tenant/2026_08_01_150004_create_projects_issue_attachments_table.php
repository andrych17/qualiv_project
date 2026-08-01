<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PROJECTS.issue_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('issue_id')->constrained('PROJECTS.issues')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            // Path relative to the netadm SFTP disk root (/var/www/storage/nusaevo-erp):
            // "{tenant_id}/projects/{project_id}/{issue_id}/{uuid}.{ext}" — see IssueService::attachFile().
            $table->string('storage_key');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PROJECTS.issue_attachments');
    }
};
