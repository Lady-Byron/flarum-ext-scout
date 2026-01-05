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
     * 需要高亮的字段配置
     */
    public static array $attributesToHighlight = [
        Discussion::class => ['title'],
        Post::class => ['content'],
        User::class => ['username', 'displayName'],
    ];

    /**
     * 用于查询的字段配置
     */
    public static array $attributesToQuery = [
        Discussion::class => ['title'],
        Post::class => ['content'],
        User::class => ['username', 'displayName', 'bio'],
    ];

    /**
     * 高亮配置
     */
    public static array $highlightConfig = [
        'pre_tags' => ['<mark>'],
        'post_tags' => ['</mark>'],
        'fragment_size' => 150,
        'number_of_fragments' => 3,
        'encoder' => 'html',
    ];

    public static function getDiscussionHighlight(int $id): ?array
    {
        return self::$highlights['discussions'][$id] ?? null;
    }

    public static function getPostHighlight(int $id): ?array
    {
        return self::$highlights['posts'][$id] ?? null;
    }

    public static function getUserHighlight(int $id): ?array
    {
        return self::$highlights['users'][$id] ?? null;
    }

    public static function clearHighlights(): void
    {
        self::$highlights = [
            'discussions' => [],
            'posts' => [],
            'users' => [],
        ];
    }

    public static function makeAllSearchable(string $class, $chunk = null): void
    {
        $self = new ScoutModelWrapper(new $class);

        $self->newQuery()
            ->orderBy($self->getKeyName())
            ->searchable($chunk);
    }

    public static function removeAllFromSearch(string $class): void
    {
        $self = new ScoutModelWrapper(new $class);

        $self->searchableUsing()->flush($self);
    }

    public static function makeBuilder(string $class, string $query, ?callable $callback = null): Builder
    {
        $wrapped = new ScoutModelWrapper(new $class);

        if (is_null($callback)) {
            $callback = self::buildElasticsearchCallback($class, $wrapped);
        }

        $builder = resolve(Builder::class, [
            'model' => $wrapped,
            'query' => $query,
            'callback' => $callback,
        ]);

        $settings = resolve(SettingsRepositoryInterface::class);
        $limit = (int) $settings->get('clarkwinkelmann-scout.limit');

        $builder->take($limit > 0 ? $limit : 200);

        return $builder;
    }

    protected static function buildElasticsearchCallback(string $class, ScoutModelWrapper $model): callable
    {
        $highlightFields = Arr::get(self::$attributesToHighlight, $class, []);
        $queryFields = Arr::get(self::$attributesToQuery, $class, []);
        $indexName = $model->searchableAs();

        return function (\Elastic\Elasticsearch\Client $client, Search $body) use ($class, $indexName, $highlightFields, $queryFields) {
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

            if (isset($bodyArray['highlight'])) {
                $bodyArray['highlight']['encoder'] = self::$highlightConfig['encoder'];
            }

            $rawQuery = self::extractQueryStringFromBody($bodyArray);
            if ($rawQuery !== null) {
                $q = self::normalizeUserQuery($rawQuery);

                if ($q !== '') {
                    $fields = !empty($queryFields) ? array_values($queryFields) : ['*'];

                    $bodyArray['query'] = [
                        'bool' => [
                            'should' => [
                                // 短语匹配：词项顺序相邻，给更高分
                                [
                                    'multi_match' => [
                                        'query' => $q,
                                        'type' => 'phrase',
                                        'fields' => $fields,
                                        'boost' => 3,
                                    ],
                                ],
                                // AND 匹配：所有词项都必须出现
                                [
                                    'multi_match' => [
                                        'query' => $q,
                                        'type' => 'best_fields',
                                        'fields' => $fields,
                                        'operator' => 'and',
                                    ],
                                ],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ];
                } else {
                    $bodyArray['query'] = ['match_none' => (object) []];
                }
            }

            $result = $client->search([
                'index' => $indexName,
                'body'  => $bodyArray,
            ])->asArray();

            self::extractHighlights($result, $class);

            return $result;
        };
    }

    protected static function extractQueryStringFromBody(array $bodyArray): ?string
    {
        $q = Arr::get($bodyArray, 'query.query_string.query');
        if (is_string($q)) {
            return $q;
        }

        $q = Arr::get($bodyArray, 'query.bool.must.0.query_string.query');
        if (is_string($q)) {
            return $q;
        }

        return null;
    }

    protected static function normalizeUserQuery(string $q): string
    {
        $q = trim($q);
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        return $q;
    }

    protected static function extractHighlights(array $result, string $class): void
    {
        $hits = Arr::get($result, 'hits.hits', []);

        $key = match ($class) {
            Discussion::class => 'discussions',
            Post::class => 'posts',
            User::class => 'users',
            default => null,
        };

        if ($key === null) {
            return;
        }

        foreach ($hits as $hit) {
            $id = (int) ($hit['_id'] ?? 0);
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

    public static function extractIdsFromResult(mixed $results): array
    {
        if (!is_array($results)) {
            return [];
        }

        return collect(Arr::get($results, 'hits.hits', []))
            ->map(fn($hit) => (int) ($hit['_id'] ?? 0))
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();
    }
}
