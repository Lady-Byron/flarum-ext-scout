<?php

namespace ClarkWinkelmann\Scout\Job;

use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

trait SerializesAndRestoresWrappedModelIdentifiers
{
    protected function getSerializedPropertyValue($value)
    {
        if ($value instanceof QueueableCollection) {
            $first = $value->first();

            if ($first instanceof ScoutModelWrapper) {
                return new ModelIdentifier(
                    get_class($first->getRealModel()),
                    $value->getQueueableIds(),
                    $value->getQueueableRelations(),
                    $value->getQueueableConnection()
                );
            }
        }

        // [FIX #11] 修复原版 bug：应该调用 getSerializedPropertyValue 而非 getRestoredPropertyValue
        return parent::getSerializedPropertyValue($value);
    }

    protected function restoreCollection($value)
    {
        $collection = parent::restoreCollection($value);
        $wrapped = $collection->map(fn($m) => new ScoutModelWrapper($m));
        return new EloquentCollection($wrapped->all());
    }
}
