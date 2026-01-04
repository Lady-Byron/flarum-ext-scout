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
                'discussions.id'
            )
            ->groupBy('discussions.id')
            ->addBinding($subquery->getBindings(), 'join');

        // 设置排序：标题匹配优先，然后按帖子匹配排序
        // 注意：闭包中需要使用 $grammar 来正确处理表前缀
        $search->setDefaultSort(function ($query) use ($discussionIds, $postIds, $grammar) {
            $discussionIdsCount = count($discussionIds);
            $postIdsCount = count($postIds);
            
            // 获取带前缀的表名
            $discussionsTable = $grammar->wrapTable('discussions');
            
            // 标题匹配的讨论排在前面
            if ($discussionIdsCount > 0) {
                $discussionIdsSql = str_repeat(', ?', $discussionIdsCount);
                $query->orderByRaw('CASE WHEN ' . $discussionsTable . '.id IN (' . ltrim($discussionIdsSql, ', ') . ') THEN 0 ELSE 1 END', $discussionIds);
            }
            // 按照最相关帖子在搜索结果中的顺序排序
            if ($postIdsCount > 0) {
                $postIdsSql = str_repeat(', ?', $postIdsCount);
                $query->orderByRaw('FIELD(most_relevant_post_id' . $postIdsSql . ')', $postIds);
            }
        });
    }
}
