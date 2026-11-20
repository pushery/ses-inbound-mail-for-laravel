<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Override;
use Pushery\SesInboundMail\Aws\AwsClients;
use Pushery\SesInboundMail\Console\ReconcileTheTransitCommand;
use Pushery\SesInboundMail\Console\SetUpAwsCommand;
use Pushery\SesInboundMail\Console\TakeOverAMailCommand;
use Pushery\SesInboundMail\Models\InboundMail;

final class SesInboundMailServiceProvider extends ServiceProvider
{
    /**
     * The sections of the config that are catalogs, as dotted paths from the config key: maps whose
     * keys the host chooses, one entry per plan, per document or per signing key. A key that a
     * published catalog leaves out was removed by the host rather than added by a later release, so
     * the merge takes a published catalog as it stands. A catalog the host never published arrives
     * whole from the default. A package lists such a section here, for example
     * 'ses-inbound-mail.plans'.
     *
     * @var list<string>
     */
    private const array CATALOG_SECTIONS = [];

    /**
     * Whether the bundled migrations are registered automatically. Disable with
     * self::ignoreMigrations() to publish and manage them in the host app instead.
     */
    public static bool $runsMigrations = true;

    /**
     * Whether the table exists although the bundled migrations are not registered, because the
     * application migrates a copy of its own. Only ignoreMigrations(tablesExist: true) sets it, and
     * it keeps the reconciliation and the prune on the schedule: $runsMigrations alone cannot tell
     * a table migrated elsewhere from one that was declined.
     */
    public static bool $tablesMigratedElsewhere = false;

    /**
     * Stops registering the bundled migrations. An application that publishes them and migrates the
     * copy passes tablesExist: true, so the reconciliation and the prune stay on the schedule.
     */
    public static function ignoreMigrations(bool $tablesExist = false): void
    {
        self::$runsMigrations = false;
        self::$tablesMigratedElsewhere = $tablesExist;
    }

