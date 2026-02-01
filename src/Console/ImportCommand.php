<?php

namespace ClarkWinkelmann\Scout\Console;

use Illuminate\Contracts\Events\Dispatcher;

class ImportCommand extends \Laravel\Scout\Console\ImportCommand
{
    use ModifiedImportTrait;

    public function handle(Dispatcher $events)
    {
        $class = $this->argument('model');

        // 验证模型类是否在已注册的 searchable 模型列表中
        $registeredClasses = array_keys(resolve('scout.attributes'));
        if (!in_array($class, $registeredClasses)) {
            $this->error("Model [$class] is not registered as searchable. Available models: " . implode(', ', $registeredClasses));
            return 1;
        }

        $this->handleClass($events, $class);
    }
}
