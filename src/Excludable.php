<?php

namespace Maize\Excludable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Maize\Excludable\Models\Exclusion;
use Maize\Excludable\Scopes\ExclusionScope;
use Maize\Excludable\Support\Config;

/**
 * @method static \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withExcluded(bool $withExcluded = true)
 * @method static \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withoutExcluded()
 * @method static \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder onlyExcluded()
 * @method static \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder whereHasExclusion(bool $not = false)
 * @method static \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder whereDoesntHaveExclusion()
 */
trait Excludable
{
    public static function bootExcludable(): void
    {
        static::addGlobalScope(new ExclusionScope);
    }

    public function exclusion(): MorphOne
    {
        return $this
            ->morphOne(
                related: Config::getExclusionModel(),
                name: 'excludable'
            )
            ->where('type', Exclusion::TYPE_EXCLUDE);
    }

    public function excluded(): bool
    {
        return $this->exclusion()->exists();
    }

    public function addToExclusion(): bool
    {
        if ($this->fireModelEvent('excluding') === false) {
            return false;
        }

        $exclusion = $this->exclusion()->firstOrCreate([
            'type' => Exclusion::TYPE_EXCLUDE,
            'excludable_type' => $this->getMorphClass(),
            'excludable_id' => $this->getKey(),
        ]);

        if ($exclusion->wasRecentlyCreated) {
            $this->fireModelEvent('excluded', false);
        }

        return true;
    }

    public function removeFromExclusion(): bool
    {
        $this->exclusion()->delete();

        return true;
    }

    public static function excludeAllModels(array|Model $exceptions = []): void
    {
        $exceptions = collect($exceptions)
            ->map(fn (mixed $exception) => match (true) {
                is_a($exception, Model::class) => $exception->getKey(),
                default => $exception,
            });

        DB::transaction(function () use ($exceptions) {
            $exclusionModel = Config::getExclusionModel();

            $exclusionModel
                ->query()
                ->where('excludable_type', app(static::class)->getMorphClass())
                ->delete();

            $exclusionModel
                ->query()
                ->create([
                    'type' => Exclusion::TYPE_EXCLUDE,
                    'excludable_type' => app(static::class)->getMorphClass(),
                    'excludable_id' => '*',
                ]);

            $exceptions->each(
                fn (mixed $exception) => $exclusionModel->query()->create([
                    'type' => Exclusion::TYPE_INCLUDE,
                    'excludable_type' => app(static::class)->getMorphClass(),
                    'excludable_id' => $exception,
                ])
            );
        });
    }

    public static function includeAllModels(): void
    {
        Config::getExclusionModel()
            ->query()
            ->where('excludable_type', app(static::class)->getMorphClass())
            ->delete();
    }
}
