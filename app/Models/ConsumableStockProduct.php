<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockProduct extends Model
{
    protected $table = 'consumable_stock_products';
    protected $primaryKey = 'consumable_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['expiry_required' => 'boolean', 'opening_assignments' => 'integer', 'expected_qty' => 'integer'];

    public function consumable()
    {
        return $this->belongsTo(Consumable::class);
    }
}
