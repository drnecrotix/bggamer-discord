<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('discord_ban_appeals')) { return; }
        Schema::create('discord_ban_appeals', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 32)->unique();
            $table->unsignedBigInteger('ban_id')->nullable();
            $table->string('discord_user_id', 32)->index();
            $table->string('discord_username', 80);
            $table->string('contact', 190);
            $table->string('appeal_reason', 190);
            $table->text('additional_information');
            $table->string('attachment_path')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('moderator_response')->nullable();
            $table->string('reviewed_by', 120)->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // An existing legacy table may predate this migration. Never drop user appeals here.
    }
};
