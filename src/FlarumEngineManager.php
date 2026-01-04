<?php

namespace ClarkWinkelmann\Scout;

use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Flarum\Settings\SettingsRepositoryInterface;
use Laravel\Scout\EngineManager;
use Matchish\ScoutElasticSearch\Engines\ElasticSearchEngine;

class FlarumEngineManager extends EngineManager
{
    /**
     * Create Elasticsearch driver
     */
    public function createElasticsearchDriver(): ElasticSearchEngine
    {
        return new ElasticSearchEngine(resolve(ElasticsearchClient::class));
    }

    public function getDefaultDriver()
    {
        $settings = resolve(SettingsRepositoryInterface::class);
        $driver = $settings->get('clarkwinkelmann-scout.driver');

        if ($driver === 'elasticsearch') {
            return 'elasticsearch';
        }

        return 'null';
    }
}
