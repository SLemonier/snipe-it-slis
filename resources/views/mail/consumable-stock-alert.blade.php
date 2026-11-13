<!doctype html>
<html><body>
<h2>{{ trans('consumable_stock.alert_subject') }}</h2>
<p>{{ trans('consumable_stock.alert_intro') }}</p>
<table cellpadding="8" cellspacing="0" border="1">
<thead><tr><th>{{ trans('general.name') }}</th><th>{{ trans('consumable_stock.lot') }}</th><th>{{ trans('consumable_stock.expiry') }}</th><th>{{ trans('consumable_stock.place') }}</th><th>{{ trans('consumable_stock.quantity') }}</th><th>{{ trans('general.status') }}</th></tr></thead>
<tbody>@foreach($rows as $row)
<tr>
<td><a href="{{ route('consumables.stock.show', $row['consumable_id']) }}">{{ $row['product'] }}</a></td>
<td>{{ $row['lot'] }}</td><td>{{ $row['expires_on'] }}</td><td>{{ $row['place'] }}</td>
<td>{{ $row['quantity'] }} {{ $row['unit'] }}</td>
<td>{{ trans('consumable_stock.'.$row['kind']) }}@if(isset($row['minimum'])) ({{ $row['minimum'] }})@endif</td>
</tr>
@endforeach</tbody>
</table>
</body></html>
