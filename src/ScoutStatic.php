<?php

namespace ClarkWinkelmann\Scout;

use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder;
use ONGR\ElasticsearchDSL\Highlight\Highlight;
use ONGR\ElasticsearchDSL\Search;

class ScoutStatic
{
    /**
     * 存储 Elasticsearch 原生高亮结果
     */
    public static array $highlights = [
        'discussions' => [],
        'posts' => [],
        'users' => [],
    ];

    /**
     * 标记当前请求是否已清除高亮缓存
     */
    protected static bool $highlightsClearedOnce = false;

    /**
     * 需要高亮的字段配置
     */
    public static array $attributesToHighlight = [
        Discussion::class => ['title'],
        Post::class => ['content'],
        User::class => ['username', 'displayName'],
    ];

    /**
     * 高亮配置
     * [FIX #4] encoder => 'html' 防止 XSS 攻击
     */
    public static array $highlightConfig = [
        'pre_tags' => ['<mark>'],
        'post_tags' => ['</mark>'],
        'fragment_size' => 150,
        'number_of_fragments' => 3,
        'encoder' => 'html',
    ];

    /**
     * 获取讨论的高亮结果
     */
    public static function getDiscussionHighlight(int $id): ?array
    {
        return self::$highlights['discussions'][$id] ?? null;
    }

    /**
     * 获取帖子的高亮结果
     */
    public static function getPostHighlight(int $id): ?array
    {
        return self::$highlights['posts'][$id] ?? null;
    }

    /**
     * 获取用户的高亮结果
     */
    public static function getUserHighlight(int $id): ?array
    {
        return self::$highlights['users'][$id] ?? null;
    }

    /**
     * 清除所有高亮缓存
     */
    public static function clearHighlights(): void
    {
        self::$highlights = [
            'discussions' => [],
            'posts' => [],
            'users' => [],
        ];
    }

    /**
     * 每个请求只清除一次高亮缓存
     * 防止同一请求中多次 Gambit 调用导致高亮被覆盖
     */
    public static function clearHighlightsOnce(): void
    {
        if (self::$highlightsClearedOnce) {
            return;
        }
        self::$highlightsClearedOnce = true;
        self::clearHighlights();
    }

    /**
     * Replacement for Searchable::makeAllSearchable
     */
    public static function makeAllSearchable(string $class, $chunk = null)
    {
        $self = new ScoutModelWrapper(new $class);

        $self->newQuery()
            ->orderBy($self->getKeyName())
            ->searchable($chunk);
    }

    /**
     * Replacement for Searchable::removeAllFromSearch
     */
    public static function removeAllFromSearch(string $class)
    {
        $self = new ScoutModelWrapper(new $class);

        $self->searchableUsing()->flush($self);
    }

    /**
     * 构建 Scout Builder，支持 ES 高亮
     */
    public static function makeBuilder(string $class, string $query, $callback = null): Builder
    {
        $wrapped = new ScoutModelWrapper(new $class);

        // 如果没有自定义 callback，使用 ES 高亮 callback
        if (is_null($callback)) {
            $callback = self::buildElasticsearchCallback($class, $wrapped);
        }

        $builder = resolve(Builder::class, [
            'model' => $wrapped,
            'query' => $query,
            'callback' => $callback,
        ]);

        $settings = resolve(SettingsRepositoryInterface::class);
        $limit = (int)$settings->get('clarkwinkelmann-scout.limit');

        if ($limit > 0) {
            $builder->take($limit);
        } else {
            $builder->take(200);
        }

        return $builder;
    }

    /**
     * 构建 Elasticsearch 回调（支持高亮）
     */
    protected static function buildElasticsearchCallback(string $class, ScoutModelWrapper $model): callable
    {
        $highlightFields = Arr::get(self::$attributesToHighlight, $class) ?? [];
        $indexName = $model->searchableAs();

        return function (\Elastic\Elasticsearch\Client $client, Search $body) use ($class, $indexName, $highlightFields) {
            // 添加 Elasticsearch 原生高亮
            if (!empty($highlightFields)) {
                $highlight = new Highlight();
                $highlight->setTags(self::$highlightConfig['pre_tags'], self::$highlightConfig['post_tags']);

                foreach ($highlightFields as $field) {
                    $highlight->addField($field, [
                        'fragment_size' => self::$highlightConfig['fragment_size'],
                        'number_of_fragments' => self::$highlightConfig['number_of_fragments'],
                    ]);
                }

                $body->addHighlight($highlight);
            }

            $bodyArray = $body->toArray();

            // [FIX #4] 添加 HTML encoder 防止 XSS
            if (isset($bodyArray['highlight'])) {
                $bodyArray['highlight']['encoder'] = self::$highlightConfig['encoder'];
            }

            // === DEBUG: 打印 ES DSL（只建议临时开）===
            if (function_exists('logger')) {
                logger()->debug('SCOUT ES DSL', [
                                'index' => $indexName,
                                'model' => $class,
                                'body'  => $bodyArray,
                                'query' => $bodyArray['query'] ?? null,
                ]);
            }

            $result = $client->search([
                'index' => $indexName,
                'body' => $bodyArray,
            ])->asArray();

            // 提取并存储高亮结果
            self::extractHighlights($result, $class);

            return $result;
        };
    }

    /**
     * 从 Elasticsearch 响应中提取高亮结果
     */
    protected static function extractHighlights(array $result, string $class): void
    {
        $hits = Arr::get($result, 'hits.hits', []);

        $key = match ($class) {
            Discussion::class => 'discussions',
            Post::class => 'posts',
            User::class => 'users',
            default => null,
        };

        if (!$key) {
            return;
        }

        foreach ($hits as $hit) {
            $id = (int)($hit['_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $highlight = Arr::get($hit, 'highlight', []);
            if (!empty($highlight)) {
                self::$highlights[$key][$id] = [];
                foreach ($highlight as $field => $fragments) {
                    self::$highlights[$key][$id][$field] = $fragments;
                }
            }
        }
    }

    /**
     * 从 Elasticsearch 结果中提取 ID 列表
     */
    public static function extractIdsFromResult($results): array
    {
        if (!is_array($results)) {
            return [];
        }

        return collect(Arr::get($results, 'hits.hits', []))
            ->map(fn($hit) => (int)($hit['_id'] ?? 0))
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();
    }
}
