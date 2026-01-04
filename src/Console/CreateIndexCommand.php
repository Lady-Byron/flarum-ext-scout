<?php

namespace ClarkWinkelmann\Scout\Console;

use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;

class CreateIndexCommand extends Command
{
    protected $signature = 'scout:create-index 
        {--analyzer=standard : Text field analyzer}
        {--search-analyzer= : Search-time analyzer}
        {--recreate : Delete existing index and recreate}
        {--shards=1 : Number of primary shards}
        {--replicas=0 : Number of replica shards}';

    protected $description = 'Create Elasticsearch indices';

    protected array $indices = [
        'discussions' => [
            'text_fields' => ['title'],
            'integer_fields' => ['id'],
        ],
        'posts' => [
            'text_fields' => ['content'],
            'integer_fields' => ['id'],
        ],
        'users' => [
            'text_fields' => ['username', 'displayName', 'bio'],
            'integer_fields' => ['id'],
        ],
    ];

    public function handle(ElasticsearchClient $client, SettingsRepositoryInterface $settings)
    {
        $analyzer = $this->option('analyzer');
        $searchAnalyzer = $this->option('search-analyzer');
        $recreate = $this->option('recreate');
        $shards = (int) $this->option('shards');
        $replicas = (int) $this->option('replicas');

        if (!$searchAnalyzer && $analyzer === 'ik_max_word') {
            $searchAnalyzer = 'ik_smart';
            $this->info("Auto-setting search_analyzer to 'ik_smart'");
        }

        $prefix = $settings->get('clarkwinkelmann-scout.prefix') ?: '';

        $this->info("Creating indices with analyzer: {$analyzer}");

        foreach ($this->indices as $table => $fields) {
            $indexName = $prefix . $table;

            $exists = $client->indices()->exists(['index' => $indexName])->asBool();

            if ($exists) {
                if ($recreate) {
                    $this->warn("Deleting existing index: {$indexName}");
                    $client->indices()->delete(['index' => $indexName]);
                } else {
                    $this->error("Index '{$indexName}' already exists. Use --recreate to delete and recreate.");
                    continue;
                }
            }

            $properties = $this->buildProperties($fields, $analyzer, $searchAnalyzer);

            $params = [
                'index' => $indexName,
                'body' => [
                    'settings' => [
                        'number_of_shards' => $shards,
                        'number_of_replicas' => $replicas,
                    ],
                    'mappings' => [
                        'properties' => $properties,
                    ],
                ],
            ];

            try {
                $client->indices()->create($params);
                $this->info("✓ Created index: {$indexName}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to create index '{$indexName}': " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('Done! Now run: php flarum scout:import-all');
    }

    protected function buildProperties(array $fields, string $analyzer, ?string $searchAnalyzer): array
    {
        $properties = [];

        foreach ($fields['text_fields'] ?? [] as $field) {
            $property = ['type' => 'text', 'analyzer' => $analyzer];
            if ($searchAnalyzer) {
                $property['search_analyzer'] = $searchAnalyzer;
            }
            $properties[$field] = $property;
        }

        foreach ($fields['integer_fields'] ?? [] as $field) {
            $properties[$field] = ['type' => 'integer'];
        }

        return $properties;
    }
}
