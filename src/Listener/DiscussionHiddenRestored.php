<?php

namespace ClarkWinkelmann\Scout\Listener;

use Flarum\Discussion\Event\Hidden;
use Flarum\Discussion\Event\Restored;

class DiscussionHiddenRestored
{
    public function handle($event)
    {
        if ($event instanceof Hidden) {
            // 讨论被隐藏时，从索引中移除所有帖子
            $event->discussion->posts()->chunkById(100, function ($posts) {
                $posts->unsearchable();
            });
        } elseif ($event instanceof Restored) {
            // 讨论恢复时，重新索引符合条件的帖子（仅评论类型且未隐藏）
            $event->discussion->posts()
                ->where('type', 'comment')
                ->whereNull('hidden_at')
                ->chunkById(100, function ($posts) {
                    $posts->searchable();
                });
        }
    }
}
