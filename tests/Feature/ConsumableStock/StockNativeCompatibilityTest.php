<?php

namespace Tests\Feature\ConsumableStock;

use App\Helpers\Helper;
use App\Models\Actionlog;
use App\Models\CheckoutRequest;
use App\Models\Consumable;
use App\Models\ConsumableAssignment;
use App\Models\ConsumableStockPlace;
use App\Models\ConsumableStockSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockNativeCompatibilityTest extends TestCase
{
    private Consumable $item;
    private ConsumableAssignment $assignment;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->superuser()->create();
        $this->actingAs($this->admin);
        $this->item = Consumable::factory()->create(['qty' => 12, 'company_id' => null, 'min_amt' => 3]);
        $this->item->users()->attach($this->admin->id, ['created_by' => $this->admin->id]);
        $this->assignment = $this->item->consumableAssignments()->firstOrFail();
        $reserve = ConsumableStockPlace::create(['name' => 'Reserve', 'kind' => 'reserve']);
        ConsumableStockSetting::create(['id' => 1, 'enabled' => true]);
        app(ConsumableStock::class)->execute($this->item, [
            'action' => 'enable', 'operation_id' => (string) Str::uuid(), 'note' => 'Inventory',
            'unit' => 'piece', 'expiry_required' => true, 'to_place_id' => $reserve->id,
        ]);
    }

    public function test_native_quantity_adjustment_and_api_update_cannot_write_orders_or_change_tracked_stock(): void
    {
        $orders = Order::count();
        $lines = OrderItem::count();
        $this->actingAsForApi($this->admin)
            ->postJson(route('api.consumables.adjust-quantity', $this->item), [
                'amount' => 5, 'note' => 'Bypass', 'order_number' => 'MUST-NOT-EXIST',
            ])->assertStatus(422)->assertJsonPath('status', 'error');
        $this->patchJson('/api/v1/consumables/'.$this->item->id, ['qty' => 99])
            ->assertStatus(409)->assertJsonPath('status', 'error');
        $this->assertSame(12, $this->item->fresh()->qty);
        $this->assertSame($orders, Order::count());
        $this->assertSame($lines, OrderItem::count());
        $this->assertTrue(app(ConsumableStock::class)->snapshot($this->item->fresh())['consistent']);
    }

    public function test_native_single_and_bulk_consumption_preserve_historical_distributions(): void
    {
        $this->actingAsForApi($this->admin);
        $this->postJson(route('api.consumables.consume', [$this->item, $this->assignment]))
            ->assertStatus(409);
        $this->postJson(route('api.consumables.bulkconsume'), ['ids' => [$this->assignment->id]])
            ->assertStatus(409);
        $this->actingAs($this->admin);
        $this->post(route('consumables.consume', [$this->item, $this->assignment]))
            ->assertRedirect(route('consumables.stock.show', $this->item));
        $this->post(route('consumables.bulkconsume.store'), ['ids' => [$this->assignment->id]])
            ->assertRedirect(route('consumables.stock.show', $this->item));
        $this->assertNull($this->assignment->fresh()->consumed_at);
        $this->assertDatabaseCount('consumable_stock_operations', 1);
    }

    public function test_bulk_fulfillment_cannot_distribute_tracked_stock(): void
    {
        $request = CheckoutRequest::factory()->create([
            'user_id' => $this->admin->id, 'requestable_id' => $this->item->id,
            'requestable_type' => Consumable::class, 'quantity' => 2,
        ]);
        $this->get(route('consumables.fulfill-requests.create', $this->item))
            ->assertRedirect(route('consumables.stock.show', $this->item));
        $this->post(route('consumables.fulfill-requests.store', $this->item), [
            'enabled_requests' => [$request->id => '1'], 'qty' => [$request->id => 2],
            'user_id' => [$request->id => $this->admin->id],
        ])->assertRedirect(route('consumables.stock.show', $this->item));
        $this->assertSame(0, (int) $request->fresh()->fulfilled_quantity);
        $this->assertDatabaseCount('consumables_users', 1);
    }

    public function test_force_delete_preserves_native_orders_pivots_and_lot_history(): void
    {
        $lines = OrderItem::where('item_type', Consumable::class)->where('item_id', $this->item->id)->count();
        try {
            $this->item->forceDelete();
            $this->fail('Tracked history must survive force deletion.');
        } catch (ValidationException $e) {
            $this->assertDatabaseHas('consumables', ['id' => $this->item->id, 'deleted_at' => null]);
            $this->assertDatabaseCount('consumables_users', 1);
            $this->assertDatabaseCount('consumable_stock_operations', 1);
            $this->assertSame($lines, OrderItem::where('item_type', Consumable::class)->where('item_id', $this->item->id)->count());
        }
    }

    public function test_purge_skips_a_tracked_parent_even_if_an_external_tool_soft_deleted_it(): void
    {
        $logs = Actionlog::where('item_type', Consumable::class)->where('item_id', $this->item->id)->count();
        DB::table('consumables')->where('id', $this->item->id)->update(['deleted_at' => now()]);
        $this->artisan('snipeit:purge', ['--force' => 'true', '--no-interaction' => true])->assertExitCode(0);
        $this->assertDatabaseHas('consumables', ['id' => $this->item->id]);
        $this->assertDatabaseCount('consumables_users', 1);
        $this->assertDatabaseCount('consumable_stock_operations', 1);
        $this->assertSame($logs, Actionlog::where('item_type', Consumable::class)->where('item_id', $this->item->id)->count());
    }

    public function test_native_low_inventory_uses_usable_lots_instead_of_historical_distributions(): void
    {
        // Eleven unidentified units are physical stock but cannot be used.
        $this->settings->update(['alert_threshold' => 0]);
        $alerts = collect(Helper::checkLowInventory())->where('type', 'consumables');
        $row = $alerts->firstWhere('id', $this->item->id);
        $this->assertNotNull($row);
        $this->assertSame(0, $row['remaining']);
        $this->assertSame(0, $this->item->fresh()->numInPlay());
    }

    public function test_native_reconciliation_cannot_overwrite_the_lot_projection(): void
    {
        DB::table('action_logs')->insert([
            'item_id' => $this->item->id, 'item_type' => Consumable::class,
            'action_type' => 'qty_adjust', 'quantity' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_08_03_144000_reconcile_inventory_qty_from_action_logs.php');
        $migration->up(); // Data-only migration; no schema changes in a transaction.
        $this->assertSame(12, $this->item->fresh()->qty);
        $this->assertTrue(app(ConsumableStock::class)->snapshot($this->item->fresh())['consistent']);
    }

    public function test_web_stock_routes_require_view_permission(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['consumables.stock.index', 'consumables.stock.places'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('consumables.stock.show', $this->item))->assertForbidden();
    }

    public function test_stock_history_uses_offset_and_limit_without_leaking_fingerprints(): void
    {
        $this->actingAsForApi($this->admin)
            ->getJson(route('api.consumables.stock.show', [$this->item, 'offset' => 0, 'limit' => 1]))
            ->assertOk()->assertJsonCount(1, 'payload.history')
            ->assertJsonPath('payload.pagination.total', 1)
            ->assertJsonMissingPath('payload.history.0.fingerprint');
        $this->getJson(route('api.consumables.stock.show', [$this->item, 'offset' => 1, 'limit' => 1]))
            ->assertOk()->assertJsonCount(0, 'payload.history');
    }
}
