<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the thesis condition "interactive" (personalized training with structured data for
 * the interactive modules and the Module 4 tutor) to users.training_personalization.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users
            MODIFY training_personalization
            ENUM('no', 'yes', 'primed', 'few_shot', 'table', 'interactive')
            NOT NULL DEFAULT 'no'");
    }

    public function down(): void
    {
        // Same fallback as the previous enum migration: unknown conditions become 'yes'.
        DB::table('users')
            ->where('training_personalization', 'interactive')
            ->update(['training_personalization' => 'yes']);
        DB::statement("ALTER TABLE users
            MODIFY training_personalization
            ENUM('no', 'yes', 'primed', 'few_shot', 'table')
            NOT NULL DEFAULT 'no'");
    }
};
