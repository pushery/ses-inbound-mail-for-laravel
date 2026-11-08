<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row for each mail SES reports, kept unique by the id SES gave it, so a notification SNS
// delivers twice and the reconciliation after it record the mail once.
//
// The moments are dateTimeTz rather than timestamps(): timestamps() declares TIMESTAMP on MySQL, which
// ends at 2038-01-19 03:14:07 UTC, and a column without a time zone on PostgreSQL. dateTimeTz is
// DATETIME on MySQL and timestamptz on PostgreSQL.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_inbound_mails', function (Blueprint $table): void {
            $table->id();
            $table->string('message_id', 255)->unique();
            $table->string('sender', 320)->nullable();
            $table->json('recipients');
            $table->json('verdicts');
            $table->string('transit_key', 1024);
            $table->dateTimeTz('received_at')->nullable();
            $table->string('archive_disk')->nullable();
            $table->string('archive_path', 1024)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->dateTimeTz('taken_over_at')->nullable();
            $table->dateTimeTz('handed_over_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_inbound_mails');
    }
};
