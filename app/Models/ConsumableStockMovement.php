<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockMovement extends Model
{
    protected $table = 'consumable_stock_movements';
    protected $guarded = [];
    public $timestamps = false;

    public function lot()
    {
        return $this->belongsTo(ConsumableStockLot::class, 'lot_id');
    }
}
