<?php

namespace ClarkWinkelmann\Scout\Job;

use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class RemoveFromSearch extends \Laravel\Scout\Jobs\RemoveFromSearch
{
    use SerializesAndRestoresWrappedModelIdentifiers;

    /**
     * 反序列化时仅创建含 ID 的存根模型（不从数据库加载）
     * 删除操作只需要模型 ID 来告知 ES 删除对应文档，且被删除的记录可能已不在数据库中
     */
    protected function restoreCollection($value)
    {
        if (!$value->class || count($value->id) === 0) {
            return new EloquentCollection;
        }

        return new EloquentCollection(
            collect($value->id)->map(function ($id) use ($value) {
                $model = new ScoutModelWrapper(new $value->class);
                // [FIX #3] Scout 9 兼容：手动实现去除表名前缀
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
