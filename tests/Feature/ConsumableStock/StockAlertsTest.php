<?php

namespace Tests\Feature\ConsumableStock;

use App\Mail\ConsumableStockAlert;
use App\Models\Consumable;
use App\Models\ConsumableStockPlace as Place;
use App\Models\ConsumableStockSetting;
use App\Models\User;
use App\Services\ConsumableStock;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAlertsTest extends TestCase
{
    private Consumable $item;

    private Place $reserve;

    private Place $bag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 13)->startOfDay());
        Mail::fake();
        $this->settings->enableAlertEmail('stock@example.test');
        $this->actingAs(User::factory()->superuser()->create());
        ConsumableStockSetting::create(['id' => 1, 'enabled' => true, 'expiry_days' => 30, 'reminder_days' => 7]);
        $this->item = Consumable::factory()->create(['qty' => 0, 'company_id' => null]);
        $this->reserve = Place::create(['name' => 'Reserve', 'kind' => 'reserve']);
        $vehicle = Place::create(['name' => 'VSAV', 'kind' => 'vehicle']);
        $this->bag = Place::create(['name' => 'Bag', 'kind' => 'bag', 'parent_id' => $vehicle->id]);
        $this->operation('enable', ['unit' => 'piece', 'expiry_required' => true, 'to_place_id' => $this->reserve->id]);
    }

    private function operation(string $action, array $data = [])
    {
        return app(ConsumableStock::class)->execute($this->item, array_merge([
            'action' => $action, 'operation_id' => (string) Str::uuid(), 'note' => 'Test inventory',
        ], $data));
    }

    private function receipt(string $number, string $expiry, int $place): void
    {
        $this->operation('receive', ['number' => $number, 'expires_on' => $expiry,
            'to_place_id' => $place, 'quantity' => 4]);
    }

    public function test_threshold_is_inclusive_and_covers_stock_in_bags(): void
    {
        $this->receipt('BOUNDARY', '2026-12-13', $this->bag->id);
        $this->receipt('LATER', '2026-12-14', $this->reserve->id);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSent(ConsumableStockAlert::class, function ($mail) {
            return $mail->hasTo('stock@example.test') && count($mail->rows) === 1
                && $mail->rows[0]['lot'] === 'BOUNDARY'
                && $mail->rows[0]['place'] === 'VSAV / Bag';
        });
    }

    public function test_reminders_are_deduplicated_then_repeat_after_configured_interval(): void
    {
        $this->receipt('A', '2026-12-01', $this->reserve->id);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(1);
        $this->travel(6)->days();
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(1);
        $this->travel(1)->days();
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(2);
    }

    public function test_expired_status_triggers_immediately_and_withdrawal_stops_alerts(): void
    {
        $this->receipt('A', '2026-11-13', $this->reserve->id);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        $this->travel(1)->days();
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSent(ConsumableStockAlert::class, fn ($mail) => $mail->rows[0]['kind'] === 'expired');
        $lot = \App\Models\ConsumableStockLot::where('number', 'A')->first();
        $this->operation('withdraw', ['lot_id' => $lot->id, 'from_place_id' => $this->reserve->id, 'quantity' => 4]);
        $this->travel(8)->days();
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(2);
    }

    public function test_dry_run_neither_sends_nor_suppresses_future_alerts(): void
    {
        $this->receipt('A', '2026-12-01', $this->reserve->id);
        $this->artisan('snipeit:consumable-stock-alerts --dry-run')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('consumable_stock_alerts', 0);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(1);
    }

    public function test_both_enable_switches_are_honoured(): void
    {
        $this->receipt('A', '2026-12-01', $this->reserve->id);
        $this->settings->disableAlertEmail();
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        $this->settings->enableAlertEmail('stock@example.test');
        ConsumableStockSetting::find(1)->update(['enabled' => false]);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertNothingSent();
        $this->assertDatabaseCount('consumable_stock_alerts', 0);
    }

    public function test_failed_delivery_keeps_alert_pending(): void
    {
        $this->receipt('A', '2026-12-01', $this->reserve->id);
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(1);
        $this->assertDatabaseCount('consumable_stock_alerts', 0);
    }

    public function test_minimum_uses_only_usable_stock_and_resets_after_replenishment(): void
    {
        $this->operation('minimum', ['to_place_id' => $this->bag->id, 'quantity' => 2]);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSent(ConsumableStockAlert::class, fn ($mail) => $mail->rows[0]['kind'] === 'low' && $mail->rows[0]['quantity'] === 0);
        $this->receipt('GOOD', '2028-01-01', $this->bag->id);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        $this->assertDatabaseCount('consumable_stock_alerts', 0);
        $this->operation('consume', ['from_place_id' => $this->bag->id, 'quantity' => 3]);
        $this->artisan('snipeit:consumable-stock-alerts')->assertExitCode(0);
        Mail::assertSentCount(2);
    }
}
