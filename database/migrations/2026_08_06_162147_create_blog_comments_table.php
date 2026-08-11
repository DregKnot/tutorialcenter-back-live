<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blog_comments', function (Blueprint $table) {

            $table->id();

            $table->foreignId('blog_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
    |--------------------------------------------------------------------------
    | Commenter
    |--------------------------------------------------------------------------
    */

            $table->nullableMorphs('commenter');

            /*
    |--------------------------------------------------------------------------
    | Guest Information
    |--------------------------------------------------------------------------
    */

            $table->string('guest_name')->nullable();

            $table->string('guest_email')->nullable();

            $table->string('guest_website')->nullable();

            /*
    |--------------------------------------------------------------------------
    | Comment
    |--------------------------------------------------------------------------
    */

            $table->longText('comment');

            $table->enum('status', [
                'pending',
                'approved',
                'spam',
                'rejected',
            ])->default('pending');

            $table->string('ip_address', 45)->nullable();

            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
    }
};
