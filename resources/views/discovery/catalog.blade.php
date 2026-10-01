@extends('layouts.public')

@section('content')
    <header class="discovery-page-heading catalog-heading">
        <p class="eyebrow">{{ str($type)->upper() }} DIRECTORY</p>
        <h1>{{ $entity->name }}</h1>
        @if(!empty($entity->description))<p>{{ $entity->description }}</p>@endif
        @if($type === 'university' && $entity->country)<p class="detail-byline"><a href="{{ route('public.countries.show', $entity->country->slug) }}">{{ $entity->country->name }}</a>@if($entity->country->region) · {{ $entity->country->region->name }}@endif</p>@endif
    </header>
    <section class="discovery-section">
        <div class="section-heading"><h2>{{ number_format($results->total()) }} {{ str('opportunity')->plural($results->total()) }}</h2><a class="text-link" href="{{ route('public.scholarships.index', [$type => $entity->slug]) }}">Open these results in search</a></div>
        @include('discovery.partials.cards', ['items' => $results])
        <nav class="pagination-wrap" aria-label="Scholarship results pages">{{ $results->onEachSide(1)->links('pagination::simple-tailwind') }}</nav>
    </section>
@endsection
