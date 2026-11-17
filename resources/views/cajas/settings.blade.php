@extends('cajas.layout')
@section('title', 'Configuración')
@section('content')
    <a class="back" href="{{ route('cajas.home') }}">← Inicio</a>
    <div class="page-head"><h1>Configuración de Cajas</h1></div>
    @can('cajas.collections.loans.view')
        <section class="panel pad section">
            <h2>Mis operadores asignados</h2>
            @forelse($operators as $operator)
                <div class="list-row">
                    <span class="avatar" aria-hidden="true">{{ mb_substr($operator->name, 0, 1) }}</span>
                    <strong class="grow">{{ $operator->name }}</strong>
                    @if($operator->status !== 'active')<span class="badge">Inactivo</span>@endif
                </div>
            @empty
                <p class="muted">Todavía no tienes operadores asignados.</p>
            @endforelse
            @unless($hasLoanAccount)
                <div class="notice subtle">Para recibir cobros necesitarás una caja asignada con Préstamos habilitado.</div>
            @endunless
        </section>
    @endcan
    @can('cajas.accounts.manage')
        <section class="panel pad section" id="new-account"><h2>Crear caja</h2><form method="post" action="{{ route('cajas.accounts.store') }}" class="section">@csrf@include('cajas.partials.account-form')</form></section>
        <div class="section-head"><h2>Catálogo de cajas</h2><span class="muted small">Solo configuración, sin saldos ni historial</span></div>
        <div class="panel">@forelse($accounts as $account)<details class="edit-account"><summary><strong>{{ $account->name }}</strong><span class="muted small">{{ $account->kind === 'bank' ? 'Banco' : 'Efectivo' }} · {{ $account->active ? 'Activa' : 'Inactiva' }} · Editar</span></summary><form method="post" action="{{ route('cajas.accounts.update', $account) }}" class="pad">@csrf @method('PUT')@include('cajas.partials.account-form', ['account' => $account])</form>
            @can('cajas.access.manage')
                <form method="post" class="destination-config" action="{{ route('cajas.accounts.destinations', $account) }}">
                    @csrf @method('PUT')<h2>Destinos de traspaso permitidos</h2><p class="small muted">Solo se comparten los nombres de estos destinos, sin sus saldos.</p>
                    @php($destinationIds = $account->destinations()->pluck('cash_accounts.id')->all())
                    @foreach($accounts->where('id', '!=', $account->id)->where('is_test', $account->is_test)->where('currency', $account->currency) as $destination)
                        <label class="check check-row"><input type="checkbox" name="destination_ids[]" value="{{ $destination->id }}" @checked(in_array($destination->id, $destinationIds))> {{ $destination->name }}</label>
                    @endforeach
                    <button class="btn" type="submit">Guardar destinos</button>
                </form>
            @endcan
            </details>@empty<div class="empty"><p>Aún no hay cajas configuradas.</p></div>@endforelse</div>
    @endcan
    @can('cajas.access.manage')
        <div class="section-head"><h2>Accesos por persona</h2></div><p class="muted">Asigna cajas y operadores a quienes ya tienen acceso a Cajas. Sin asignación, no verán recursos.</p>
        <div class="panel section">@forelse($users as $member)<div class="list-row"><div class="grow"><strong>{{ $member->name }}</strong><p class="small muted">{{ $member->status === 'active' ? 'Usuario activo' : 'Usuario inactivo' }}</p></div><a class="btn" href="{{ route('cajas.access', $member) }}">Editar accesos</a></div>@empty<div class="empty"><p>No hay usuarios habilitados para Cajas.</p></div>@endforelse</div>
        @if(auth()->user()->can('prestamos.access') && auth()->user()->can('settings.manage'))<p class="footnote">Los perfiles y permisos se asignan en <a href="{{ route('settings.users') }}">Usuarios de Préstamos ↗</a>. Para acceso exclusivo a Cajas usa el perfil cajas-operacion y retira el acceso previo a Préstamos.</p>@endif
    @endcan
@endsection
