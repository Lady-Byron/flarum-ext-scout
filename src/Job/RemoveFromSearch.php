<?php

namespace ClarkWinkelmann\Scout\Job;

use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RemoveFromSearch extends \Laravel\Scout\Jobs\RemoveFromSearch
{
    use SerializesAndRestoresWrappedModelIdentifiers;

    protected function restoreCollection($value)
    {
        if (!$value->class || count($value->id) === 0) {
            return new EloquentCollection;
        }

        return new EloquentCollection(
            collect($value->id)->map(function ($id) use ($value) {
                $model = new ScoutModelWrapper(new $value->class);

                // FIX: 兼容 Scout 9 —— 直接拿 scout key 名，并去掉可能的表名前缀
                $keyName = $model->getScoutKeyName();
                if (strpos($keyName, '.') !== false) {
                    $keyName = substr($keyName, strrpos($keyName, '.') + 1);
                }

                $model->getRealModel()->forceFill([$keyName => $id]);

                return $model;
            })
        );
    }
}

