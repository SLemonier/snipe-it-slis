@php($expirySummary = $consumable->present()->stockExpirySummary())
<div class="box box-default">
    <div class="box-header with-border"><h2 class="box-title">{{ trans('consumable_stock.menu') }}</h2></div>
    <div class="box-body">
        <p><strong>{{ trans('consumable_stock.next_expiry') }}:</strong> {{ $expirySummary['next']?->format($snipeSettings->date_display_format) ?? trans('consumable_stock.none') }}</p>
        @if($expirySummary['expired']) <p class="text-danger">{{ trans('consumable_stock.expired') }}: {{ $expirySummary['expired'] }}</p> @endif
        @if($expirySummary['unknown']) <p class="text-warning">{{ trans('consumable_stock.unknown') }}: {{ $expirySummary['unknown'] }}</p> @endif
        <table class="table table-striped">
            <thead><tr><th>{{ trans('consumable_stock.lot') }}</th><th>{{ trans('consumable_stock.expiry') }}</th><th>{{ trans('consumable_stock.quantity') }}</th></tr></thead>
            <tbody>@foreach($expirySummary['lots'] as $lot)
                <tr>
                    <td><a href="{{ route('consumables.stock.show', $consumable).'#stock-lot-'.$lot->id }}">{{ $lot->unidentified ? trans('consumable_stock.unidentified') : $lot->number }}</a></td>
                    <td>{{ $lot->expires_on?->format($snipeSettings->date_display_format) ?? trans('consumable_stock.unknown') }}</td>
                    <td>{{ $lot->balances_sum_quantity }}</td>
                </tr>
            @endforeach</tbody>
        </table>
    </div>
</div>
