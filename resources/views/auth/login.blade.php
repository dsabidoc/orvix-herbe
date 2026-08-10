<x-layouts.app>
    <div class="grid min-h-screen place-items-center px-4">
        <div class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <img class="h-14 w-auto" src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos">
                <p class="mt-3 text-sm font-semibold text-slate-500">Acceso operativo</p>
            </div>

            <form class="mt-6 space-y-4" method="POST" action="{{ route('login.store') }}">
                @csrf
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="email">Correo</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="email" name="email" type="email" value="{{ old('email', 'admin@orvix.test') }}" required autofocus>
                    @error('email')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-sm font-semibold text-slate-700" for="password">Contrasena</label>
                    <input class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm" id="password" name="password" type="password" value="orvix-demo" required>
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input class="rounded border-slate-300" name="remember" type="checkbox" value="1">
                    Mantener sesion
                </label>
                <button class="w-full rounded-md bg-[#0d9488] px-4 py-2.5 text-sm font-bold text-white" type="submit">Entrar</button>
            </form>

            <div class="mt-5 rounded-md bg-slate-50 p-3 text-sm text-slate-600">
                Demo: `admin@orvix.test`, `samuel@orvix.test`, `dario@orvix.test`, `adriana@orvix.test`. Contrasena: `orvix-demo`.
            </div>
        </div>
    </div>
</x-layouts.app>
