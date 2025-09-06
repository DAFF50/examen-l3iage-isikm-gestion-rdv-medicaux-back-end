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
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('specialty_id')->constrained()->onDelete('cascade');
            $table->string('license_number')->unique();
            $table->integer('experience_years')->default(0);
            $table->decimal('consultation_fee', 10, 2);
            $table->string('clinic_name');
            $table->string('clinic_address');
            $table->json('working_days'); // ['monday', 'tuesday', ...]
            $table->time('working_start_time');
            $table->time('working_end_time');
            $table->integer('appointment_duration')->default(30); // en minutes
            $table->text('qualifications')->nullable();
            $table->decimal('rating', 3, 2)->default(0);
            $table->integer('total_reviews')->default(0);
            $table->boolean('is_verified')->default(false);
            $table->boolean('accepts_online_payment')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
