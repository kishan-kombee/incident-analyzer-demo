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
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('cpu')->nullable()->comment('CPU % 0-100');
            $table->unsignedInteger('db_latency')->nullable()->comment('DB latency in ms');
            $table->string('requests_per_sec', 64)->nullable()->comment('e.g. High, 100, Low');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
