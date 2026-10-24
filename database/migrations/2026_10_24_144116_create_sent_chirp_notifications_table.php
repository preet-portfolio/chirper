<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (chirp, recipient) whose NewChirp email was handed to the mailer (ADR 0007).
     */
    public function up(): void
    {
        Schema::create('sent_chirp_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chirp_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');

            $table->unique(['chirp_id', 'user_id']);
            $table->index('user_id'); // the unique index leads with chirp_id; user deletes cascade by user_id
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sent_chirp_notifications');
    }
};
