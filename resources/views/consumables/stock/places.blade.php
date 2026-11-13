@extends('layouts/default')
@section('title', trans('consumable_stock.places'))
@section('content')
<div class="box box-default">
    <div class="box-header with-border"><h2 class="box-title">{{ trans('consumable_stock.places') }}</h2></div>
    <div class="box-body">
        <p>{{ trans('consumable_stock.places_help') }}</p>
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <table class="table table-striped">
            <thead><tr><th>{{ trans('general.name') }}</th><th>{{ trans('consumable_stock.kind') }}</th><th>{{ trans('consumable_stock.parent') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($places as $place)
                <tr><td>{{ $place->displayPath() }}</td><td>{{ trans('consumable_stock.kinds.'.$place->kind) }}</td><td>{{ $place->parent?->name }}</td>
                    <td>@can('superuser')<a class="btn btn-default btn-sm" data-toggle="collapse" href="#place-{{ $place->id }}">{{ trans('general.edit') }}</a>@endcan</td>
                </tr>
                @can('superuser')
                    <tr class="collapse" id="place-{{ $place->id }}"><td colspan="4">
                        @include('consumables.stock.place-form', ['editingPlace' => $place])
                    </td></tr>
                @endcan
            @endforeach
            </tbody>
        </table>
        @can('superuser')
            <h3>{{ trans('consumable_stock.new_place') }}</h3>
            @include('consumables.stock.place-form', ['editingPlace' => null])
        @endcan
        <a href="{{ route('consumables.stock.index') }}">{{ trans('consumable_stock.all_stock') }}</a>
    </div>
</div>
@stop
