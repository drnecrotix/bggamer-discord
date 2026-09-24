<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('discord_portal_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('guild_id', 20);
            $table->string('invite_code', 64)->nullable();
            $table->string('updated_by', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_portal_settings');
    }
};
