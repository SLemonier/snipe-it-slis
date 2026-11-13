<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsumableStockOperation extends Model
{
    protected $table = 'consumable_stock_operations';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function movements()
    {
        return $this->hasMany(ConsumableStockMovement::class, 'operation_id');
    }
}
