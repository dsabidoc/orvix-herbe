<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('assets/favicon-orvix.svg') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="min-h-screen bg-[#f4f7fb] text-[#172033] antialiased">
        @auth
            <div class="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
                <aside class="hidden border-r border-slate-200 bg-white px-5 py-6 lg:block">
                    <a class="flex items-center gap-3" href="{{ route('dashboard') }}">
                        <img class="h-12 w-auto" src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos">
                    </a>

                    <nav class="mt-8 space-y-1 text-sm font-medium text-slate-600">
                        @foreach ([
                            ['Dashboard', 'dashboard', 'chart'],
                            ['Cobranza', 'collections.index', 'cash'],
                            ['Simulador', 'simulator.index', 'calculator'],
                            ['Cartera', 'loans.index', 'wallet'],
                            ['Cortes', 'cuts.index', 'receipt'],
                            ['Solicitudes', 'applications.index', 'file'],
                            ['Clientes', 'clients.index', 'users'],
                            ['Expedientes', 'documents.index', 'folder'],
                        ] as [$label, $route, $icon])
                            <a class="{{ request()->routeIs($route) ? 'bg-[#e6f7f4] text-[#0f766e]' : 'hover:bg-slate-100' }} flex items-center justify-between rounded-md px-3 py-2.5" href="{{ route($route) }}">
                                <span class="flex items-center gap-2.5">
                                    <span class="grid size-5 place-items-center rounded bg-[#e6f7f4] text-[#0d9488]">
                                        @switch($icon)
                                            @case('chart')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-8"/></svg>
                                                @break
                                            @case('users')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                @break
                                            @case('wallet')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 7H5a3 3 0 0 0 0 6h15v6H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h15z"/><path d="M16 13h.01"/></svg>
                                                @break
                                            @case('folder')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 10h18"/></svg>
                                                @break
                                            @case('file')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8"/><path d="M8 17h5"/></svg>
                                                @break
                                            @case('calculator')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8"/><path d="M8 10h.01"/><path d="M12 10h.01"/><path d="M16 10h.01"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/></svg>
                                                @break
                                            @case('cash')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 9h.01"/><path d="M18 15h.01"/></svg>
                                                @break
                                            @case('receipt')
                                                <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 2v20l3-2 3 2 3-2 3 2 4-2V2z"/><path d="M8 7h8"/><path d="M8 11h8"/><path d="M8 15h5"/></svg>
                                                @break
                                        @endswitch
                                    </span>
                                    <span>{{ $label }}</span>
                                </span>
                                @if (request()->routeIs($route))
                                    <span class="size-2 rounded-full bg-[#0d9488]"></span>
                                @endif
                            </a>
                        @endforeach
                        @can('settings.manage')
                            <details class="rounded-md" {{ request()->routeIs('settings.*') ? 'open' : '' }}>
                                <summary class="{{ request()->routeIs('settings.*') ? 'bg-[#e6f7f4] text-[#0f766e]' : 'hover:bg-slate-100' }} cursor-pointer rounded-md px-3 py-2.5 font-semibold">
                                    <span class="inline-flex items-center gap-2.5">
                                        <span class="inline-grid size-5 place-items-center rounded bg-[#e6f7f4] text-[#0d9488]">
                                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6V22a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1H2a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6V2a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.24.36.38.7.6 1H22a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-.6 1Z"/></svg>
                                        </span>
                                        <span>Configuracion</span>
                                    </span>
                                </summary>
                                <div class="mt-1 space-y-1 pl-3">
                                    <a class="{{ request()->routeIs('settings.users') ? 'text-[#0f766e]' : 'text-slate-600' }} flex items-center gap-2 rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.users') }}"><span class="size-1.5 rounded-full bg-[#0d9488]"></span>Usuarios</a>
                                    <a class="{{ request()->routeIs('settings.roles') ? 'text-[#0f766e]' : 'text-slate-600' }} flex items-center gap-2 rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.roles') }}"><span class="size-1.5 rounded-full bg-[#0d9488]"></span>Roles</a>
                                    <a class="{{ request()->routeIs('settings.permissions') ? 'text-[#0f766e]' : 'text-slate-600' }} flex items-center gap-2 rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.permissions') }}"><span class="size-1.5 rounded-full bg-[#0d9488]"></span>Permisos</a>
                                </div>
                            </details>
                        @endcan
                    </nav>

                    <div class="mt-8 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                        <p class="font-semibold text-slate-950">{{ auth()->user()->name }}</p>
                        <p class="mt-1 text-slate-500">{{ auth()->user()->roles->pluck('name')->join(', ') }}</p>
                        <form class="mt-4" method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" type="submit">Salir</button>
                        </form>
                    </div>
                </aside>

                <div class="fixed inset-0 z-40 hidden bg-slate-950/40 lg:hidden" data-mobile-menu-overlay></div>
                <aside class="fixed inset-y-0 left-0 z-50 flex w-[min(86vw,320px)] -translate-x-full flex-col border-r border-slate-200 bg-white px-5 py-5 shadow-xl transition-transform duration-200 lg:hidden" data-mobile-menu>
                    <div class="flex items-center justify-between gap-3">
                        <a class="flex items-center gap-3" href="{{ route('dashboard') }}">
                            <img class="h-10 w-auto" src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos">
                        </a>
                        <button class="grid size-9 place-items-center rounded-md border border-slate-200 text-slate-700" type="button" data-close-mobile-menu aria-label="Cerrar menu">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>

                    <nav class="mt-6 flex-1 space-y-1 overflow-y-auto text-sm font-medium text-slate-600">
                        @foreach ([
                            ['Dashboard', 'dashboard'],
                            ['Cobranza', 'collections.index'],
                            ['Simulador', 'simulator.index'],
                            ['Cartera', 'loans.index'],
                            ['Cortes', 'cuts.index'],
                            ['Solicitudes', 'applications.index'],
                            ['Clientes', 'clients.index'],
                            ['Expedientes', 'documents.index'],
                        ] as [$label, $route])
                            <a class="{{ request()->routeIs($route) ? 'bg-[#e6f7f4] text-[#0f766e]' : 'hover:bg-slate-100' }} flex items-center justify-between rounded-md px-3 py-3" href="{{ route($route) }}">
                                <span class="flex items-center gap-2.5"><span class="size-2 rounded-full bg-[#0d9488]"></span>{{ $label }}</span>
                                @if (request()->routeIs($route))
                                    <span class="size-2 rounded-full bg-[#0d9488]"></span>
                                @endif
                            </a>
                        @endforeach
                        @can('settings.manage')
                            <details class="rounded-md" {{ request()->routeIs('settings.*') ? 'open' : '' }}>
                                <summary class="{{ request()->routeIs('settings.*') ? 'bg-[#e6f7f4] text-[#0f766e]' : 'hover:bg-slate-100' }} cursor-pointer rounded-md px-3 py-3 font-semibold">
                                    <span class="inline-flex items-center gap-2.5"><span class="size-2 rounded-full bg-[#0d9488]"></span>Configuracion</span>
                                </summary>
                                <div class="mt-1 space-y-1 pl-5">
                                    <a class="{{ request()->routeIs('settings.users') ? 'text-[#0f766e]' : 'text-slate-600' }} block rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.users') }}">Usuarios</a>
                                    <a class="{{ request()->routeIs('settings.roles') ? 'text-[#0f766e]' : 'text-slate-600' }} block rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.roles') }}">Roles</a>
                                    <a class="{{ request()->routeIs('settings.permissions') ? 'text-[#0f766e]' : 'text-slate-600' }} block rounded-md px-3 py-2 hover:bg-slate-100" href="{{ route('settings.permissions') }}">Permisos</a>
                                </div>
                            </details>
                        @endcan
                    </nav>

                    <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                        <p class="font-semibold text-slate-950">{{ auth()->user()->name }}</p>
                        <p class="mt-1 text-slate-500">{{ auth()->user()->roles->pluck('name')->join(', ') }}</p>
                        <form class="mt-4" method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700" type="submit">Salir</button>
                        </form>
                    </div>
                </aside>

                <main>
                    <header class="border-b border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-semibold text-[#0f766e]">America/Merida · MXN · efectivo con confirmacion</p>
                                <h2 class="mt-1 text-2xl font-bold text-slate-950">{{ $title ?? 'Orvix Prestamos' }}</h2>
                            </div>
                            <button class="grid size-10 shrink-0 place-items-center rounded-md border border-slate-300 bg-white text-slate-700 shadow-sm lg:hidden" type="button" data-open-mobile-menu aria-label="Abrir menu">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>
                            </button>
                        </div>
                    </header>

                    <section class="px-4 py-6 sm:px-6 lg:px-8">
                        @if (session('status'))
                            <div class="no-print mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
                        @endif
                        @if (session('warning'))
                            <div class="no-print mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">{{ session('warning') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="no-print mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                                <p class="font-bold">Revisa los campos obligatorios.</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        {{ $slot }}
                    </section>
                </main>
            </div>

            <dialog id="confirm-paid-dialog" class="w-[min(92vw,420px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
                <form method="dialog">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-[#0f766e]">Confirmar pago</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">¿Marcar esta letra como pagada?</h3>
                    </div>
                    <div class="space-y-4 px-5 py-4">
                        <p class="text-sm leading-6 text-slate-600">El pago quedará en el corte semanal como <strong>por confirmar</strong>. El saldo contractual se aplicará hasta que administración confirme la recepción.</p>
                        <div>
                            <label class="text-sm font-semibold text-slate-700" for="confirm-paid-date">Fecha de pago</label>
                            <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-900 focus:border-[#0d9488] focus:outline-none focus:ring-2 focus:ring-[#99f6e4]" id="confirm-paid-date" type="date" value="{{ now('America/Merida')->toDateString() }}">
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                        <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" value="cancel">No</button>
                        <button class="rounded-md bg-[#0d9488] px-4 py-2 text-sm font-bold text-white" value="confirm">Sí, marcar pagado</button>
                    </div>
                </form>
            </dialog>

            <dialog id="confirm-document-delete-dialog" class="w-[min(92vw,420px)] rounded-lg border border-slate-200 bg-white p-0 text-left shadow-xl backdrop:bg-slate-950/40">
                <form method="dialog">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <p class="text-sm font-semibold uppercase tracking-[0.16em] text-red-700">Eliminar archivo</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">¿Eliminar este archivo del expediente?</h3>
                    </div>
                    <div class="px-5 py-4">
                        <p class="text-sm leading-6 text-slate-600">Esta accion quitara el archivo del expediente. Si fue un error, tendran que volver a subirlo.</p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
                        <button class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700" value="cancel">Cancelar</button>
                        <button class="rounded-md bg-red-700 px-4 py-2 text-sm font-bold text-white" value="confirm">Si, eliminar</button>
                    </div>
                </form>
            </dialog>
        @else
            {{ $slot }}
        @endauth
        @livewireScripts
    </body>
</html>
