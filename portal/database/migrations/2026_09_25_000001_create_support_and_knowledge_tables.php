<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('email', 255);
            $table->string('discord_user_id', 20)->nullable();
            $table->string('subject', 160);
            $table->text('message');
            $table->text('reply')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
        });
        Schema::create('knowledge_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12)->index();
            $table->string('title', 160);
            $table->text('body');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_items');
        Schema::dropIfExists('support_tickets');
    }
};
