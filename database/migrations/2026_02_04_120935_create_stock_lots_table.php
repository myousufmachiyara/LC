<?php
// database/migrations/xxxx_xx_xx_create_stock_lots_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->string('lot_number');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->timestamps();
            
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variation_id')->references('id')->on('product_variations')->onDelete('set null');
            
            $table->unique(['location_id', 'product_id', 'variation_id', 'lot_number'], 'stock_lots_unique');
            $table->index(['location_id', 'product_id', 'lot_number']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stock_lots');
    }
};