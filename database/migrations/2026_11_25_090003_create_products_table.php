<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->char('kode_barang', 8)->unique();
            $table->string('nama_barang', 150)->index();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('dept', 10)->nullable()->index();
            $table->string('barcode', 30)->nullable()->unique();
            $table->unsignedInteger('harga')->default(0);
            $table->unsignedInteger('qty')->default(0)->index();
            $table->integer('qty_asli')->nullable();
            $table->boolean('needs_stock_check')->default(false)->index();
            $table->string('satuan', 30)->default('pcs');
            $table->unsignedInteger('min_stock')->default(5);
            $table->string('lokasi_rak', 50)->nullable();
            $table->text('deskripsi')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
