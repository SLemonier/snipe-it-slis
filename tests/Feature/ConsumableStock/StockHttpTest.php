<?php

namespace Tests\Feature\ConsumableStock;

use App\Models\Company;
use App\Models\Consumable;
use App\Models\ConsumableStockPlace as Place;
use App\Models\ConsumableStockSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockHttpTest extends TestCase
{
    private Consumable $item;

    private Place $place;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->superuser()->create();
        $this->item = Consumable::factory()->create(['qty' => 5, 'company_id' => null]);
        $this->place = Place::create(['name' => 'Reserve', 'kind' => 'reserve']);
        ConsumableStockSetting::create(['id' => 1, 'enabled' => true]);
    }

    private function activation(): array
    {
        return ['action' => 'enable', 'operation_id' => (string) Str::uuid(), 'unit' => 'piece',
            'expiry_required' => true, 'to_place_id' => $this->place->id, 'note' => 'Initial inventory'];
    }

    public function test_web_pages_render_before_and_after_activation(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('consumables.stock.show', $this->item))->assertOk();
        $this->post(route('consumables.stock.store', $this->item), $this->activation())
            ->assertSessionHasNoErrors()->assertRedirect(route('consumables.stock.show', $this->item));
        $this->get(route('consumables.stock.show', $this->item))->assertOk()->assertSee('Initial inventory');
        $this->get(route('consumables.stock.index'))->assertOk();
        $this->get(route('consumables.stock.places'))->assertOk();
        $this->get(route('settings.alerts.index'))->assertOk();
    }

    public function test_vehicle_filter_includes_nested_bags_once(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('consumables.stock.store', $this->item), $this->activation())->assertSessionHasNoErrors();
        $bag = Place::create(['name' => 'Nested bag', 'kind' => 'bag', 'parent_id' => $this->place->id]);
        $stock = app(\App\Services\ConsumableStock::class);
        $stock->execute($this->item, ['action' => 'receive', 'operation_id' => (string) Str::uuid(),
            'note' => 'New stock in bag', 'number' => 'B', 'expires_on' => '2099-01-01', 'quantity' => 3, 'to_place_id' => $bag->id]);
        $this->get(route('consumables.stock.index', ['place_id' => $this->place->id]))
            ->assertOk()->assertViewHas('summaries', fn ($rows) => $rows[$this->item->id]['physical'] === 8);
        $this->get(route('consumables.stock.index', ['place_id' => $bag->id]))
            ->assertOk()->assertViewHas('summaries', fn ($rows) => $rows[$this->item->id]['physical'] === 3);
    }

    public function test_api_exposes_only_authorized_stock_and_rejects_old_checkout_for_active_product(): void
    {
        $this->actingAsForApi($this->admin);
        $this->postJson(route('api.consumables.stock.store', $this->item), $this->activation())
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('payload.stock.physical', 5);
        $this->getJson(route('api.consumables.stock.show', $this->item))
            ->assertOk()->assertJsonPath('payload.stock.usable', 0);
        $this->postJson('/api/v1/consumables/'.$this->item->id.'/checkout', ['assigned_to' => $this->admin->id])
            ->assertStatus(409);
        $this->assertDatabaseCount('consumables_users', 0);
        $this->assertEquals(5, $this->item->fresh()->qty);
    }

    public function test_view_only_user_cannot_activate_or_manage_places(): void
    {
        $this->actingAsForApi(User::factory()->viewConsumables()->create());
        $this->getJson(route('api.consumables.stock.show', $this->item))->assertOk();
        $this->postJson(route('api.consumables.stock.store', $this->item), $this->activation())->assertForbidden();
        $this->postJson(route('api.consumables.stock.places.store'), ['name' => 'Unauthorized', 'kind' => 'bag'])
            ->assertForbidden();
        $this->assertDatabaseCount('consumable_stock_products', 0);
    }

    public function test_company_scope_cannot_be_bypassed_with_a_product_or_place_id(): void
    {
        $this->settings->enableMultipleFullCompanySupport();
        [$a, $b] = Company::factory()->count(2)->create();
        $this->item->company_id = $a->id;
        $this->item->save();
        $this->place->company_id = $b->id;
        $this->place->save();
        $user = User::factory()->viewConsumables()->editConsumables()->for($a)->create();
        $this->actingAsForApi($user);
        $this->postJson(route('api.consumables.stock.store', $this->item), $this->activation())->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('payload', null);
        $this->assertDatabaseCount('consumable_stock_products', 0);
        $this->actingAsForApi(User::factory()->viewConsumables()->editConsumables()->for($b)->create());
        $this->getJson(route('api.consumables.stock.show', $this->item))->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('payload', null);
    }

    public function test_validation_rejects_negative_receipts_and_missing_expiry_without_partial_writes(): void
    {
        $this->actingAsForApi($this->admin);
        $this->postJson(route('api.consumables.stock.store', $this->item), $this->activation())->assertOk();
        $request = ['action' => 'receive', 'operation_id' => (string) Str::uuid(), 'note' => 'Delivery',
            'quantity' => -2, 'number' => 'A', 'to_place_id' => $this->place->id];
        $this->postJson(route('api.consumables.stock.store', $this->item), $request)
            ->assertOk()->assertJsonPath('status', 'error')->assertJsonStructure(['messages' => ['quantity']]);
        $request['quantity'] = 2;
        $this->postJson(route('api.consumables.stock.store', $this->item), $request)
            ->assertOk()->assertJsonPath('status', 'error')->assertJsonStructure(['messages' => ['stock']]);
        $this->assertEquals(5, $this->item->fresh()->qty);
        $this->assertDatabaseCount('consumable_stock_operations', 1);
    }

    public function test_notification_thresholds_are_configurable_without_overwriting_them_on_legacy_saves(): void
    {
        $this->actingAs($this->admin)->post(route('settings.alerts.save'), [
            'consumable_stock_enabled' => '1', 'consumable_expiry_days' => 90,
            'consumable_reminder_days' => 14,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('consumable_stock_settings', ['id' => 1, 'expiry_days' => 90, 'reminder_days' => 14]);
        $this->post(route('settings.alerts.save'), ['admin_cc_always' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('consumable_stock_settings', ['id' => 1, 'expiry_days' => 90, 'reminder_days' => 14]);
        $this->post(route('settings.alerts.save'), ['consumable_expiry_days' => -1])
            ->assertSessionHasErrors('consumable_expiry_days');
    }

    public function test_unactivated_products_keep_existing_quantity_and_checkout_behaviour(): void
    {
        $this->actingAsForApi($this->admin);
        $this->postJson('/api/v1/consumables/'.$this->item->id.'/checkout', ['assigned_to' => $this->admin->id])
            ->assertOk()->assertJsonPath('status', 'success');
        $this->assertSame(4, $this->item->fresh()->numRemaining());
        $this->item->update(['qty' => 9]);
        $this->assertSame(8, $this->item->fresh()->numRemaining());
        $this->assertDatabaseCount('consumable_stock_products', 0);
        $this->assertDatabaseCount('consumable_stock_operations', 0);
    }
}
