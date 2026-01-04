<?php

namespace ClarkWinkelmann\Scout\Extend;

use ClarkWinkelmann\Scout\FlarumSearchableScope;
use ClarkWinkelmann\Scout\ScoutModelWrapper;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ContainerUtil;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

class Scout implements ExtenderInterface
{
    protected $modelClass;
    protected $attributes = [];
    protected $searchable = [];
    protected $listenSaved = [];
    protected $listenDeleted = [];

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    public function attributes($callback): self
    {
        $this->attributes[] = $callback;
        return $this;
    }

    public function searchable($callback): self
    {
        $this->searchable[] = $callback;
        return $this;
    }

    public function listenSaved(string $eventClass, $callback): self
    {
        $this->listenSaved[] = [$eventClass, $callback];
        return $this;
    }

    public function listenDeleted(string $eventClass, $callback): self
    {
        $this->listenDeleted[] = [$eventClass, $callback];
        return $this;
    }

    public function extend(Container $container, Extension $extension = null)
    {
        if (!class_exists($this->modelClass)) {
            return;
        }

        $manager = $container->make(ExtensionManager::class);

        if (!$manager->isEnabled('lady-byron-scout')) {
            return;
        }

        $this->modelClass::addGlobalScope(new FlarumSearchableScope());

        // [FIX #10] 修复原版 bug：循环内正确注册每个 callback
        if (count($this->attributes)) {
            $container->extend('scout.attributes', function (array $attributes) use ($container) {
                foreach ($this->attributes as $callback) {
                    $wrappedCallback = ContainerUtil::wrapCallback($callback, $container);
                    $attributes[$this->modelClass][] = $wrappedCallback;
                }
                return $attributes;
            });
        }

        // [FIX #10] 修复原版 bug：循环内正确注册每个 callback
        if (count($this->searchable)) {
            $container->extend('scout.searchable', function (array $searchable) use ($container) {
                foreach ($this->searchable as $callback) {
                    $wrappedCallback = ContainerUtil::wrapCallback($callback, $container);
                    $searchable[$this->modelClass][] = $wrappedCallback;
                }
                return $searchable;
            });
        }

        if (count($this->listenSaved) || count($this->listenDeleted)) {
            $events = $container->make(Dispatcher::class);
            $app = $container->make('flarum');

            $app->booted(function () use ($events, $container) {
                foreach ($this->listenSaved as $listener) {
                    $events->listen($listener[0], function ($event) use ($listener, $container) {
                        $model = ContainerUtil::wrapCallback($listener[1], $container)($event);

                        if ($model) {
                            (new ScoutModelWrapper($model))->scoutObserverSaved();
                        }
                    });
                }

                foreach ($this->listenDeleted as $listener) {
                    $events->listen($listener[0], function ($event) use ($listener, $container) {
                        $model = ContainerUtil::wrapCallback($listener[1], $container)($event);

                        if ($model) {
                            (new ScoutModelWrapper($model))->scoutObserverDeleted();
                        }
                    });
                }
            });
        }
    }
}
