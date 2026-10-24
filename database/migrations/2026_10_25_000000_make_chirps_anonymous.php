<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chirper becomes an anonymous public board (ADR 0008). Chirps no longer belong to
 * users; ownership is a hashed per-browser token. Existing chirps belonged to accounts
 * that no longer exist, so they're cleared. Account-only tables from the v1-with-auth
 * version are dropped if a database still has them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sent_chirp_notifications');
        Schema::dropIfExists('passkeys');

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        DB::table('chirps')->delete();

        Schema::table('chirps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->char('author_token_hash', 64)->index();
        });
    }

    public function down(): void
    {
        DB::table('chirps')->delete();

        Schema::table('chirps', function (Blueprint $table) {
            $table->dropIndex(['author_token_hash']);
            $table->dropColumn('author_token_hash');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        });
    }
};
