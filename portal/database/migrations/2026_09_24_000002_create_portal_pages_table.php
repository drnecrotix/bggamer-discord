<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_pages', function (Blueprint $table) {
            $table->string('slug')->primary();
            $table->string('title', 120);
            $table->string('subtitle', 240);
            $table->text('body');
            $table->string('cta_label', 80);
            $table->string('cta_url', 500);
            $table->string('updated_by', 20)->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('portal_pages'); }
};
