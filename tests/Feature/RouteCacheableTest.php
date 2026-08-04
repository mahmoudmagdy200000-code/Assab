<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Production could not cache its routes (2026-08-04):
 *
 *   Unable to prepare route [api/v1/notifications/unread] for serialization.
 *   Another route has already been assigned name [notifications.unread].
 *
 * Every module route file is registered TWICE — once by its own
 * RouteServiceProvider under /api/v1, once by routes/api.php inside the
 * apilocale group — so each ->name() in it was declared twice. `route:cache`
 * refuses to serialize a duplicate name, and without a cached route table a
 * ~1500-route app pays the full route-compilation cost on every request.
 */
class RouteCacheableTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        $duplicates = collect(Route::getRoutes()->getRoutes())
            ->map->getName()
            ->filter()
            // A trailing dot is a GROUP prefix landing on a route that never
            // named itself; Laravel's serializer regenerates those instead of
            // failing (AbstractRouteCollection::addToSymfonyRoutesCollection),
            // so they are not what breaks the cache.
            ->reject(fn (string $name) => str_ends_with($name, '.'))
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->keys()
            ->all();

        $this->assertSame([], $duplicates, 'duplicate route names break `php artisan route:cache`: '.implode(', ', $duplicates));
    }

    /** The canonical (provider) copy keeps the bare name; only the apilocale copy is prefixed. */
    public function test_module_route_names_resolve_to_the_v1_url(): void
    {
        $this->assertSame('/api/v1/notifications/unread', route('notifications.unread', absolute: false));
    }
}
