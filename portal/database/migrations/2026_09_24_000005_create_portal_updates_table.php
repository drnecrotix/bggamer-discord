<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('portal_updates', function (Blueprint $table) {
            $table->id();
            $table->string('version', 80);
            $table->string('digest', 71);
            $table->string('actor_id', 20);
            $table->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('portal_updates'); }
};
