<?php

namespace App\Http\Transformers;

use App\Models\ConsumableStockOperation;
use App\Models\ConsumableStockPlace;

class ConsumableStockTransformer
{
    public function operation(ConsumableStockOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'action' => $operation->action,
            'actor_id' => $operation->actor_id,
            'note' => $operation->note,
            'created_at' => $operation->created_at->toIso8601String(),
            'details' => $operation->details,
            'movements' => $operation->movements->map(fn ($movement) => [
                'lot_id' => $movement->lot_id,
                'from_place_id' => $movement->from_place_id,
                'to_place_id' => $movement->to_place_id,
                'quantity' => $movement->quantity,
            ])->all(),
        ];
    }

    public function place(ConsumableStockPlace $place): array
    {
        return [
            'id' => $place->id, 'name' => $place->name, 'path' => $place->displayPath(),
            'kind' => $place->kind, 'parent_id' => $place->parent_id,
            'company_id' => $place->company_id, 'asset_id' => $place->asset_id, 'location_id' => $place->location_id,
        ];
    }
}
