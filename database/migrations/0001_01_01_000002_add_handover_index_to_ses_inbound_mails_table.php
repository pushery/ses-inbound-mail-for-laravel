<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Pushery\SesInboundMail\Support\MigrationLocks;
use Pushery\SesInboundMail\Support\TableLookup;

// The reconciliation every five minutes and the health checks at every run ask for the mails not
// handed to the application yet, by the moment they were taken over or, where they never were,
// recorded, and the daily prune for the mails handed over before a moment. The table keeps every
// mail while no retention is set, so without an index each of them reads all of it to find the few
// rows it wants. An index that leads with handed_over_at and carries taken_over_at serves all three,
// on PostgreSQL and on MySQL.
//
// Its price: the takeover writes both columns, once when it takes the original over and once when it
// hands the mail over, and an update of an indexed column is no HOT update on PostgreSQL. Those two
// updates of every mail write an entry into each index of the table, where they wrote none before.
//
// The table takes its writes while the index is built. On PostgreSQL the build is concurrent, which
// cannot run inside a transaction, so this migration runs outside the one Laravel would open. A
// concurrent build that fails leaves an invalid index of the same name behind, which the planner
// never reads, so whatever stands under the name goes first and running the migration again builds
// the index. The build waits for every transaction of the database that started before it and
// blocks no read or write while it waits, so it is left to wait rather than cut short by a
// lock_timeout. MySQL adds the index in place while writes go on, and the wait for the table's
// metadata lock is bounded. SQLite builds it plainly.
//
// PostgreSQL and SQLite allow an index name once per schema, so there the name carries the table
// prefix where the connection prefixes its indexes, as a name Laravel derives does. A second install
// under another prefix in the same schema then drops and builds its own index, not the one of the
// first. MySQL allows an index name once per table, and the name stays as it is.
return new class extends Migration
{
    private const string INDEX = 'ses_inbound_mails_handed_over_at_taken_over_at_index';

    /** PostgreSQL refuses a concurrent build inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // On a first run nothing stands under the name, and the drop changes nothing.
            Schema::getConnection()->statement('drop index concurrently if exists '.$this->index());

            Schema::table('ses_inbound_mails', function (Blueprint $table): void {
                $table->index(['handed_over_at', 'taken_over_at'], $this->index())->online();
            });

            return;
        }

        MigrationLocks::bound();

        // MySQL and SQLite leave no index behind a build that fails, so an index under the name is
        // this one, and a copy of this file that runs after it, as one published a second time does,
        // leaves it as it is.
        Schema::whenTableDoesntHaveIndex(TableLookup::qualified('ses_inbound_mails'), $this->index(), function (Blueprint $table): void {
            $table->index(['handed_over_at', 'taken_over_at'], $this->index())->online();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement('drop index concurrently if exists '.$this->index());

            return;
        }

        MigrationLocks::bound();

        Schema::table('ses_inbound_mails', function (Blueprint $table): void {
            $table->dropIndex($this->index());
        });
    }

    /** The name the index goes by on the connection the migration runs on. */
    private function index(): string
    {
        $connection = Schema::getConnection();

        return in_array($connection->getDriverName(), ['pgsql', 'sqlite'], true) && (bool) $connection->getConfig('prefix_indexes')
            ? $connection->getTablePrefix().self::INDEX
            : self::INDEX;
    }
};
