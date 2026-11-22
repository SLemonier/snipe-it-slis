<?php

namespace Tests\Feature\ConsumableStock;

use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableStockBalance as Balance;
use App\Models\ConsumableStockLot as Lot;
use App\Models\ConsumableStockPlace as Place;
use App\Models\ConsumableStockSetting;
use App\Models\Location;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockNativeIntegrationTest extends TestCase
{
    private Consumable $item;

    private Location $location;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->superuser()->create();
        $this->actingAs($this->admin);
        $this->item = Consumable::factory()->create(['qty' => 0, 'company_id' => null]);
        $this->location = Location::factory()->create(['name' => 'Reserve native', 'company_id' => null]);
        ConsumableStockSetting::create(['id' => 1, 'enabled' => true]);
    }

    private function operation(string $action, array $data = []): array
    {
        return array_merge(['action' => $action, 'operation_id' => (string) Str::uuid(), 'note' => 'Physical inventory'], $data);
    }

    private function enable(): void
    {
        app(ConsumableStock::class)->execute($this->item, $this->operation('enable', [
            'unit' => 'unit', 'expiry_required' => true, 'to_location_id' => $this->location->id,
        ]));
    }

    private function receive(int $quantity = 3): Lot
    {
        app(ConsumableStock::class)->execute($this->item->fresh(), $this->operation('receive', [
            'number' => 'NATIVE-LOT', 'quantity' => $quantity, 'expires_on' => now()->addMonth()->toDateString(),
            'to_location_id' => $this->location->id,
        ]));

        return Lot::where('consumable_id', $this->item->id)->where('number', 'NATIVE-LOT')->firstOrFail();
    }

    public function test_existing_native_location_is_available_without_creating_a_stock_place(): void
    {
        $this->item->update(['company_id' => Company::factory()->create()->id]);
        $this->get(route('consumables.stock.show', $this->item))->assertOk()
            ->assertSee('Reserve native')->assertSee('name="to_location_id"', false);
        $this->assertDatabaseCount('consumable_stock_places', 0);
        $data = $this->operation('enable', ['unit' => 'unit', 'expiry_required' => true, 'to_location_id' => $this->location->id]);
        $this->post(route('consumables.stock.store', $this->item), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('consumable_stock_places', ['location_id' => $this->location->id]);
        $this->assertDatabaseCount('locations', 1);
        $this->post(route('consumables.stock.store', $this->item), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consumable_stock_operations', 1);
    }

    public function test_company_restrictions_apply_to_native_location_ids(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        $this->settings->update(['scope_locations_fmcs' => 1, 'null_company_is_floater' => 0]);
        [$a, $b] = Company::factory()->count(2)->create();
        $this->item->update(['company_id' => $a->id]);
        $this->location->company_id = $b->id;
        $this->location->saveOrFail();
        $user = User::factory()->viewConsumables()->editConsumables()->for($a)->create();
        $this->actingAs($user);
        $this->get(route('consumables.stock.show', $this->item))->assertOk()->assertDontSee('Reserve native');
        $this->post(route('consumables.stock.store', $this->item), $this->operation('enable', [
            'unit' => 'unit', 'expiry_required' => true, 'to_location_id' => $this->location->id,
        ]))->assertSessionHasErrors();
        $this->assertDatabaseCount('consumable_stock_products', 0);
        $this->assertDatabaseCount('consumable_stock_places', 0);
    }

    public function test_native_child_locations_move_without_changing_stock_or_history(): void
    {
        $this->enable();
        $this->receive();
        $vehicle = Location::factory()->create(['company_id' => null]);
        $otherVehicle = Location::factory()->create(['company_id' => null]);
        $bag = Location::factory()->create(['name' => 'Bag', 'company_id' => null, 'parent_id' => $vehicle->id]);
        app(ConsumableStock::class)->execute($this->item->fresh(), $this->operation('transfer', [
            'from_location_id' => $this->location->id, 'to_location_id' => $bag->id, 'quantity' => 2,
        ]));
        $before = Balance::orderBy('id')->get()->toJson();
        $movements = DB::table('consumable_stock_movements')->orderBy('id')->get()->toJson();
        $bag->parent_id = $otherVehicle->id;
        $this->assertTrue($bag->save());
        $this->assertSame($before, Balance::orderBy('id')->get()->toJson());
        $this->assertSame($movements, DB::table('consumable_stock_movements')->orderBy('id')->get()->toJson());
        $this->get(route('consumables.stock.index', ['location_id' => $otherVehicle->id]))->assertOk()
            ->assertViewHas('summaries', fn ($rows) => $rows[$this->item->id]['physical'] === 2);
        $this->get(route('consumables.stock.index', ['location_id' => $vehicle->id]))->assertOk()
            ->assertViewHas('summaries', fn ($rows) => count($rows) === 0);
    }

    public function test_location_migration_preserves_ids_balances_history_and_existing_native_places(): void
    {
        $native = $this->location->getAttributes();
        $vehicle = Place::create(['name' => 'Vehicle', 'kind' => 'vehicle']);
        $bag = Place::create(['name' => 'Bag', 'kind' => 'bag', 'parent_id' => $vehicle->id]);
        $linked = Place::create(['name' => 'Legacy label', 'kind' => 'reserve', 'location_id' => $this->location->id]);
        app(ConsumableStock::class)->execute($this->item, $this->operation('enable', [
            'unit' => 'unit', 'expiry_required' => true, 'to_place_id' => $bag->id,
        ]));
        app(ConsumableStock::class)->execute($this->item->fresh(), $this->operation('receive', [
            'number' => 'LEGACY', 'quantity' => 2, 'expires_on' => '2099-01-01', 'to_place_id' => $bag->id,
        ]));
        $balances = Balance::orderBy('id')->get()->toJson();
        $movements = DB::table('consumable_stock_movements')->orderBy('id')->get()->toJson();
        $operations = DB::table('consumable_stock_operations')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_22_100000_link_consumable_stock_to_native_locations.php');
        $migration->up();
        $migration->up();
        $this->assertSame($native, $this->location->fresh()->getAttributes());
        $this->assertEquals($this->location->id, $linked->fresh()->location_id);
        $this->assertEquals($vehicle->fresh()->location_id, $bag->fresh()->location->parent_id);
        $this->assertSame($balances, Balance::orderBy('id')->get()->toJson());
        $this->assertSame($movements, DB::table('consumable_stock_movements')->orderBy('id')->get()->toJson());
        $this->assertSame($operations, DB::table('consumable_stock_operations')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('locations', 3);
        $this->assertEquals(2, $this->item->fresh()->qty);
    }

    public function test_calendar_shows_and_updates_nonempty_lots_and_hides_depleted_lots(): void
    {
        $this->enable();
        $lot = $this->receive();
        $url = route('api.calendar.events', ['event_type' => 'consumable.expiration']);
        $this->actingAsForApi($this->admin)->getJson($url)->assertOk()->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.allDay', true)
            ->assertJsonPath('events.0.start', $lot->expires_on->toDateString())
            ->assertJsonPath('events.0.url', route('consumables.stock.show', $this->item).'#stock-lot-'.$lot->id);
        $newDate = now()->addMonths(2)->toDateString();
        app(ConsumableStock::class)->execute($this->item->fresh(), $this->operation('amend_lot', [
            'lot_id' => $lot->id, 'number' => 'NATIVE-LOT', 'expires_on' => $newDate,
        ]));
        $this->getJson($url)->assertJsonPath('events.0.start', $newDate);
        $this->get(route('consumables.show', $this->item))->assertOk()->assertSee('NATIVE-LOT')->assertSee(trans('consumable_stock.next_expiry'));
        app(ConsumableStock::class)->execute($this->item->fresh(), $this->operation('consume', [
            'from_location_id' => $this->location->id, 'quantity' => 3,
        ]));
        $this->getJson($url)->assertJsonCount(0, 'events')->assertJsonPath('total', 0);
        $this->assertDatabaseHas('consumable_stock_lots', ['id' => $lot->id]);
    }

    public function test_calendar_respects_consumable_permissions_and_module_switch(): void
    {
        $this->enable();
        $this->receive();
        $url = route('api.calendar.events', ['event_type' => 'consumable.expiration']);
        $this->actingAsForApi(User::factory()->viewAssets()->create())->getJson($url)->assertOk()->assertJsonCount(0, 'events');
        $this->actingAsForApi(User::factory()->viewConsumables()->create())->getJson($url)->assertOk()->assertJsonCount(1, 'events');
        ConsumableStockSetting::find(1)->update(['enabled' => false]);
        $this->actingAsForApi($this->admin)->getJson($url)->assertOk()->assertJsonCount(0, 'events');
    }

    public function test_calendar_backfill_does_not_duplicate_events(): void
    {
        $this->enable();
        $lot = $this->receive();
        CalendarEvent::where('source_type', Lot::class)->forceDelete();
        $migration = require database_path('migrations/2026_11_22_110000_index_consumable_lot_expirations.php');
        $migration->up();
        $migration->up();
        $this->assertSame(1, CalendarEvent::where('source_type', Lot::class)->where('source_id', $lot->id)->count());
    }

    public function test_a_location_linked_to_stock_cannot_be_deleted(): void
    {
        $this->enable();
        $this->assertFalse($this->location->fresh()->isDeletable());
        $this->expectException(ValidationException::class);
        $this->location->delete();
    }

    public function test_native_and_legacy_ids_cannot_be_mixed(): void
    {
        $place = Place::create(['name' => 'Legacy', 'kind' => 'reserve']);
        $this->post(route('consumables.stock.store', $this->item), $this->operation('enable', [
            'unit' => 'unit', 'expiry_required' => true, 'to_location_id' => $this->location->id, 'to_place_id' => $place->id,
        ]))->assertSessionHasErrors();
        $this->assertDatabaseCount('consumable_stock_products', 0);
    }
}
