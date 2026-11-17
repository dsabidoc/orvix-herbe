@csrf
<input type="hidden" name="version" value="{{ $cut->version }}">
<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
