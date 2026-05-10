<?php

namespace EduLazaro\Laracrate\Support;

use EduLazaro\Laracrate\Exceptions\CollectionNotAllowedForModel;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Resolves laracrate collection config with optional per-model scoping.
 *
 * Two modes:
 *
 *   1. Flat (legacy, no scoping)
 *
 *      'documents' => [
 *          'disk'   => 'documents',
 *          'access' => 'signed',
 *      ]
 *
 *      Any model using HasFiles can write to this collection with the same config.
 *
 *   2. Scoped (granular per model)
 *
 *      'documents' => [
 *          'disk'   => 'documents',        // base shared by all models
 *          'access' => 'signed',
 *          'models' => [
 *              'case'         => ['path' => 'cases/{slug}/documents'],
 *              'organization' => ['path' => 'orgs/{handle}/documents', 'preview' => false],
 *          ],
 *      ]
 *
 *      When 'models' is declared:
 *        - Only the listed morph aliases (or FQCNs) can use the collection.
 *        - The override block is merged on top of the base config.
 *        - The 'models' key itself is stripped from the resolved output.
 *
 *      Passing $modelOrAlias = null returns the base config without merging
 *      (useful for tooling that iterates collections without a model context).
 */
class CollectionConfig
{
    /**
     * Returns the effective collection config for a given model (alias or FQCN).
     *
     * @throws CollectionNotAllowedForModel when 'models' is declared and the
     *         given alias is not listed.
     */
    public static function resolve(string $collection, ?string $modelOrAlias = null): array
    {
        $config = config("laracrate.collections.{$collection}", []);

        if (!is_array($config) || $config === []) {
            return [];
        }

        if (!isset($config['models']) || !is_array($config['models'])) {
            return $config;
        }

        $models = $config['models'];
        unset($config['models']);

        if ($modelOrAlias === null) {
            return $config;
        }

        $key = self::matchKey($models, $modelOrAlias);

        if ($key === null) {
            throw new CollectionNotAllowedForModel(
                "Laracrate collection [{$collection}] is restricted to: [" .
                implode(', ', array_keys($models)) .
                "] — received [{$modelOrAlias}]."
            );
        }

        $override = is_array($models[$key]) ? $models[$key] : [];

        return array_replace_recursive($config, $override);
    }

    /**
     * True when a collection has a 'models' restriction declared.
     */
    public static function isRestricted(string $collection): bool
    {
        $config = config("laracrate.collections.{$collection}", []);

        return is_array($config)
            && isset($config['models'])
            && is_array($config['models']);
    }

    /**
     * Match the caller-supplied identifier against the keys in 'models'.
     * Tries direct hit first, then normalizes alias↔FQCN via the morph map.
     */
    protected static function matchKey(array $models, string $needle): ?string
    {
        if (array_key_exists($needle, $models)) {
            return $needle;
        }

        $map = Relation::morphMap();
        if (!is_array($map) || $map === []) {
            return null;
        }

        // FQCN → alias
        $alias = array_search($needle, $map, true);
        if ($alias !== false && array_key_exists($alias, $models)) {
            return $alias;
        }

        // alias → FQCN
        if (isset($map[$needle]) && array_key_exists($map[$needle], $models)) {
            return $map[$needle];
        }

        return null;
    }
}
