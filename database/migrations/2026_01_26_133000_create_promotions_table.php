<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $軟) {
            $軟->id();
            $軟->string('title');
            $軟->text('description')->nullable();
            $軟->string('image_url');
            $軟->string('link_url')->nullable();
            $軟->boolean('is_active')->default(true);
            $軟->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
