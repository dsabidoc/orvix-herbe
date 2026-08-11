<x-layouts.app>
    <div class="grid min-h-screen place-items-center px-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <img class="h-14 w-auto" src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos">
                <p class="mt-3 text-sm font-semibold text-slate-500">Nuevo acceso</p>
                <h1 class="mt-2 text-2xl font-bold text-slate-950">Generar contraseña</h1>
                <p class="mt-2 text-sm leading-6 text-slate-600">Captura y confirma tu nueva contraseña. Debe tener al menos 8 caracteres, letras y numeros.</p>
            </div>

            <form class="mt-6 space-y-4" method="POST" action="{{ route('password.update') }}">
                @csrf
                <input name="token" type="hidden" value="{{ $token }}">
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="email">Correo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="email" name="email" type="email" value="{{ old('email', $email) }}" required autofocus>
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="password">Nueva contraseña</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="password" name="password" type="password" required autocomplete="new-password">
                    @error('password')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="password_confirmation">Confirmar nueva contraseña</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2.5 text-sm font-bold text-white" type="submit">Guardar nueva contraseña</button>
            </form>

            <a class="mt-5 inline-flex text-sm font-semibold text-slate-600 hover:text-[#0f766e]" href="{{ route('login') }}">Regresar al login</a>
        </div>
    </div>
</x-layouts.app>
