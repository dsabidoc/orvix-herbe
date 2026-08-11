<x-layouts.app>
    <div class="grid min-h-screen place-items-center px-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <img class="h-14 w-auto" src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos">
                <p class="mt-3 text-sm font-semibold text-slate-500">Recuperar acceso</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">Restablecer contraseña</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Escribe tu correo y te enviaremos un enlace seguro para generar una nueva contraseña.</p>
            </div>

            @if (session('status'))
                <div class="mt-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
            @endif

            <form class="mt-6 space-y-4" method="POST" action="{{ route('password.email') }}">
                @csrf
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="email">Correo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2.5 text-sm font-bold text-white" type="submit">Enviar enlace</button>
            </form>

            <a class="mt-5 inline-flex text-sm font-semibold text-slate-600 hover:text-[#0f766e]" href="{{ route('login') }}">Regresar al login</a>
        </div>
    </div>
</x-layouts.app>
