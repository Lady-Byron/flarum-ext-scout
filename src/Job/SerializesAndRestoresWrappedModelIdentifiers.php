<?php

namespace ClarkWinkelmann\Scout\Job;

use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Queue\QueueableCollection;

/**
 * 处理 ScoutModelWrapper 的队列序列化
 *
 * 仅提供序列化方法（getSerializedPropertyValue）。
 * restoreCollection 由各 Job 子类自行实现，因为：
 * - MakeSearchable 需要从数据库加载完整模型（索引操作需要全部属性）
 * - RemoveFromSearch 只需创建含 ID 的存根模型（删除操作只需 ID，且原始记录可能已不存在）
 */
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
}
