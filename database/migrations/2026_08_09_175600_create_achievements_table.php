<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();

            // Stable machine-readable identifier
            $table->string('code')->unique();

            // Display information
            $table->string('name');
            $table->text('description')->nullable();

            // Examples: onboarding, accuracy, speed, streak, leaderboard
            $table->string('category');

            // badge, medal, or award
            $table->string('type');

            // Bronze, Silver, Gold, Platinum, Diamond, etc.
            $table->string('tier')->nullable();

            // once, attempt, daily, weekly, monthly, annual, lifetime
            $table->string('scope')->default('once');

            $table->boolean('repeatable')->default(false);
            $table->boolean('progressive')->default(false);

            $table->unsignedInteger('display_order')->default(0);
            $table->string('icon_path')->nullable();

            // Configurable thresholds and rule settings
            $table->json('requirements')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->index(['category', 'type']);
            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};