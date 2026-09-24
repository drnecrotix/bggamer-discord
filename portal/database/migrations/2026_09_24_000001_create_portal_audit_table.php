<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_audit', function (Blueprint $table) {
            $table->id();
            $table->string('actor_id', 20);
            $table->string('action', 80);
            $table->string('subject_id', 30)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_audit');
    }
};
