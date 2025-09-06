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
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('genre', ['masculin', 'feminin', 'autre'])->nullable();
            $table->enum('user_type', ['patient', 'doctor', 'admin'])->default('patient');
            $table->boolean('is_active')->default(true);
            $table->string('profile_image')->nullable();
            $table->text('bio')->nullable();
            $table->timestamp('email_verified_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'address', 'date_of_birth', 'genre',
                'user_type', 'is_active', 'profile_image', 'bio'
            ]);
        });
    }
};
