<?php

namespace ClarkWinkelmann\Scout\Console;

use Flarum\Post\Post;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

class ImportAllCommand extends Command
{
    use ModifiedImportTrait;

    protected $signature = 'scout:import-all {--c|chunk= : The number of records to import at a time}';
    protected $description = 'Import all Flarum models into the search index';

    public function handle(Dispatcher $events, Container $container)
    {
        $classes = array_keys($container->make('scout.attributes'));

        foreach ($classes as $class) {
            // [FIX #8] 防止 class_parents() 返回 false
            if (in_array(Post::class, class_parents($class) ?: [])) {
                continue;
            }
            $this->handleClass($events, $class);
        }
    }
}
