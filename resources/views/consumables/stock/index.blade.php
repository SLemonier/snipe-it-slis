@extends('layouts/default')
@section('title', trans('consumable_stock.title'))
@section('content')
<div class="box box-default">
    <div class="box-header with-border">
        <h2 class="box-title">{{ trans('consumable_stock.title') }}</h2>
        <div class="box-tools"><a href="{{ route('consumables.stock.places') }}">{{ trans('consumable_stock.places') }}</a></div>
    </div>
    <div class="box-body">
        <p>{{ trans('consumable_stock.index_help') }}</p>
        <form method="GET" class="form-inline">
            <label for="stock-search">{{ trans('general.search') }}</label>
            <input class="form-control" id="stock-search" name="search" value="{{ request('search') }}">
            <button class="btn btn-default" type="submit">{{ trans('general.search') }}</button>
        </form>
        <table class="table table-striped">
            <thead><tr><th>{{ trans('general.name') }}</th><th>{{ trans('consumable_stock.physical') }}</th><th>{{ trans('consumable_stock.usable') }}</th><th>{{ trans('consumable_stock.unavailable') }}</th></tr></thead>
            <tbody>
            @forelse ($items as $item)
                @php($summary = $summaries[$item->id])
                <tr>
                    <td><a href="{{ route('consumables.stock.show', $item) }}">{{ $item->name }}</a>
                        @if (!$summary['consistent']) <span class="label label-danger">{{ trans('consumable_stock.inconsistent') }}</span> @endif
                    </td>
                    <td>{{ $summary['physical'] }} {{ $summary['unit'] }}</td>
                    <td>{{ $summary['usable'] }}</td>
                    <td>{{ $summary['unavailable'] }}</td>
                </tr>
            @empty
                <tr><td colspan="4">{{ trans('consumable_stock.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $items->withQueryString()->links() }}
        <a href="{{ route('consumables.index') }}">{{ trans('consumable_stock.catalogue') }}</a>
    </div>
</div>
@stop
