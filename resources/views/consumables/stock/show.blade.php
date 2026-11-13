@extends('layouts/default')
@section('title', $consumable->name.' — '.trans('consumable_stock.title'))
@section('content')
<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">{{ $consumable->name }} — {{ trans('consumable_stock.title') }}</h2>
        <div class="box-tools"><a href="{{ route('consumables.stock.index') }}">{{ trans('consumable_stock.all_stock') }}</a></div>
    </div>
    <div class="box-body">
        @if (!$module->enabled)
            <div class="alert alert-info">{{ trans('consumable_stock.disabled_help') }}</div>
        @endif
        @if (!$snapshot['consistent'])
            <div class="alert alert-danger">{{ trans('consumable_stock.errors.legacy_changed') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if ($snapshot['enabled'])
            <div class="row">
                @foreach(['physical', 'usable', 'unavailable'] as $metric)
                    <div class="col-sm-4"><h3>{{ $snapshot[$metric] }} <small>{{ $snapshot['unit'] }}</small></h3><p>{{ trans('consumable_stock.'.$metric) }}</p></div>
                @endforeach
            </div>
            <p class="text-muted">{{ trans('consumable_stock.legacy_help', ['count' => $snapshot['legacy_assignments']]) }}</p>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead><tr><th>{{ trans('consumable_stock.lot') }}</th><th>{{ trans('consumable_stock.expiry') }}</th><th>{{ trans('consumable_stock.place') }}</th><th>{{ trans('consumable_stock.quantity') }}</th><th>{{ trans('general.status') }}</th></tr></thead>
                    <tbody>
                    @forelse($snapshot['balances'] as $row)
                        <tr>
                            <td>{{ $row['unidentified'] ? trans('consumable_stock.unidentified') : $row['number'] }}</td>
                            <td>{{ $row['expires_on'] ?? ($snapshot['expiry_required'] ? trans('consumable_stock.unknown') : trans('consumable_stock.no_expiry')) }}</td>
                            <td>{{ $row['place'] }}</td><td>{{ $row['quantity'] }}</td>
                            <td><span class="label label-{{ $row['usable'] ? 'success' : 'warning' }}">{{ trans('consumable_stock.'.($row['usable'] ? 'usable' : ($row['quarantined'] ? 'quarantined' : 'unavailable'))) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ trans('consumable_stock.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @foreach($snapshot['minimums'] as $minimum)
                <p class="{{ $minimum['low'] ? 'text-danger' : 'text-muted' }}">
                    {{ $places->firstWhere('id', $minimum['place_id'])?->displayPath() }}:
                    {{ $minimum['available'] }} / {{ trans('consumable_stock.minimum') }} {{ $minimum['minimum'] }}
                </p>
            @endforeach
        @else
            <div class="alert alert-info">{{ trans('consumable_stock.activation_help') }}</div>
        @endif

        @if($module->enabled && $snapshot['consistent'])
            @if(auth()->user()->can('update', $consumable) || ($snapshot['enabled'] && auth()->user()->can('checkout', $consumable)))
                <h3>{{ trans('consumable_stock.record') }}</h3>
                <form method="POST" action="{{ route('consumables.stock.store', $consumable) }}" id="stock-movement-form">
                    @csrf
                    <input type="hidden" name="operation_id" value="{{ old('operation_id', (string) \Illuminate\Support\Str::uuid()) }}">
                    <div class="form-group">
                        <label for="stock-action">{{ trans('consumable_stock.action') }}</label>
                        <select class="form-control" name="action" id="stock-action">
                            @if(!$snapshot['enabled'])
                                <option value="enable">{{ trans('consumable_stock.actions.enable') }}</option>
                            @else
                                @foreach(['transfer','consume','receive','identify','withdraw','adjust','quarantine','amend_lot','minimum'] as $action)
                                    @if(in_array($action, ['transfer','consume']) ? auth()->user()->can('checkout', $consumable) : auth()->user()->can('update', $consumable))
                                        <option value="{{ $action }}" @selected(old('action') === $action)>{{ trans('consumable_stock.actions.'.$action) }}</option>
                                    @endif
                                @endforeach
                            @endif
                        </select>
                    </div>
                    <div class="form-group stock-field" data-actions="enable">
                        <label for="stock-unit">{{ trans('consumable_stock.unit') }}</label>
                        <input id="stock-unit" name="unit" class="form-control" maxlength="50" value="{{ old('unit', trans('consumable_stock.default_unit')) }}">
                        <input type="hidden" name="expiry_required" value="0">
                        <label><input type="checkbox" name="expiry_required" value="1" @checked(old('expiry_required', true))> {{ trans('consumable_stock.expiry_required') }}</label>
                    </div>
                    @foreach(['from' => 'identify transfer consume withdraw adjust', 'to' => 'enable receive transfer adjust minimum'] as $direction => $actions)
                        <div class="form-group stock-field" data-actions="{{ $actions }}">
                            <label for="stock-{{ $direction }}">{{ trans('consumable_stock.'.$direction) }}</label>
                            <select class="form-control" name="{{ $direction }}_place_id" id="stock-{{ $direction }}">
                                <option value="">{{ trans('consumable_stock.choose') }}</option>
                                @foreach($places as $place)
                                    <option value="{{ $place->id }}" @selected((string) old($direction.'_place_id') === (string) $place->id)>{{ $place->displayPath() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    <div class="form-group stock-field" data-actions="identify transfer consume withdraw adjust quarantine amend_lot">
                        <label for="stock-lot">{{ trans('consumable_stock.lot') }}</label>
                        <select class="form-control" name="lot_id" id="stock-lot">
                            <option value="">{{ trans('consumable_stock.fefo') }}</option>
                            @foreach(collect($snapshot['balances'])->unique('lot_id') as $row)
                                <option value="{{ $row['lot_id'] }}" @selected((string) old('lot_id') === (string) $row['lot_id'])>{{ $row['unidentified'] ? trans('consumable_stock.unidentified') : $row['number'] }} — {{ $row['expires_on'] ?? trans('consumable_stock.unknown') }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group stock-field" data-actions="receive identify adjust amend_lot">
                        <label for="stock-number">{{ trans('consumable_stock.number') }}</label>
                        <input id="stock-number" class="form-control" name="number" maxlength="191" value="{{ old('number') }}">
                        <label for="stock-expiry">{{ trans('consumable_stock.expiry') }}</label>
                        <input id="stock-expiry" class="form-control" name="expires_on" type="date" value="{{ old('expires_on') }}">
                    </div>
                    <div class="form-group stock-field" data-actions="receive">
                        <label for="stock-reference">{{ trans('consumable_stock.reference') }}</label>
                        <input id="stock-reference" class="form-control" name="reference" maxlength="191" value="{{ old('reference') }}">
                        <label for="stock-received">{{ trans('consumable_stock.received') }}</label>
                        <input id="stock-received" class="form-control" name="received_on" type="date" value="{{ old('received_on', date('Y-m-d')) }}">
                    </div>
                    <div class="form-group stock-field" data-actions="receive identify transfer consume withdraw adjust minimum">
                        <label for="stock-quantity">{{ trans('consumable_stock.quantity') }}</label>
                        <input id="stock-quantity" class="form-control" name="quantity" type="number" step="1" max="99999" value="{{ old('quantity', 1) }}">
                        <p class="help-block" id="stock-adjust-help">{{ trans('consumable_stock.adjust_help') }}</p>
                    </div>
                    <div class="form-group stock-field" data-actions="quarantine">
                        <label for="stock-quarantine">{{ trans('consumable_stock.quarantined') }}</label>
                        <select id="stock-quarantine" name="quarantined" class="form-control">
                            <option value="1">{{ trans('general.yes') }}</option><option value="0">{{ trans('general.no') }}</option>
                        </select>
                    </div>
                    <div class="alert alert-info" id="stock-preview" role="status" style="display:none"></div>
                    <div class="form-group">
                        <label for="stock-note">{{ trans('consumable_stock.note') }}</label>
                        <textarea id="stock-note" class="form-control" name="note" required maxlength="2000">{{ old('note') }}</textarea>
                    </div>
                    <p>{{ trans('consumable_stock.confirm_lot') }}</p>
                    <button type="submit" class="btn btn-primary">{{ trans('consumable_stock.confirm') }}</button>
                </form>
            @endif
        @endif
        <p><a href="{{ route('consumables.stock.places') }}">{{ trans('consumable_stock.places') }}</a> · <a href="{{ route('consumables.show', $consumable) }}">{{ trans('consumable_stock.legacy_record') }}</a></p>
        <h3>{{ trans('consumable_stock.history') }}</h3>
        <table class="table table-striped">
            <thead><tr><th>{{ trans('general.date') }}</th><th>{{ trans('consumable_stock.action') }}</th><th>{{ trans('general.user') }}</th><th>{{ trans('consumable_stock.note') }}</th><th>{{ trans('consumable_stock.details') }}</th></tr></thead>
            <tbody>@foreach($history as $operation)
                <tr><td>{{ $operation->created_at }}</td><td>{{ trans('consumable_stock.actions.'.$operation->action) }}</td><td>{{ $operation->actor?->present()->fullName }}</td><td>{{ $operation->note }}</td>
                    <td>@foreach($operation->movements as $movement)
                        <div>{{ $movement->lot?->number }}: {{ $movement->quantity }} {{ $snapshot['unit'] }}
                            {{ $places->firstWhere('id', $movement->from_place_id)?->name }}
                            → {{ $places->firstWhere('id', $movement->to_place_id)?->name }}
                        </div>
                    @endforeach
                    @if($operation->action === 'amend_lot')
                        <div>{{ trans('consumable_stock.lot') }}: {{ $operation->details['previous_number'] }} → {{ $operation->details['number'] }}</div>
                        <div>{{ trans('consumable_stock.expiry') }}: {{ $operation->details['previous_expires_on'] ?? trans('consumable_stock.no_expiry') }} → {{ $operation->details['expires_on'] ?? trans('consumable_stock.no_expiry') }}</div>
                    @elseif($operation->action === 'quarantine')
                        <div>{{ trans('consumable_stock.quarantined') }}:
                            {{ trans('general.'.($operation->details['previous_quarantined'] ? 'yes' : 'no')) }}
                            → {{ trans('general.'.($operation->details['quarantined'] ? 'yes' : 'no')) }}</div>
                    @elseif($operation->action === 'minimum')
                        <div>{{ $places->firstWhere('id', $operation->details['to_place_id'])?->name }}:
                            {{ trans('consumable_stock.minimum') }} {{ $operation->details['quantity'] }} {{ $snapshot['unit'] }}</div>
                    @endif
                    @if(!empty($operation->details['reference']))
                        <div>{{ trans('consumable_stock.reference') }}: {{ $operation->details['reference'] }}</div>
                    @endif
                    @if(!empty($operation->details['received_on']))
                        <div>{{ trans('consumable_stock.received') }}: {{ $operation->details['received_on'] }}</div>
                    @endif</td>
                </tr>
            @endforeach</tbody>
        </table>
        {{ $history->links() }}
    </div>
</div>
@stop
@section('moar_scripts')
<script>
(function () {
    var form = document.getElementById('stock-movement-form');
    if (!form) return;
    var balances = @json($snapshot['balances']);
    var action = document.getElementById('stock-action');
    var quantity = document.getElementById('stock-quantity');
    var source = document.getElementById('stock-from');
    var lot = document.getElementById('stock-lot');
    var preview = document.getElementById('stock-preview');
    function refresh() {
        var negative = action.value === 'adjust' && Number(quantity.value) < 0;
        form.querySelectorAll('.stock-field').forEach(function (field) {
            var show = field.dataset.actions.split(' ').indexOf(action.value) !== -1;
            if (action.value === 'adjust') {
                if (field.contains(source) || field.contains(lot)) show = negative;
                if (field.contains(document.getElementById('stock-to')) || field.contains(document.getElementById('stock-number'))) show = !negative;
            }
            field.style.display = show ? '' : 'none';
            field.querySelectorAll('input,select,textarea').forEach(function (input) { input.disabled = !show; });
        });
        quantity.min = action.value === 'adjust' ? '-99999' : (action.value === 'minimum' ? '0' : '1');
        document.getElementById('stock-adjust-help').style.display = action.value === 'adjust' ? '' : 'none';
        preview.style.display = 'none';
        preview.textContent = '';
        if (['transfer','consume'].indexOf(action.value) !== -1 && source.value && Number(quantity.value) > 0) {
            var left = Number(quantity.value);
            var picked = [];
            balances.filter(function (row) {
                return row.usable && String(row.place_id) === source.value && (!lot.value || String(row.lot_id) === lot.value);
            }).forEach(function (row) {
                var count = Math.min(left, row.quantity);
                if (count > 0) picked.push(row.number + ': ' + count);
                left -= count;
            });
            preview.textContent = picked.join(' / ') + (left > 0 ? ' — ' + @json(trans('consumable_stock.errors.insufficient')) : '');
            preview.style.display = '';
        }
    }
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    form.addEventListener('submit', function () { form.querySelector('[type=submit]').disabled = true; });
    refresh();
})();
</script>
@stop
