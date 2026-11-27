<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\SesInboundMail\Support\TableLookup;

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
        // A copy of this file can run after the table exists: an application that publishes the
        // migrations a second time, or once they have run from the package, gets every file again
        // under a new date, and `migrate` takes each copy for a migration it has not run. The copy
        // leaves the table it finds, and the mails in it, as they are.
        //
        // A table found here says that CREATE TABLE ran, not that the unique index after it did. MySQL
        // runs no DDL inside a transaction, so a `migrate` cut off between the two leaves a table in
        // which a notification SNS delivers twice is recorded twice, and returning on the table alone
        // would record that as done. A table that carries the index under any name passes.
        if (TableLookup::exists('ses_inbound_mails')) {
            if (! Schema::hasIndex(TableLookup::qualified('ses_inbound_mails'), ['message_id'], 'unique')) {
                throw new RuntimeException('ses_inbound_mails exists without the unique index on message_id that this migration creates, so a previous migrate stopped between the table and its index, or the table is not the one this package creates. Add the index (a unique index on ses_inbound_mails.message_id) and run migrate again, or drop the table first if it holds no mail.');
            }

            return;
        }

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
        // up() leaves a table it finds in place, so the table this rollback would drop can hold the
        // mails an earlier run recorded. The rollback refuses to take them along.
        if (TableLookup::exists('ses_inbound_mails') && DB::table('ses_inbound_mails')->exists()) {
            throw new RuntimeException('Refusing to drop the ses_inbound_mails table while it holds mails. Delete them first if they can go.');
        }

        Schema::dropIfExists('ses_inbound_mails');
    }
};
