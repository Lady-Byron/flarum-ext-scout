<?php

namespace ClarkWinkelmann\Scout\Search;

use ClarkWinkelmann\Scout\ScoutStatic;
use Flarum\Search\GambitInterface;
use Flarum\Search\SearchState;
use Flarum\User\User;

class UserGambit implements GambitInterface
{
    public function apply(SearchState $search, $bit)
    {
        ScoutStatic::clearHighlights();

        $builder = ScoutStatic::makeBuilder(User::class, $bit);
        $ids = ScoutStatic::extractIdsFromResult($builder->raw());

        $search->getQuery()->whereIn('id', $ids);

        $search->setDefaultSort(function ($query) use ($ids) {
            if (count($ids)) {
                $query->orderByRaw('FIELD(id' . str_repeat(', ?', count($ids)) . ')', $ids);
            }
        });
    }
}
