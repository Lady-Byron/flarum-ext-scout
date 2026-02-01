<?php

namespace ClarkWinkelmann\Scout\Console;

use ClarkWinkelmann\Scout\ScoutStatic;

class FlushCommand extends \Laravel\Scout\Console\FlushCommand
{
    public function handle()
    {
        $class = $this->argument('model');

        // 验证模型类是否在已注册的 searchable 模型列表中
        $registeredClasses = array_keys(resolve('scout.attributes'));
        if (!in_array($class, $registeredClasses)) {
            $this->error("Model [$class] is not registered as searchable. Available models: " . implode(', ', $registeredClasses));
            return 1;
        }

        ScoutStatic::removeAllFromSearch($class);

        $this->info('All [' . $class . '] records have been flushed.');
    }
}
