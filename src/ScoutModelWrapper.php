<?php

namespace ClarkWinkelmann\Scout;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;

class ScoutModelWrapper extends Model
{
    use Searchable;

    protected $table = 'scout_should_not_be_used';
    protected $realModel;

    public function __construct(Model $realModel)
    {
        parent::__construct([]);
        $this->realModel = $realModel;
    }

    public function getRealModel(): Model
    {
        return $this->realModel;
    }

    public function newQuery()
    {
        return $this->realModel->newQuery();
    }

    public function getQueueableId()
    {
        return $this->realModel->getQueueableId();
    }

    public static function bootSearchable()
    {
        // Override original
    }

    public function shouldBeSearchable(): bool
    {
        $callbacks = resolve('scout.searchable');

        // [FIX #2] 防止 class_parents() 返回 false
        $parents = class_parents($this->realModel) ?: [];
        foreach (array_reverse(array_merge([get_class($this->realModel)], $parents)) as $class) {
            if (Arr::exists($callbacks, $class)) {
                foreach ($callbacks[$class] as $callback) {
                    $returnValue = $callback($this->realModel);
                    if (is_bool($returnValue)) {
                        return $returnValue;
                    }
                }
            }
        }

        return true;
    }

    public function searchIndexShouldBeUpdated(): bool
    {
        return true;
    }

    public function queryScoutModelsByIds(Builder $builder, array $ids)
    {
        $query = $this->realModel->newQuery();

        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        $whereIn = in_array($this->getKeyType(), ['int', 'integer']) ? 'whereIntegerInRaw' : 'whereIn';

        return $query->{$whereIn}($this->getScoutKeyName(), $ids);
    }

    public function searchableAs(): string
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        return ($settings->get('clarkwinkelmann-scout.prefix') ?: '') . $this->realModel->getTable();
    }

    public function toSearchableArray(): array
    {
        $callbacks = resolve('scout.attributes');
        $attributes = [];

        // [FIX #2] 防止 class_parents() 返回 false
        $parents = class_parents($this->realModel) ?: [];
        foreach (array_reverse(array_merge([get_class($this->realModel)], $parents)) as $class) {
            if (Arr::exists($callbacks, $class)) {
                foreach ($callbacks[$class] as $callback) {
                    $attributes = array_merge($attributes, $callback($this->realModel, $attributes));
                }
            }
        }

        return $attributes;
    }

    public function syncWithSearchUsing()
    {
        return config('scout.queue.connection') ?: config('queue.default');
    }

    public function syncWithSearchUsingQueue()
    {
        return config('scout.queue.queue');
    }

    public function getScoutKey()
    {
        return $this->realModel->getKey();
    }

    public function getScoutKeyName()
    {
        return $this->realModel->getQualifiedKeyName();
    }

    public function scoutObserverSaved()
    {
        if (!$this->searchIndexShouldBeUpdated()) {
            return;
        }

        if (!$this->shouldBeSearchable()) {
            if ($this->wasSearchableBeforeUpdate()) {
                $this->unsearchable();
            }
            return;
        }

        $this->searchable();
    }

    public function scoutObserverDeleted()
    {
        if (!$this->wasSearchableBeforeDelete()) {
            return;
        }

        $this->unsearchable();
    }
}
