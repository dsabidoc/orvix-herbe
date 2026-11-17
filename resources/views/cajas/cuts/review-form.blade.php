@extends('cajas.layout')
@section('title', $title)
@section('content')
<a class="back" href="{{ route('cajas.cuts.show', $cut) }}">← Volver al corte</a>
<div class="page-head">
    <div><p class="eyebrow">{{ $cut->operator->name }} · {{ $cut->cut_on->format('d/m/Y') }}</p><h1>{{ $title }}</h1></div>
</div>
<section class="panel pad narrow">
    @include('cajas.cuts.review-fields')
</section>
@endsection
