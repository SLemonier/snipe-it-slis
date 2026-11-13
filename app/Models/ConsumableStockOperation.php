<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableStockOperation extends Model
{
    protected $table = 'consumable_stock_operations';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ConsumableStockMovement::class, 'operation_id');
    }
}
