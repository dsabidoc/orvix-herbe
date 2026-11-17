<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <caption class="pb-2 text-left font-semibold">{{ $heading }}</caption>
        <thead class="border-b border-slate-200">
            <tr><th scope="col">Letra</th><th scope="col">Vence</th><th scope="col">Capital</th><th scope="col">Interés</th><th scope="col">Total</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($schedule as $letter)
                <tr>
                    <td class="py-2">{{ $letter['number'] }}</td>
                    <td>{{ $letter['due_on'] }}</td>
                    <td>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($letter['principal_cents'])) }}</td>
                    <td>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($letter['interest_cents'])) }}</td>
                    <td>{{ \App\Support\Money::mxn(\App\Support\Money::decimal($letter['amount_cents'])) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-2">La propuesta llegó sin letras.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
