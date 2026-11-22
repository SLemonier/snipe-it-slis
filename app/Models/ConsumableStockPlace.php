<?php

namespace App\Models;

use App\Models\Traits\CompanyableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableStockPlace extends Model
{
    use CompanyableTrait;

    protected $table = 'consumable_stock_places';

    protected $guarded = [];

    protected $casts = ['company_id' => 'integer', 'parent_id' => 'integer'];

    public static function locationPath(Location $location): string
    {
        $names = [$location->name];
        $seen = [$location->id];
        $parent = $location->parent;
        while ($parent && ! in_array($parent->id, $seen, true)) {
            array_unshift($names, $parent->name);
            $seen[] = $parent->id;
            $parent = $parent->parent;
        }

        return implode(' / ', $names);
    }

    /** @return BelongsTo<ConsumableStockPlace, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function displayPath(): string
    {
        if ($this->location_id && $this->location) {
            return self::locationPath($this->location);
        }
        $names = [$this->name];
        $seen = [$this->id];
        $parent = $this->parent;
        while ($parent && ! in_array($parent->id, $seen, true)) {
            array_unshift($names, $parent->name);
            $seen[] = $parent->id;
            $parent = $parent->parent;
        }

        return implode(' / ', $names);
    }
}
