<?php

namespace EduLazaro\Laracrate\Uploads;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Where a front end may upload, by name, and who may: the upload targets an app
 * declares once, in routes/uploads.php, as it declares its broadcast channels.
 *
 *   UploadTargets::define('avatar', fn (User $user) => UploadGrant::for($user, 'avatar', replace: true));
 *
 *   UploadTargets::define('venue.gallery', fn (User $user, Venue $venue) =>
 *       $user->can('update', $venue) ? UploadGrant::for($venue, 'gallery') : null);
 *
 * The React and Svelte components ask for a target by name, with its params: what to
 * show when they mount, and a grant once the user picks files. The callback's first argument is the signed in user; the others come
 * from the params by name, a model resolved by its route key. The params come from the
 * browser, so the callback is where the app decides whether this user may upload
 * there: it returns the grant, or null to refuse.
 */
final class UploadTargets
{
    /** @var array<string, Closure> */
    private array $targets = [];

    private bool $loaded = false;

    public static function define(string $name, Closure $callback): void
    {
        app(self::class)->add($name, $callback);
    }

    public function add(string $name, Closure $callback): void
    {
        $this->targets[$name] = $callback;
    }

    public function has(string $name): bool
    {
        $this->load();

        return isset($this->targets[$name]);
    }

    /** The grant the target gives this user for these params, or null when it refuses them. */
    public function grant(string $name, ?Model $user, array $params = []): ?UploadGrant
    {
        $this->load();

        $callback = $this->targets[$name] ?? null;
        $arguments = $callback ? $this->arguments($callback, $user, $params) : null;

        if ($arguments === null) {
            return null;
        }

        $grant = $callback(...$arguments);

        return $grant instanceof UploadGrant ? $grant : null;
    }

    /** The app's targets file (laracrate.uploads.targets), read the first time a target is asked for. */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $path = config('laracrate.uploads.targets');

        if (is_string($path) && is_file($path)) {
            require $path;
        }
    }

    /** The user, then each param by name. Null when one cannot be had: the target refuses. */
    private function arguments(Closure $callback, ?Model $user, array $params): ?array
    {
        $arguments = [];

        foreach ((new ReflectionFunction($callback))->getParameters() as $index => $parameter) {
            [$found, $value] = $index === 0 ? $this->user($parameter, $user) : $this->param($parameter, $params);

            if (! $found) {
                return null;
            }

            $arguments[] = $value;
        }

        return $arguments;
    }

    private function user(ReflectionParameter $parameter, ?Model $user): array
    {
        if ($user === null) {
            return [$parameter->allowsNull(), null];
        }

        $type = $parameter->getType();

        // fn (Admin $admin): a signed in client is not one.
        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! $user instanceof ($type->getName())) {
            return [false, null];
        }

        return [true, $user];
    }

    private function param(ReflectionParameter $parameter, array $params): array
    {
        $value = $params[$parameter->getName()] ?? null;

        if ($value === null || $value === '') {
            if ($parameter->isDefaultValueAvailable()) {
                return [true, $parameter->getDefaultValue()];
            }

            return [$parameter->allowsNull(), null];
        }

        if (! is_scalar($value)) {
            return [false, null];
        }

        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if ($name !== null && ! $type->isBuiltin()) {
            if (! is_subclass_of($name, Model::class)) {
                return [false, null];
            }

            $model = (new $name)->resolveRouteBinding($value);

            return [$model !== null, $model];
        }

        return match ($name) {
            'int' => [is_numeric($value) && (int) $value == $value, (int) $value],
            'float' => [is_numeric($value), (float) $value],
            'bool' => [true, filter_var($value, FILTER_VALIDATE_BOOL)],
            'string' => [true, (string) $value],
            default => [true, $value],
        };
    }
}