    #[Override]
    public function register(): void
    {
        $this->mergeConfigRecursivelyFrom(__DIR__.'/../config/ses-inbound-mail.php', 'ses-inbound-mail');

        // The clients of the setup, in the region the configuration names, with the credentials the SDK
        // finds for the process, the environment first.
        $this->app->bind(AwsClients::class, static function (Application $app): AwsClients {
            $region = $app->make(Repository::class)->get('ses-inbound-mail.aws.region');

            return new AwsClients(['region' => is_string($region) && $region !== '' ? $region : 'eu-central-1', 'version' => 'latest']);
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/ses-inbound-mail.php');

        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // The net under the notifications and the prune read the table of the reported mails, so
        // they run only where it exists: where the package migrates it, or where the application
        // migrates a copy of its own and said so. Read from the flags rather than from the schema,
        // since asking the database at boot would put a query on every request.
        if (self::$runsMigrations || self::$tablesMigratedElsewhere) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule, Container $container): void {
                $schedule->command('ses-inbound-mail:reconcile')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

                // The prune deletes originals on the archive disk, so it is planned only where the
                // configuration names a retention. A running prune holds back a second one, since two at
                // once would delete the same rows and originals together. Its lock lasts two hours, well
                // under the day: a prune that was killed without releasing it leaves the next day's run
                // free instead of keeping it from starting.
                if (is_int($container->make(Repository::class)->get('ses-inbound-mail.retention.days'))) {
                    $schedule->command('model:prune', ['--model' => [InboundMail::model()]])->daily()->withoutOverlapping(120)->onOneServer();
                }
            });
        }

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->commands([ReconcileTheTransitCommand::class, SetUpAwsCommand::class, TakeOverAMailCommand::class]);
        }
    }

    /**
     * Lay the shipped defaults under a published config file, all the way down.
     *
     * Laravel's own `mergeConfigFrom()` is a single `array_merge` at the top level, so it asks
     * one question per top-level key: is it there? A host that published the config file has
     * every top-level key, so a key that a later release adds inside one of those blocks would
     * never arrive, and the block the host published would win whole.
     *
     * Nothing would error or log: the new setting would read as `null` (or as `[]`). A feature
     * added in a minor release would be off for exactly the hosts that customized that area, and
     * a corrected security default would not take effect, for as long as the host keeps the file
     * it published.
     *
     * Recursion stops at a list on either side, because a list is a value, not a structure.
     * `array_merge_recursive()` concatenates lists and `array_replace_recursive()` merges them by
     * index: a host narrowing a shipped `['web', 'api']` to `['admin']` gets back
     * `['web', 'api', 'admin']` from the first and `['admin', 'api']` from the second. Both hand
     * back an entry the host removed, which on an allowlist is a security regression. Here the
     * published list stands as written.
     *
     * `array_is_list([])` is true, which is the behavior wanted here: an empty array is a host
     * saying "none", and descending into it could only re-introduce what it emptied.
     *
     * Recursion stops at a catalog as well, a section named in CATALOG_SECTIONS. Its keys are the
     * host's, so a key missing from the published catalog is one the host removed, and laying the
     * default under it would hand that entry back.
     *
     * A host with a cached config is not merged at runtime: the cache already holds the merge
     * `config:cache` did when it ran. A key a later release adds is missing from it until the host
     * caches again, so a package that reads a nested key needs its read-site fallback to agree with
     * the shipped default.
     */
    private function mergeConfigRecursivelyFrom(string $path, string $key): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $shipped = require $path;

        $repository = $this->app->make(Repository::class);
        $existing = $repository->get($key);

        // Both sides come off disk or out of the container, so neither is provably
        // string-keyed here — a config array is just an array. The recursion below is
        // written for exactly that: it asks whether a value is a list, never whether a key
        // is a string.
        $repository->set($key, $this->mergeConfigSections(
            is_array($shipped) ? $shipped : [],
            is_array($existing) ? $existing : [],
            $key.'.',
            self::CATALOG_SECTIONS,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $shipped
     * @param  array<array-key, mixed>  $published
     * @param  string  $path  the dotted path of the two maps, so a catalog is recognized at any depth
     * @param  list<string>  $catalogs  the dotted paths of the catalogs, which the merge takes as published
     * @return array<array-key, mixed>
     */
    private function mergeConfigSections(array $shipped, array $published, string $path = '', array $catalogs = []): array
    {
        foreach ($shipped as $key => $value) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $value;

                continue;
            }

            // Recurse only where both sides are maps and the section is no catalog. If either is a
            // list, or the published value is a scalar or an explicit null, what the host wrote
            // stands. An explicit null is a value like any other here, which keeps "write => null
            // to switch this off" working exactly as a shipped config file describes it.
            if (is_array($value) && is_array($published[$key]) && ! array_is_list($value) && ! array_is_list($published[$key]) && ! in_array($path.$key, $catalogs, true)) {
                $published[$key] = $this->mergeConfigSections($value, $published[$key], $path.$key.'.', $catalogs);
            }
        }

        return $published;
    }

    private function registerPublishing(): void
    {
        // Resolve publish targets through the Application contract's path methods
        // (available via illuminate/contracts), not the config_path()/database_path()/
        // resource_path()/lang_path() global helpers. Those are Foundation helpers,
        // shipped only with laravel/framework — which this lean package does not
        // require — so the helper form would freeze a wrong dependency contract and
        // fatal in a non-Foundation host. The method form is behavior-identical.
        //
        // Each group carries the bare 'ses-inbound-mail' umbrella tag on top of its
        // specific one, so `vendor:publish --tag="ses-inbound-mail"` publishes every
        // resource at once — the tag convention Laravel's official package skeleton
        // establishes.
        //
        // The umbrella covers what a host adapts by editing a file: the config. There is
        // deliberately no views group, because the package renders no page, and no lang group,
        // because it writes no text to translate. The umbrella has two exceptions, and a package
        // can grow into either without a warning, because publishing more is not an error
        // anywhere:
        //
        //   * Migration tags split per opt-in layer. A published migration runs. A package
        //     that separates its migration tags does so that a host which enabled one layer
        //     never creates the other layers' tables. One umbrella command would undo that,
        //     and the damage would show at the host's next `php artisan migrate`, not at
        //     publish time. With a single migration group, as here, the umbrella may carry it.
        //   * Tags that write to the same destination and exclude each other, such as two
        //     variants of one view set of which the host picks one. Under a common tag the
        //     registration order decides which survives, and the result is undefined.
        $this->publishes([
            __DIR__.'/../config/ses-inbound-mail.php' => $this->app->configPath('ses-inbound-mail.php'),
        ], ['ses-inbound-mail', 'ses-inbound-mail-config']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], ['ses-inbound-mail', 'ses-inbound-mail-migrations']);
    }
}
