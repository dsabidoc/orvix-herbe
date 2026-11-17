<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Inicio') · Orvix Cajas</title>
    <link rel="icon" href="{{ asset('assets/orvix-cajas.svg') }}" type="image/svg+xml">
    <script src="{{ asset('assets/cajas-theme.js') }}"></script>
    <link rel="stylesheet"  href="{{ asset('assets/cajas.css') }}?v=17">
    <script defer src="{{ asset('assets/cajas-dialog.js') }}?v=5"></script>
</head>
<body>
<a class="skip" href="#content">Saltar al contenido</a>
<div class="shell">
    <header class="header">
        <a class="brand" href="{{ route('cajas.home') }}" aria-label="Orvix Cajas, inicio"><img class="brand-mark" src="{{ asset('assets/orvix-cajas.svg') }}" width="38" height="38" alt=""><span>orvix <small>cajas</small></span></a>
        <nav class="nav" aria-label="Navegación principal">
            <a href="{{ route('cajas.home') }}" @if(request()->routeIs('cajas.home')) aria-current="page" @endif>Inicio</a>
            @if(auth()->user()->can('cajas.collections.loans.view') || auth()->user()->can('cajas.collections.naboo.view'))
                <a href="{{ route('cajas.collections') }}" @if(request()->routeIs('cajas.collections', 'cajas.cuts.*', 'cajas.corrections.*', 'cajas.credits.*', 'cajas.disbursements.*', 'cajas.capital-corrections.*', 'cajas.offset-corrections.*')) aria-current="page" @endif>Cobros</a>
            @endif
            @can('cajas.accounts.view')
                <a href="{{ route('cajas.accounts') }}" @if(request()->routeIs('cajas.accounts', 'cajas.accounts.show', 'cajas.operations.*', 'cajas.movements.*', 'cajas.transfer-corrections.*', 'cajas.reconciliations.*', 'cajas.manual-corrections.*', 'cajas.differences.*')) aria-current="page" @endif>Cajas</a>
            @endcan
        </nav>
        <details class="profile">
            <summary><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span>{{ auth()->user()->name }}</span><span aria-hidden="true">⌄</span></summary>
            <div class="profile-menu">
                <button class="cajas-theme-toggle" type="button" data-cajas-theme-toggle aria-label="Modo oscuro" aria-pressed="false">
                    <span>Tema</span><strong data-cajas-theme-value>Claro</strong>
                </button>
                @if(auth()->user()->canAny(['cajas.collections.loans.view', 'cajas.accounts.manage', 'cajas.access.manage']))
                    <a href="{{ route('cajas.settings') }}">Configuración de Cajas</a>
                @endif
                @can('prestamos.access')<a href="{{ route('dashboard') }}">Abrir Préstamos ↗</a>@endcan
                <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Cerrar sesión</button></form>
            </div>
        </details>
    </header>
    @if(\App\Domain\Cajas\CajasSandbox::enabled())
        <div class="stage"><span class="dot"></span>ENTORNO DE PRUEBA <span class="stage-note">· Importes ficticios. Sin conexión a bancos ni cobros reales.</span></div>
    @else
        <div class="stage"><span class="dot"></span>Preparación de Cajas <span class="stage-note">· Los movimientos de dinero aún no están habilitados.</span></div>
    @endif
    <main id="content">
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="errors" role="alert"><strong>Revisa estos datos</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
    <footer><span>Orvix Cajas</span><span>Solo tus cajas y operadores autorizados</span></footer>
</div>
</body>
</html>
