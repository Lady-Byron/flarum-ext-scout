<?php

namespace ClarkWinkelmann\Scout;

use ClarkWinkelmann\Scout\Extend\Scout as ScoutExtend;
use Flarum\Api\Serializer\DiscussionSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event as DiscussionEvent;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Extend;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Post\Event as PostEvent;
use Flarum\User\Search\UserSearcher;
use Flarum\User\User;
use Flarum\User\Event as UserEvent;
use FoF\UserBio\Event\BioChanged;
use Laravel\Scout\Console as ScoutConsole;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/less/forum.less'),

    new Extend\Locales(__DIR__ . '/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(ScoutServiceProvider::class),

    (new Extend\SimpleFlarumSearch(DiscussionSearcher::class))
        ->setFullTextGambit(Search\DiscussionGambit::class),
    (new Extend\SimpleFlarumSearch(UserSearcher::class))
        ->setFullTextGambit(Search\UserGambit::class),

    (new Extend\Console())
        ->command(Console\CreateIndexCommand::class)
        ->command(Console\FlushCommand::class)
        ->command(Console\ImportAllCommand::class)
        ->command(Console\ImportCommand::class)
        ->command(ScoutConsole\IndexCommand::class)
        ->command(ScoutConsole\DeleteIndexCommand::class),

    // [NEW] 扩展 DiscussionSerializer 输出高亮字段
    (new Extend\ApiSerializer(DiscussionSerializer::class))
        ->attributes(function (DiscussionSerializer $serializer, Discussion $discussion, array $attributes): array {
            // 从 ScoutStatic 静态属性读取高亮数据
            $discussionHighlight = ScoutStatic::getDiscussionHighlight($discussion->id);
            if ($discussionHighlight) {
                $attributes['titleHighlight'] = $discussionHighlight['title'][0] ?? null;
            }

            // 如果有 most_relevant_post_id，获取帖子高亮
            $mostRelevantPostId = $discussion->most_relevant_post_id ?? null;
            if ($mostRelevantPostId) {
                $postHighlight = ScoutStatic::getPostHighlight($mostRelevantPostId);
                if ($postHighlight) {
                    $attributes['contentHighlight'] = $postHighlight['content'][0] ?? null;
                }
            }

            return $attributes;
        }),

    (new ScoutExtend(Discussion::class))
        ->listenSaved(DiscussionEvent\Started::class, function (DiscussionEvent\Started $event) {
            return $event->discussion;
        })
        ->listenSaved(DiscussionEvent\Renamed::class, function (DiscussionEvent\Renamed $event) {
            return $event->discussion;
        })
        // [FIX #6] 添加隐藏/恢复事件监听
        ->listenSaved(DiscussionEvent\Hidden::class, function (DiscussionEvent\Hidden $event) {
            return $event->discussion;
        })
        ->listenSaved(DiscussionEvent\Restored::class, function (DiscussionEvent\Restored $event) {
            return $event->discussion;
        })
        ->listenDeleted(DiscussionEvent\Deleted::class, function (DiscussionEvent\Deleted $event) {
            return $event->discussion;
        })
        // [FIX #9] 添加 searchable 条件：排除已隐藏的讨论
        ->searchable(function (Discussion $discussion) {
            if ($discussion->hidden_at !== null) {
                return false;
            }
            return null;
        })
        ->attributes(function (Discussion $discussion): array {
            return [
                'id' => $discussion->id,
                'title' => $discussion->title,
            ];
        }),

    (new ScoutExtend(Post::class))
        ->listenSaved(PostEvent\Posted::class, function (PostEvent\Posted $event) {
            return $event->post;
        })
        ->listenSaved(PostEvent\Revised::class, function (PostEvent\Revised $event) {
            return $event->post;
        })
        // [FIX #6] 添加隐藏/恢复事件监听
        ->listenSaved(PostEvent\Hidden::class, function (PostEvent\Hidden $event) {
            return $event->post;
        })
        ->listenSaved(PostEvent\Restored::class, function (PostEvent\Restored $event) {
            return $event->post;
        })
        ->listenDeleted(PostEvent\Deleted::class, function (PostEvent\Deleted $event) {
            return $event->post;
        })
        // [FIX #7] 完善 searchable 条件：排除非评论类型和已隐藏的帖子
        ->searchable(function (Post $post) {
            if ($post->type !== 'comment') {
                return false;
            }
            if ($post->hidden_at !== null) {
                return false;
            }
            return null;
        })
        ->attributes(function (Post $post): array {
            return [
                'id' => $post->id,
            ];
        }),

    (new ScoutExtend(CommentPost::class))
        ->attributes(function (CommentPost $post): array {
            return [
                'content' => strip_tags($post->formatContent()),
            ];
        }),

    (new ScoutExtend(User::class))
        ->listenSaved(UserEvent\Registered::class, function (UserEvent\Registered $event) {
            return $event->user;
        })
        ->listenDeleted(UserEvent\Deleted::class, function (UserEvent\Deleted $event) {
            return $event->user;
        })
        ->listenSaved(BioChanged::class, function (BioChanged $event) {
            return $event->user;
        })
        ->attributes(function (User $user): array {
            return [
                'id' => $user->id,
                'displayName' => $user->display_name,
                'username' => $user->username,
                'bio' => $user->bio,
            ];
        }),

    (new Extend\Event())
        ->listen(DiscussionEvent\Deleting::class, Listener\DeletingDiscussion::class),
];
