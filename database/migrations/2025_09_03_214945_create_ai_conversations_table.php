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
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('session_id')->index();
            $table->text('user_message');
            $table->text('ai_response');
            $table->enum('type', ['health_question', 'navigation_help', 'general'])->default('general');
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->json('context')->nullable(); // Contexte de la conversation
            $table->boolean('is_helpful')->nullable(); // Feedback utilisateur
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
