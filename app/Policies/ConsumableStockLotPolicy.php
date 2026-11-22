<?php

namespace App\Policies;

use App\Models\Consumable;
use App\Models\ConsumableStockLot;
use App\Models\User;

class ConsumableStockLotPolicy
{
    public function view(User $user, ?ConsumableStockLot $lot = null): bool
    {
        if ($lot === null) {
            return $user->can('view', Consumable::class);
        }

        return $lot->consumable !== null && $user->can('view', $lot->consumable);
    }
}
