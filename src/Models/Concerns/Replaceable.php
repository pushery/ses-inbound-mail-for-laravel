<?php

declare(strict_types=1);

namespace Pushery\SesInboundMail\Models\Concerns;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * Lets the application put a model of its own in the place of a model of this package, through the
 * `models` map of the package's configuration, keyed by the package's class. A class that does not
 * extend the package's is ignored, and the package's class answers.
 *
 * @phpstan-require-extends Model
 */
trait Replaceable
{
    /**
     * @return class-string<static>
     */
    public static function model(): string
    {
        $container = Container::getInstance();

        $models = $container->bound(Repository::class)
            ? $container->make(Repository::class)->get('ses-inbound-mail.models')
            : null;

        $configured = is_array($models) ? ($models[static::class] ?? null) : null;

        return is_string($configured) && is_subclass_of($configured, static::class)
            ? $configured
            : static::class;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolve(array $attributes = []): static
    {
        $class = static::model();

        return new $class($attributes);
    }
}
