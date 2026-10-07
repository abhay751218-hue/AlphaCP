<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 10 — which archive already went to which destination.
 *
 * The cron command uses this to be idempotent: an archive is pushed to a
 * destination exactly once, no matter how often the hourly tick fires or how
 * often an operator runs the command by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('backup_destination_pushes')) {
            Schema::create('backup_destination_pushes', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('destination_id');
                $table->string('username', 16);
                $table->string('archive_id', 32);
                $table->string('file', 120);
                $table->string('status', 16)->default('queued'); // queued|done|failed
                $table->unsignedBigInteger('task_id')->nullable();
                $table->string('message', 500)->nullable();
                $table->timestamps();

                $table->index('destination_id', 'bdp_destination_index');
                $table->unique(['destination_id', 'archive_id'], 'bdp_destination_archive_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_destination_pushes');
    }
};
