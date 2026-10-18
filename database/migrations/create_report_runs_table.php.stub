<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('panorama.history.connection');
    }

    public function up(): void
    {
        Schema::create(config('panorama.history.table', 'report_runs'), function (Blueprint $table): void {
            $table->id();
            $table->string('report');
            $table->string('name');
            $table->json('params');
            $table->string('format', 32);
            $table->string('filename');
            $table->string('destination', 64)->nullable();
            $table->string('path')->nullable();
            $table->string('user_id')->nullable();
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('rows')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();

            $table->index(['report', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('panorama.history.table', 'report_runs'));
    }
};
