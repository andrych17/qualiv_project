<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects module — internal-only JIRA-like project/issue tracker (CLAUDE.md §5/§10:
 * Core-shaped module, but gated to Nusaevo's own internal tenant via the 'internal'
 * plan rather than any sellable tier — see config/tenant_modules.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('PROJECTS.projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            // Per-project issue numbering (PRJ-1, PRJ-2, ...) — incremented under a row
            // lock in IssueService::create() rather than a module-level SYSCONFIG serial,
            // since each project needs its own independent sequence.
            $table->unsignedInteger('next_issue_seq')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('PROJECTS.projects');
    }
};
