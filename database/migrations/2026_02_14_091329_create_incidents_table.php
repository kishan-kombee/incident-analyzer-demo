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
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->json('logs')->comment('Raw log entries from intake');
            $table->json('preprocessed')->nullable()->comment('Deduped, grouped, severity-marked');
            $table->string('likely_cause')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('next_steps')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
