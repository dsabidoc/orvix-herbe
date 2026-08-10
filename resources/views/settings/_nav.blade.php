<nav class="mb-5 flex flex-wrap gap-2">
    <a class="{{ request()->routeIs('settings.users') ? 'bg-[#0d9488] text-white' : 'border border-slate-300 bg-white text-slate-700' }} rounded-md px-4 py-2 text-sm font-bold" href="{{ route('settings.users') }}">Usuarios</a>
    <a class="{{ request()->routeIs('settings.roles') ? 'bg-[#0d9488] text-white' : 'border border-slate-300 bg-white text-slate-700' }} rounded-md px-4 py-2 text-sm font-bold" href="{{ route('settings.roles') }}">Roles</a>
    <a class="{{ request()->routeIs('settings.permissions') ? 'bg-[#0d9488] text-white' : 'border border-slate-300 bg-white text-slate-700' }} rounded-md px-4 py-2 text-sm font-bold" href="{{ route('settings.permissions') }}">Permisos</a>
</nav>
