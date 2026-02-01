<?php

namespace ClarkWinkelmann\Scout\Listener;

use Flarum\Discussion\Event\Deleting;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class DeletingDiscussion
{
    public function handle(Deleting $event)
    {
        // 仅加载帖子 ID（而非完整模型），减少内存占用
        $postIds = $event->discussion->posts()->pluck('id')->all();

        // Flarum 不会为每个帖子单独触发 Deleted 事件，所以我们手动处理
        // 使用 afterDelete 确保在讨论删除后再从索引中移除帖子
        $event->discussion->afterDelete(function () use ($postIds) {
            if (empty($postIds)) {
                return;
            }

            // 分批处理避免 OOM
            foreach (array_chunk($postIds, 100) as $chunk) {
                $posts = new EloquentCollection(
                    array_map(function ($id) {
                        $post = new Post();
                        $post->id = $id;
                        return $post;
                    }, $chunk)
                );

                $posts->unsearchable();
            }
        });
    }
}
