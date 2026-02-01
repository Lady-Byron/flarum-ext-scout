<?php

namespace ClarkWinkelmann\Scout\Job;

use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class MakeSearchable extends \Laravel\Scout\Jobs\MakeSearchable
{
    use SerializesAndRestoresWrappedModelIdentifiers;

    /**
     * 反序列化时从数据库加载完整模型并包装为 ScoutModelWrapper
     * 索引操作需要完整的模型属性来构建 toSearchableArray()
     */
    protected function restoreCollection($value)
    {
        $collection = parent::restoreCollection($value);
        $wrapped = $collection->map(fn($m) => new ScoutModelWrapper($m));
        return new EloquentCollection($wrapped->all());
    }
}
