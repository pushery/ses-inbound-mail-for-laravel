<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pushery\SesInboundMail\Support\MigrationLocks;
use Pushery\SesInboundMail\Support\TableLookup;

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

        $add = static function (Blueprint $table): void {
            $table->boolean('found_in_transit')->default(false);
        };
        $connection = Schema::getConnection();

        // A copy of this file that runs after the column exists, as one published a second time does,
        // leaves the column as it is. On PostgreSQL the column is looked up in the catalog of the table
        // a query finds along the search_path: the schema builder's lookup of the columns reads the
        // server's version first, which a migration captured under pretend without a connection
        // cannot give.
        if ($connection->getDriverName() === 'pgsql') {
            $found = $connection->scalar(
                'select 1 from pg_attribute where attrelid = to_regclass(?) and attname = ? and not attisdropped',
                [$connection->getQueryGrammar()->wrapTable('ses_inbound_mails'), 'found_in_transit'],
            );

            if ($found === null) {
                Schema::table('ses_inbound_mails', $add);
            }

            return;
        }

        Schema::whenTableDoesntHaveColumn(TableLookup::qualified('ses_inbound_mails'), 'found_in_transit', $add);
    }

    public function down(): void
    {
        MigrationLocks::bound();

        // up() leaves a column it finds in place, so the column this rollback would drop can hold what
        // an earlier run wrote. Only a mail it marks as found in transit would read otherwise without
        // it, since the default writes every other one again, so the rollback refuses while there is
        // such a mail.
        if (DB::table('ses_inbound_mails')->where('found_in_transit', true)->exists()) {
            throw new RuntimeException('Refusing to drop the found_in_transit column while it marks a mail found in transit. Set it to false first if that can go.');
        }

        Schema::table('ses_inbound_mails', function (Blueprint $table): void {
            $table->dropColumn('found_in_transit');
        });
    }
};
