<?php

namespace ClarkWinkelmann\Scout\Search;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;
use Illuminate\Database\Query\Expression;

class DiscussionGambit implements GambitInterface
{
    public function apply(SearchState $search, $bit)
    {
        // 清除之前的高亮缓存
        ScoutStatic::clearHighlights();

        // 搜索讨论标题
        $discussionBuilder = ScoutStatic::makeBuilder(Discussion::class, $bit);
        $discussionIds = ScoutStatic::extractIdsFromResult($discussionBuilder->raw());

        // 搜索帖子内容
        $postBuilder = ScoutStatic::makeBuilder(Post::class, $bit);
        $postIds = ScoutStatic::extractIdsFromResult($postBuilder->raw());

        $postIdsCount = count($postIds);
        $postIdsSql = $postIdsCount > 0 ? str_repeat(', ?', $postIdsCount) : ', 0';

        $query = $search->getQuery();
        $grammar = $query->getGrammar();

        // 以下 SQL 逻辑与原版保持一致
        $allMatchingPostsQuery = Post::whereVisibleTo($search->getActor())
            ->select('posts.discussion_id')
            ->selectRaw('FIELD(id' . $postIdsSql . ') as priority', $postIds)
            ->where('posts.type', 'comment')
            ->whereIn('id', $postIds);

        $bestMatchingPostQuery = Post::query()
            ->select('posts.discussion_id')
            ->selectRaw('min(matching_posts.priority) as min_priority')
            ->join(
                new Expression('(' . $allMatchingPostsQuery->toSql() . ') ' . $grammar->wrap('matching_posts')),
                $query->raw('matching_posts.discussion_id'),
                '=',
                'posts.discussion_id'
            )
            ->groupBy('posts.discussion_id')
            ->addBinding($allMatchingPostsQuery->getBindings(), 'join');

        $subquery = Post::whereVisibleTo($search->getActor())
            ->select('posts.discussion_id')
            ->selectRaw('id as most_relevant_post_id')
            ->join(
                new Expression('(' . $bestMatchingPostQuery->toSql() . ') ' . $grammar->wrap('best_matching_posts')),
                $query->raw('best_matching_posts.discussion_id'),
                '=',
                'posts.discussion_id'
            )
            ->whereIn('id', $postIds)
            ->whereRaw('FIELD(id' . $postIdsSql . ') = best_matching_posts.min_priority', $postIds)
            ->addBinding($bestMatchingPostQuery->getBindings(), 'join');

        $query
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($discussionIds) {
                $query
                    ->whereNotNull('most_relevant_post_id')
                    ->orWhereIn('id', $discussionIds);
            })
            ->selectRaw('COALESCE(posts_ft.most_relevant_post_id, ' . $grammar->wrapTable('discussions') . '.first_post_id) as most_relevant_post_id')
            ->leftJoin(
                new Expression('(' . $subquery->toSql() . ') ' . $grammar->wrap('posts_ft')),
                $query->raw('posts_ft.discussion_id'),
                '=',
                // 关键：这里也不要写死 discussions.id，用 wrapTable('discussions') 适配前缀
                $grammar->wrapTable('discussions') . '.' . $grammar->wrap('id')
            )
            // 关键：groupBy 也不要写死 discussions.id
            ->groupBy($grammar->wrapTable('discussions') . '.' . $grammar->wrap('id'))
            ->addBinding($subquery->getBindings(), 'join');

        // 设置排序：标题匹配优先，然后按帖子匹配排序
        $search->setDefaultSort(function ($query) use ($discussionIds, $postIds) {
            $grammar = $query->getGrammar();

            // 关键：不要写死 discussions.id（无前缀表名会炸）
            $discussionIdCol = $grammar->wrapTable('discussions') . '.' . $grammar->wrap('id');

            // 标题匹配的讨论排在前面
            if (count($discussionIds) > 0) {
                $placeholders = implode(', ', array_fill(0, count($discussionIds), '?'));
                $query->orderByRaw(
                    "CASE WHEN {$discussionIdCol} IN ({$placeholders}) THEN 0 ELSE 1 END",
                    $discussionIds
                );
            }

            // 按照最相关帖子在搜索结果中的顺序排序
            if (count($postIds) > 0) {
                $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
                $query->orderByRaw(
                    "FIELD(most_relevant_post_id, {$placeholders})",
                    $postIds
                );
            }
        });
    }
}
