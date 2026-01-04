<?php

namespace ClarkWinkelmann\Scout;

use ClarkWinkelmann\Scout\Job\MakeSearchable;
use ClarkWinkelmann\Scout\Job\RemoveFromSearch;
use ClarkWinkelmann\Scout\Search\ImprovedGambitManager;
use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Elastic\Elasticsearch\ClientBuilder as ElasticsearchClientBuilder;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Search\GambitManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Scout;

class ScoutServiceProvider extends AbstractServiceProvider
{
    public function register()
    {
        // Elasticsearch 8.x 客户端
        $this->container->singleton(ElasticsearchClient::class, function () {
            $settings = $this->container->make(SettingsRepositoryInterface::class);
            $host = $settings->get('clarkwinkelmann-scout.elasticsearchHost') ?: 'localhost:9200';

            $builder = ElasticsearchClientBuilder::create()->setHosts([$host]);

            $authType = $settings->get('clarkwinkelmann-scout.elasticsearchAuthType') ?: 'none';
            if ($authType === 'basic') {
                $username = $settings->get('clarkwinkelmann-scout.elasticsearchUsername');
                $password = $settings->get('clarkwinkelmann-scout.elasticsearchPassword');
                if ($username && $password) {
                    $builder->setBasicAuthentication($username, $password);
                }
            } elseif ($authType === 'apikey') {
                $apiKey = $settings->get('clarkwinkelmann-scout.elasticsearchApiKey');
                if ($apiKey) {
                    $builder->setApiKey($apiKey);
                }
            }

            if ($settings->get('clarkwinkelmann-scout.elasticsearchSslVerification') === '0') {
                $builder->setSSLVerification(false);
            }

            return $builder->build();
        });

        $this->container->singleton(EngineManager::class, function ($app) {
            return new FlarumEngineManager($app);
        });

        Scout::makeSearchableUsing(MakeSearchableDisable::class);
        Scout::removeFromSearchUsing(MakeSearchableDisable::class);

        $this->container->singleton('scout.searchable', function () {
            return [];
        });

        $this->container->singleton('scout.attributes', function () {
            return [];
        });

        // 最小查询长度设置
        $this->container->resolving('flarum.assets.forum', function (Assets $assets) {
            $settings = $this->container->make(SettingsRepositoryInterface::class);
            $length = (int)$settings->get('clarkwinkelmann-scout.queryMinLength');
            if ($length > 0) {
                $assets->js(function (SourceCollector $sources) use ($length) {
                    $sources->addString(function () use ($length) {
                        return "app.initializers.add('scout-min-length',function(){flarum.core.compat['components/Search'].MIN_SEARCH_LEN=$length});";
                    });
                });
            }
        });
    }

    public function boot()
    {
        Collection::macro('searchable', function () {
            if ($this->isEmpty()) {
                return;
            }

            $wrappedCollection = $this->map(function ($model) {
                if ($model instanceof ScoutModelWrapper) {
                    return $model;
                }
                return new ScoutModelWrapper($model);
            });

            $first = $wrappedCollection->first();
            $settings = resolve(SettingsRepositoryInterface::class);

            if (!$settings->get('clarkwinkelmann-scout.queue')) {
                return $first->searchableUsing()->update($wrappedCollection);
            }

            resolve(Dispatcher::class)->dispatch(new MakeSearchable($wrappedCollection));
        });

        Collection::macro('unsearchable', function () {
            if ($this->isEmpty()) {
                return;
            }

            $wrappedCollection = $this->map(function ($model) {
                if ($model instanceof ScoutModelWrapper) {
                    return $model;
                }
                return new ScoutModelWrapper($model);
            });

            $first = $wrappedCollection->first();
            $settings = resolve(SettingsRepositoryInterface::class);

            if (!$settings->get('clarkwinkelmann-scout.queue')) {
                return $first->searchableUsing()->delete($wrappedCollection);
            }

            resolve(Dispatcher::class)->dispatch(new RemoveFromSearch($wrappedCollection));
        });

        // Override GambitManager
        $fullTextGambits = $this->container->make('flarum.simple_search.fulltext_gambits');
        foreach ($fullTextGambits as $searcher => $fullTextGambitClass) {
            $this->container
                ->when($searcher)
                ->needs(GambitManager::class)
                ->give(function () use ($searcher, $fullTextGambitClass) {
                    $gambitManager = new ImprovedGambitManager($this->container->make($fullTextGambitClass));
                    foreach (Arr::get($this->container->make('flarum.simple_search.gambits'), $searcher, []) as $gambit) {
                        $gambitManager->add($this->container->make($gambit));
                    }
                    return $gambitManager;
                });
        }
    }
}
