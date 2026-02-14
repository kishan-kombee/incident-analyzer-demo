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
        if (Schema::hasTable('incidents')) {
            Schema::table('incidents', function (Blueprint $table) {
                if (! Schema::hasColumn('incidents', 'logs')) {
                    $table->json('logs')->after('id');
                }
                if (! Schema::hasColumn('incidents', 'preprocessed')) {
                    $table->json('preprocessed')->nullable()->after('logs');
                }
                if (! Schema::hasColumn('incidents', 'likely_cause')) {
                    $table->string('likely_cause')->nullable()->after('preprocessed');
                }
                if (! Schema::hasColumn('incidents', 'confidence')) {
                    $table->decimal('confidence', 5, 4)->nullable()->after('likely_cause');
                }
                if (! Schema::hasColumn('incidents', 'next_steps')) {
                    $table->text('next_steps')->nullable()->after('confidence');
                }
            });
        }

        if (Schema::hasTable('metrics')) {
            Schema::table('metrics', function (Blueprint $table) {
                if (! Schema::hasColumn('metrics', 'cpu')) {
                    $table->unsignedTinyInteger('cpu')->nullable()->after('incident_id');
                }
                if (! Schema::hasColumn('metrics', 'db_latency')) {
                    $table->unsignedInteger('db_latency')->nullable()->after('cpu');
                }
                if (! Schema::hasColumn('metrics', 'requests_per_sec')) {
                    $table->string('requests_per_sec', 64)->nullable()->after('db_latency');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('incidents')) {
            $drops = array_filter(
                ['logs', 'preprocessed', 'likely_cause', 'confidence', 'next_steps'],
                fn ($c) => Schema::hasColumn('incidents', $c)
            );
            if ($drops !== []) {
                Schema::table('incidents', fn (Blueprint $table) => $table->dropColumn($drops));
            }
        }
        if (Schema::hasTable('metrics')) {
            $drops = array_filter(
                ['cpu', 'db_latency', 'requests_per_sec'],
                fn ($c) => Schema::hasColumn('metrics', $c)
            );
            if ($drops !== []) {
                Schema::table('metrics', fn (Blueprint $table) => $table->dropColumn($drops));
            }
        }
    }
};
