<?php

namespace Tests\Feature\ConsumableStock;

use App\Models\Consumable;
use App\Models\ConsumableStockBalance as Balance;
use App\Models\ConsumableStockLot as Lot;
use App\Models\ConsumableStockOperation as Operation;
use App\Models\ConsumableStockPlace as Place;
use App\Models\ConsumableStockProduct as Product;
use App\Models\ConsumableStockSetting;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockLedgerTest extends TestCase
{
    private ConsumableStock $stock;
    private Consumable $item;
    private Place $reserve;
    private Place $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 13)->startOfDay());
        $this->actingAs(User::factory()->superuser()->create());
        ConsumableStockSetting::create(['id' => 1, 'enabled' => true, 'expiry_days' => 60, 'reminder_days' => 7]);
        $this->stock = app(ConsumableStock::class);
        $this->item = Consumable::factory()->create(['qty' => 0, 'company_id' => null]);
        $this->reserve = Place::create(['name' => 'Reserve', 'kind' => 'reserve', 'company_id' => null]);
        $this->vehicle = Place::create(['name' => 'VSAV 1', 'kind' => 'vehicle', 'company_id' => null]);
    }

    private function operation(string $action, array $values = []): Operation
    {
        return $this->stock->execute($this->item, array_merge([
            'action' => $action, 'operation_id' => (string) Str::uuid(), 'note' => 'Verified physical operation',
        ], $values));
    }

    private function enable(bool $expiry = true): void
    {
        $this->operation('enable', ['unit' => 'piece', 'expiry_required' => $expiry, 'to_place_id' => $this->reserve->id]);
    }

    private function receive(int $quantity = 10, string $number = 'A', ?string $expiry = '2027-01-01'): Lot
    {
        $this->operation('receive', ['quantity' => $quantity, 'number' => $number,
            'expires_on' => $expiry, 'to_place_id' => $this->reserve->id]);
        return Lot::where('number', $number)->firstOrFail();
    }

    public function test_opening_preserves_original_inventory_assignments_and_history(): void
    {
        $this->item->qty = 12;
        $this->item->save();
        $this->item->users()->attach(auth()->id(), ['assigned_to' => auth()->id(), 'created_by' => auth()->id()]);
        $beforeItem = DB::table('consumables')->where('id', $this->item->id)->first();
        $beforeAssignments = DB::table('consumables_users')->get()->toJson();
        $beforeLogs = DB::table('action_logs')->get()->toJson();

        $this->enable();
        $this->assertEquals($beforeItem, DB::table('consumables')->where('id', $this->item->id)->first());
        $this->assertSame($beforeAssignments, DB::table('consumables_users')->get()->toJson());
        $this->assertSame($beforeLogs, DB::table('action_logs')->get()->toJson());
        $this->assertEquals(11, Balance::sum('quantity'));
        $this->assertNull(Lot::first()->expires_on);
        $this->assertTrue(Lot::first()->unidentified);
        $this->assertSame(0, $this->item->fresh()->numRemaining());

        $this->operation('identify', ['lot_id' => Lot::first()->id, 'from_place_id' => $this->reserve->id,
            'quantity' => 4, 'number' => 'KNOWN', 'expires_on' => '2027-01-01']);
        $this->assertEquals(11, Balance::sum('quantity'));
        $this->assertEquals(12, $this->item->fresh()->qty);
        $this->assertEquals(7, Lot::where('unidentified', true)->first()->balances()->sum('quantity'));
        $this->assertSame(4, $this->item->fresh()->numRemaining());
    }

    public function test_transfer_preserves_lot_and_total_then_consumption_decreases_stock(): void
    {
        $this->enable();
        $lot = $this->receive();
        $this->operation('transfer', ['lot_id' => $lot->id, 'from_place_id' => $this->reserve->id,
            'to_place_id' => $this->vehicle->id, 'quantity' => 4]);
        $this->assertEquals(10, Balance::sum('quantity'));
        $this->assertEquals(10, $this->item->fresh()->qty);
        $this->operation('consume', ['lot_id' => $lot->id, 'from_place_id' => $this->vehicle->id, 'quantity' => 2]);
        $this->assertEquals(8, Balance::sum('quantity'));
        $this->assertEquals(8, $this->item->fresh()->qty);
        $this->assertEquals(2, Balance::where('place_id', $this->vehicle->id)->sum('quantity'));
        $this->assertDatabaseCount('consumables_users', 0);
    }

    public function test_fefo_splits_a_transfer_across_actual_lots(): void
    {
        $this->enable();
        $later = $this->receive(4, 'LATER', '2027-07-01');
        $earlier = $this->receive(2, 'EARLIER', '2027-01-01');
        $op = $this->operation('transfer', ['from_place_id' => $this->reserve->id,
            'to_place_id' => $this->vehicle->id, 'quantity' => 5]);
        $this->assertEquals(2, $op->movements()->where('lot_id', $earlier->id)->sum('quantity'));
        $this->assertEquals(3, $op->movements()->where('lot_id', $later->id)->sum('quantity'));
        $this->assertEquals(6, Balance::sum('quantity'));
    }

    public function test_failed_split_rolls_back_every_balance_and_audit_row(): void
    {
        $this->enable();
        $this->receive(2, 'A');
        $this->receive(2, 'B');
        $before = Balance::orderBy('id')->get()->toJson();
        $ops = Operation::count();
        try {
            $this->operation('consume', ['from_place_id' => $this->reserve->id, 'quantity' => 5]);
            $this->fail('Expected insufficient-stock rejection.');
        } catch (ValidationException $e) {
            $this->assertSame($before, Balance::orderBy('id')->get()->toJson());
            $this->assertSame($ops, Operation::count());
            $this->assertEquals(4, $this->item->fresh()->qty);
        }
    }

    public function test_replayed_request_is_idempotent_and_conflicting_reuse_is_rejected(): void
    {
        $this->enable();
        $lot = $this->receive();
        $data = ['operation_id' => (string) Str::uuid(), 'lot_id' => $lot->id,
            'from_place_id' => $this->reserve->id, 'quantity' => 2];
        $first = $this->operation('consume', $data);
        $second = $this->operation('consume', $data);
        $this->assertSame($first->id, $second->id);
        $this->assertEquals(8, Balance::sum('quantity'));
        $this->expectException(ValidationException::class);
        $this->operation('consume', array_merge($data, ['quantity' => 3]));
    }

    public function test_expired_stock_remains_physical_and_cannot_be_used(): void
    {
        $this->enable();
        $lot = $this->receive(5, 'OLD', '2026-11-12');
        $snapshot = $this->stock->snapshot($this->item->fresh());
        $this->assertEquals(5, $snapshot['physical']);
        $this->assertEquals(0, $snapshot['usable']);
        try {
            $this->operation('consume', ['lot_id' => $lot->id, 'from_place_id' => $this->reserve->id, 'quantity' => 1]);
            $this->fail('Expired lot must not be used.');
        } catch (ValidationException $e) {
            $this->assertEquals(5, Balance::sum('quantity'));
        }
        $this->operation('withdraw', ['lot_id' => $lot->id, 'from_place_id' => $this->reserve->id, 'quantity' => 5]);
        $this->assertEquals(0, Balance::sum('quantity'));
        $this->assertDatabaseHas('consumable_stock_lots', ['id' => $lot->id]);
    }

    public function test_expiry_is_inclusive_through_the_printed_date(): void
    {
        $this->enable();
        $this->receive(3, 'TODAY', '2026-11-13');
        $this->assertSame(3, $this->item->fresh()->numRemaining());
        $this->travel(1)->days();
        $this->assertSame(0, $this->item->fresh()->numRemaining());
    }

    public function test_same_lot_cannot_silently_change_expiry_on_receipt(): void
    {
        $this->enable();
        $this->receive();
        try {
            $this->receive(5, 'A', '2028-01-01');
            $this->fail('Conflicting lot details must be rejected.');
        } catch (ValidationException $e) {
            $this->assertEquals(10, Balance::sum('quantity'));
            $this->assertEquals(10, $this->item->fresh()->qty);
        }
    }

    public function test_quarantine_and_lot_date_correction_are_audited(): void
    {
        $this->enable();
        $lot = $this->receive();
        $this->operation('quarantine', ['lot_id' => $lot->id, 'quarantined' => true]);
        $this->assertSame(0, $this->item->fresh()->numRemaining());
        $op = $this->operation('amend_lot', ['lot_id' => $lot->id, 'number' => 'A', 'expires_on' => '2026-11-12']);
        $this->assertSame('2027-01-01', $op->details['previous_expires_on']);
        $this->operation('quarantine', ['lot_id' => $lot->id, 'quarantined' => false]);
        $this->assertSame(0, $this->item->fresh()->numRemaining());
    }

    public function test_undated_products_work_when_expiry_is_not_required(): void
    {
        $this->enable(false);
        $this->receive(5, 'NO-DATE', null);
        $this->assertSame(5, $this->item->fresh()->numRemaining());
    }

    public function test_existing_qty_edits_and_deletion_cannot_damage_activated_inventory(): void
    {
        $this->enable();
        $this->receive();
        try {
            $this->item->refresh()->update(['qty' => 50]);
            $this->fail('Use the ledger for quantity changes.');
        } catch (ValidationException $e) {
            $this->assertEquals(10, $this->item->fresh()->qty);
        }
        $this->expectException(ValidationException::class);
        $this->item->fresh()->delete();
    }

    public function test_detects_out_of_band_legacy_changes_without_overwriting_them(): void
    {
        $this->enable();
        $this->receive();
        DB::table('consumables')->where('id', $this->item->id)->update(['qty' => 999]);
        $this->assertFalse($this->stock->snapshot($this->item->fresh())['consistent']);
        try {
            $this->operation('consume', ['from_place_id' => $this->reserve->id, 'quantity' => 1]);
            $this->fail('Reconciliation required.');
        } catch (ValidationException $e) {
            $this->assertEquals(999, $this->item->fresh()->qty);
            $this->assertEquals(10, Balance::sum('quantity'));
        }
    }

    public function test_moving_a_bag_moves_its_path_without_changing_balances(): void
    {
        $this->enable();
        $lot = $this->receive();
        $bag = $this->stock->savePlace(['name' => 'Bag A', 'kind' => 'bag', 'company_id' => null, 'parent_id' => $this->reserve->id]);
        $this->operation('transfer', ['lot_id' => $lot->id, 'from_place_id' => $this->reserve->id,
            'to_place_id' => $bag->id, 'quantity' => 3]);
        $before = Balance::orderBy('id')->get()->toJson();
        $this->stock->savePlace(['name' => 'Bag A', 'kind' => 'bag', 'company_id' => null, 'parent_id' => $this->vehicle->id], $bag);
        $this->assertSame($before, Balance::orderBy('id')->get()->toJson());
        $this->assertSame('VSAV 1 / Bag A', $bag->fresh()->displayPath());
        $this->assertDatabaseCount('consumable_stock_place_events', 2);
        $this->expectException(ValidationException::class);
        $this->stock->savePlace(['name' => 'VSAV 1', 'kind' => 'vehicle', 'company_id' => null, 'parent_id' => $bag->id], $this->vehicle);
    }

    public function test_a_used_migration_cannot_be_rolled_back_destructively(): void
    {
        $this->enable();
        $migration = require database_path('migrations/2026_11_13_100000_create_consumable_stock_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_disabling_module_preserves_ledger_and_keeps_legacy_guard(): void
    {
        $this->enable();
        $this->receive();
        ConsumableStockSetting::find(1)->update(['enabled' => false]);
        $this->assertTrue($this->item->usesStockLots());
        $this->assertEquals(10, Balance::sum('quantity'));
        $this->expectException(ValidationException::class);
        $this->item->refresh()->update(['qty' => 20]);
    }
}
