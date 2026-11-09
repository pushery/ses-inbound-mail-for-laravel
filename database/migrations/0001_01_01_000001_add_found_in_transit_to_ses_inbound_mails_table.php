<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\SesInboundMail\Support\MigrationLocks;

// Whether the reconciliation found a mail in transit, rather than a notification reporting it. Such a
// mail is recorded from its headers: the recipient SES named in the Received header it put on top, and
// the verdicts on spam and viruses SES wrote into it, without SPF, DKIM and DMARC. An application that
// treats it differently reads it on the mail it is handed. A mail recorded before this column reads as
// reported.
return new class extends Migration
{
    public function up(): void
    {
        MigrationLocks::bound();

        Schema::table('ses_inbound_mails', function (Blueprint $table): void {
            $table->boolean('found_in_transit')->default(false);
        });
    }

    public function down(): void
    {
        MigrationLocks::bound();

        Schema::table('ses_inbound_mails', function (Blueprint $table): void {
            $table->dropColumn('found_in_transit');
        });
    }
};
