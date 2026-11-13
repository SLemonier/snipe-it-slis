@php($stockSettings = \App\Models\ConsumableStockSetting::current())
<div class="form-group">
    <label class="col-md-3 control-label" for="consumable_stock_enabled">{{ trans('consumable_stock.title') }}</label>
    <div class="col-md-9">
        <input type="hidden" name="consumable_stock_enabled" value="0">
        <label><input id="consumable_stock_enabled" type="checkbox" name="consumable_stock_enabled" value="1" @checked(old('consumable_stock_enabled', $stockSettings->enabled))> {{ trans('consumable_stock.enable_module') }}</label>
        <p class="help-block">{{ trans('consumable_stock.module_help') }}</p>
    </div>
</div>
<div class="form-group">
    <label class="col-md-3 control-label" for="consumable_expiry_days">{{ trans('consumable_stock.alert_days') }}</label>
    <div class="col-md-3"><input class="form-control" id="consumable_expiry_days" name="consumable_expiry_days" type="number" min="0" max="3650" required value="{{ old('consumable_expiry_days', $stockSettings->expiry_days) }}"></div>
    <div class="col-md-6">{{ trans('general.days') }} — {{ trans('consumable_stock.alert_days_help') }}</div>
</div>
<div class="form-group">
    <label class="col-md-3 control-label" for="consumable_reminder_days">{{ trans('consumable_stock.reminder_days') }}</label>
    <div class="col-md-3"><input class="form-control" id="consumable_reminder_days" name="consumable_reminder_days" type="number" min="1" max="3650" required value="{{ old('consumable_reminder_days', $stockSettings->reminder_days) }}"></div>
    <div class="col-md-6">{{ trans('general.days') }}</div>
</div>
