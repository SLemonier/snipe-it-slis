<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableStockBalance as Balance;
use App\Models\ConsumableStockLot as Lot;
use App\Models\ConsumableStockMovement as Movement;
use App\Models\ConsumableStockOperation as Operation;
use App\Models\ConsumableStockPlace as Place;
use App\Models\ConsumableStockProduct as Product;
use App\Models\ConsumableStockSetting;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConsumableStock
{
    public function execute(Consumable $item, array $data): Operation
    {
        Gate::authorize('view', $item);
        $action = $data['action'];
        Gate::authorize(in_array($action, ['transfer', 'consume']) ? 'checkout' : 'update', $item);
        $this->check(ConsumableStockSetting::current()->enabled, 'disabled');

        return DB::transaction(function () use ($item, $data, $action) {
            $locked = Consumable::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $fingerprintData = $data;
            unset($fingerprintData['operation_id']);
            ksort($fingerprintData);
            $fingerprint = hash('sha256', json_encode([$locked->id, auth()->id(), $fingerprintData]));
            $existing = Operation::find($data['operation_id']);
            if ($existing) {
                $this->check(hash_equals($existing->fingerprint, $fingerprint), 'duplicate');
                return $existing;
            }

            $product = Product::find($locked->id);
            if ($action === 'enable') {
                $this->check(!$product, 'already_enabled');
            } else {
                $this->check($product !== null, 'not_enabled');
                // Detect any legacy direct SQL/import path before it can corrupt this ledger.
                $this->check((int) $locked->getRawOriginal('qty') === $product->expected_qty
                    && $this->assignmentCount($locked) === $product->opening_assignments, 'legacy_changed');
            }

            $operation = Operation::create([
                'id' => $data['operation_id'], 'consumable_id' => $locked->id,
                'actor_id' => auth()->id(), 'action' => $action, 'fingerprint' => $fingerprint,
                'note' => $data['note'], 'details' => $data, 'created_at' => now(),
            ]);

            if ($action === 'enable') {
                $place = $this->place($locked, $data['to_place_id']);
                $qty = (int) $locked->getRawOriginal('qty');
                $assigned = $this->assignmentCount($locked);
                $this->check($qty >= $assigned, 'legacy_changed');
                $product = Product::create([
                    'consumable_id' => $locked->id, 'unit' => $data['unit'],
                    'expiry_required' => $data['expiry_required'] ?? true,
                    'opening_qty' => $qty, 'opening_assignments' => $assigned,
                    'expected_qty' => $qty, 'enabled_by' => auth()->id(),
                ]);
                if ($qty > $assigned) {
                    $lot = Lot::create([
                        'consumable_id' => $locked->id,
                        'number' => 'OPENING-'.$operation->id,
                        'unidentified' => true,
                    ]);
                    $this->add($lot, $place, $qty - $assigned);
                    $this->record($operation, $lot, null, $place->id, $qty - $assigned);
                }
                return $operation;
            }

            if ($action === 'minimum') {
                $place = $this->place($locked, $data['to_place_id']);
                DB::table('consumable_stock_minimums')->updateOrInsert(
                    ['consumable_id' => $locked->id, 'place_id' => $place->id],
                    ['quantity' => $data['quantity']]
                );
                return $operation;
            }

            if ($action === 'amend_lot') {
                $lot = $this->lot($locked, $data['lot_id']);
                $this->check(!$lot->unidentified, 'not_unidentified');
                $this->check(!$product->expiry_required || !empty($data['expires_on']), 'expiry_required');
                $this->check(!Lot::where('consumable_id', $locked->id)->where('number', $data['number'])
                    ->where('id', '!=', $lot->id)->exists(), 'lot_conflict');
                $operation->details = array_merge($data, ['previous_number' => $lot->number,
                    'previous_expires_on' => $lot->expires_on?->toDateString()]);
                $operation->save();
                $lot->number = $data['number'];
                $lot->expires_on = $data['expires_on'] ?? null;
                $lot->save();
                return $operation;
            }

            if ($action === 'quarantine') {
                $lot = $this->lot($locked, $data['lot_id']);
                $operation->details = array_merge($data, ['previous_quarantined' => $lot->quarantined]);
                $operation->save();
                $lot->quarantined = $data['quarantined'];
                $lot->save();
                return $operation;
            }

            if ($action === 'receive' || ($action === 'adjust' && $data['quantity'] > 0)) {
                $place = $this->place($locked, $data['to_place_id']);
                $lot = $this->resolveLot($locked, $product, $data);
                $this->add($lot, $place, $data['quantity']);
                $this->record($operation, $lot, null, $place->id, $data['quantity']);
                $this->adjustLegacyProjection($locked, $product, $data['quantity']);
                return $operation;
            }

            if ($action === 'identify') {
                $source = $this->lot($locked, $data['lot_id']);
                $this->check($source->unidentified, 'not_unidentified');
                $place = $this->place($locked, $data['from_place_id']);
                $target = $this->resolveLot($locked, $product, $data);
                $this->take($source, $place, $data['quantity']);
                $this->add($target, $place, $data['quantity']);
                $this->record($operation, $source, $place->id, null, $data['quantity']);
                $this->record($operation, $target, null, $place->id, $data['quantity']);
                return $operation;
            }

            $sourcePlace = $this->place($locked, $data['from_place_id']);
            $targetPlace = $action === 'transfer' ? $this->place($locked, $data['to_place_id']) : null;
            $this->check(!$targetPlace || $targetPlace->id !== $sourcePlace->id, 'same_place');
            $quantity = abs($data['quantity']);
            $remaining = $quantity;

            if (!empty($data['lot_id'])) {
                $lots = collect([$this->lot($locked, $data['lot_id'])]);
            } else {
                // The operator can choose the actual lot; omitted lot invokes FEFO.
                $lots = Lot::where('consumable_id', $locked->id)
                    ->whereHas('balances', fn ($q) => $q->where('place_id', $sourcePlace->id)->where('quantity', '>', 0))
                    ->orderByRaw('expires_on IS NULL')->orderBy('expires_on')->orderBy('id')->get();
            }

            foreach ($lots as $lot) {
                if (in_array($action, ['transfer', 'consume']) && !$lot->usable($product->expiry_required)) {
                    if (!empty($data['lot_id'])) {
                        $this->check(false, 'unusable');
                    }
                    continue;
                }
                $balance = Balance::where('lot_id', $lot->id)->where('place_id', $sourcePlace->id)->first();
                $take = min($remaining, $balance?->quantity ?? 0);
                if ($take === 0) {
                    continue;
                }
                $this->take($lot, $sourcePlace, $take);
                if ($targetPlace) {
                    $this->add($lot, $targetPlace, $take);
                }
                $this->record($operation, $lot, $sourcePlace->id, $targetPlace?->id, $take);
                $remaining -= $take;
                if ($remaining === 0) {
                    break;
                }
            }
            $this->check($remaining === 0, 'insufficient');
            if (!$targetPlace) {
                $this->adjustLegacyProjection($locked, $product, -$quantity);
            }
            return $operation;
        }, 3);
    }

    public function savePlace(array $data, ?Place $place = null): Place
    {
        Gate::authorize('superuser');
        return DB::transaction(function () use ($data, $place) {
            // A stable singleton lock serializes parent moves and prevents concurrent cycles.
            DB::table('consumable_stock_settings')->insertOrIgnore([
                'id' => 1, 'enabled' => false, 'expiry_days' => 60, 'reminder_days' => 7,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('consumable_stock_settings')->where('id', 1)->lockForUpdate()->first();
            $place = $place ? Place::whereKey($place->id)->lockForUpdate()->firstOrFail() : new Place;
            $company = isset($data['company_id']) ? (int) $data['company_id'] : null;
            if ($place->exists) {
                $this->check($place->company_id === $company, 'place_company');
            }
            $parent = !empty($data['parent_id']) ? Place::find($data['parent_id']) : null;
            $this->check(empty($data['parent_id']) || $parent !== null, 'invalid_reference');
            $cursor = $parent;
            while ($cursor) {
                $this->check(!$place->exists || $cursor->id !== $place->id, 'cycle');
                $this->check($cursor->company_id === $company, 'company');
                $cursor = $cursor->parent;
            }
            foreach (['asset_id' => Asset::class, 'location_id' => Location::class] as $key => $model) {
                if (!empty($data[$key])) {
                    $linked = $model::findOrFail($data[$key]);
                    $this->check(($linked->company_id === null ? null : (int) $linked->company_id) === $company, 'company');
                }
            }
            $before = $place->exists ? $place->getAttributes() : null;
            $place->fill($data)->save();
            DB::table('consumable_stock_place_events')->insert([
                'place_id' => $place->id, 'actor_id' => auth()->id(),
                'before_state' => $before ? json_encode($before) : null,
                'after_state' => json_encode($place->getAttributes()), 'created_at' => now(),
            ]);
            return $place;
        }, 3);
    }

    public function snapshot(Consumable $item): array
    {
        Gate::authorize('view', $item);
        $product = Product::find($item->id);
        $lots = Lot::where('consumable_id', $item->id)->with(['balances.place.parent'])->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')->orderBy('id')->get();
        $physical = 0;
        $usable = 0;
        $rows = [];
        foreach ($lots as $lot) {
            foreach ($lot->balances as $balance) {
                if ($balance->quantity === 0) {
                    continue;
                }
                $available = $lot->usable($product?->expiry_required ?? true);
                $physical += $balance->quantity;
                $usable += $available ? $balance->quantity : 0;
                $rows[] = [
                    'lot_id' => $lot->id, 'number' => $lot->number,
                    'expires_on' => $lot->expires_on?->toDateString(),
                    'place_id' => $balance->place_id, 'place' => $balance->place?->displayPath(),
                    'quantity' => $balance->quantity, 'usable' => $available,
                    'unidentified' => $lot->unidentified, 'quarantined' => $lot->quarantined,
                ];
            }
        }
        $minimums = DB::table('consumable_stock_minimums')->where('consumable_id', $item->id)->get()
            ->map(function ($minimum) use ($rows) {
                $available = collect($rows)->where('place_id', $minimum->place_id)->where('usable', true)->sum('quantity');
                return ['place_id' => $minimum->place_id, 'minimum' => $minimum->quantity,
                    'available' => $available, 'low' => $available < $minimum->quantity];
            })->all();

        return [
            'consumable_id' => $item->id, 'enabled' => $product !== null,
            'unit' => $product?->unit, 'expiry_required' => $product?->expiry_required,
            'physical' => $physical, 'usable' => $usable, 'unavailable' => $physical - $usable,
            'legacy_assignments' => $product?->opening_assignments ?? $this->assignmentCount($item),
            'consistent' => !$product || ((int) $item->getRawOriginal('qty') === $product->expected_qty
                && $this->assignmentCount($item) === $product->opening_assignments),
            'balances' => $rows, 'minimums' => $minimums,
        ];
    }

    private function assignmentCount(Consumable $item): int
    {
        return DB::table('consumables_users')->where('consumable_id', $item->id)->count();
    }

    private function place(Consumable $item, int $id): Place
    {
        $place = Place::find($id);
        $this->check($place !== null, 'invalid_reference');
        $this->check(Company::isCurrentUserHasAccess($place)
            && ($place->company_id === ($item->company_id === null ? null : (int) $item->company_id)), 'company');
        return $place;
    }

    private function lot(Consumable $item, int $id): Lot
    {
        $lot = Lot::where('consumable_id', $item->id)->find($id);
        $this->check($lot !== null, 'invalid_reference');
        return $lot;
    }

    private function resolveLot(Consumable $item, Product $product, array $data): Lot
    {
        $expiry = $data['expires_on'] ?? null;
        $this->check(!$product->expiry_required || $expiry !== null, 'expiry_required');
        $lot = Lot::firstOrNew(['consumable_id' => $item->id, 'number' => $data['number']]);
        if ($lot->exists) {
            $this->check(!$lot->unidentified && $lot->expires_on?->toDateString() === $expiry, 'lot_conflict');
        } else {
            $lot->expires_on = $expiry;
            $lot->save();
        }
        return $lot;
    }

    private function add(Lot $lot, Place $place, int $quantity): void
    {
        $balance = Balance::firstOrNew(['lot_id' => $lot->id, 'place_id' => $place->id]);
        $newQuantity = ($balance->quantity ?? 0) + $quantity;
        $this->check($newQuantity <= 99999, 'quantity_limit');
        $balance->quantity = $newQuantity;
        $balance->save();
    }

    private function take(Lot $lot, Place $place, int $quantity): void
    {
        $balance = Balance::where('lot_id', $lot->id)->where('place_id', $place->id)->first();
        $this->check($balance !== null && $balance->quantity >= $quantity, 'insufficient');
        $balance->quantity -= $quantity;
        $balance->save();
    }

    private function record(Operation $operation, Lot $lot, ?int $from, ?int $to, int $quantity): void
    {
        Movement::create(['operation_id' => $operation->id, 'lot_id' => $lot->id,
            'from_place_id' => $from, 'to_place_id' => $to, 'quantity' => $quantity]);
    }

    private function adjustLegacyProjection(Consumable $item, Product $product, int $delta): void
    {
        $next = $product->expected_qty + $delta;
        $this->check($next >= $product->opening_assignments && $next <= 99999, 'quantity_limit');
        // This preserves all old assignment/history rows. The original total is recorded in the profile.
        DB::table('consumables')->where('id', $item->id)->update(['qty' => $next, 'updated_at' => now()]);
        $product->expected_qty = $next;
        $product->save();
    }

    private function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw ValidationException::withMessages(['stock' => trans('consumable_stock.errors.'.$message)]);
        }
    }
}
