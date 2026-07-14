<?php
// ponytail: Standard simple migration
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_category_id')->constrained('inventory_categories')->onDelete('cascade');
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('stock')->default(0);
            $table->integer('minimum_stock')->default(0);
            $table->string('unit', 30);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
