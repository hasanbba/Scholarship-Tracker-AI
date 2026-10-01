@extends('layouts.public')

@section('content')
    <header class="discovery-page-heading">
        <p class="eyebrow">SOURCE-FIRST OPPORTUNITIES</p>
        <h1>Scholarships</h1>
        <p>Search public scholarship cycles with a current verified decision and active official sources.</p>
    </header>
    <form class="filter-panel" action="{{ route('public.scholarships.index') }}" method="get" role="search">
        <div class="filter-grid">
            <label>Keyword<input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="200" placeholder="Title, description, university"></label>
            <label>Country slug<input name="country" value="{{ $filters['country'] ?? '' }}" placeholder="e.g. canada"></label>
            <label>Region slug<input name="region" value="{{ $filters['region'] ?? '' }}" placeholder="e.g. europe"></label>
            <label>University slug<input name="university" value="{{ $filters['university'] ?? '' }}" placeholder="e.g. example-university"></label>
            <label>Subject slug<input name="subject" value="{{ $filters['subject'] ?? '' }}" placeholder="e.g. computer-science"></label>
            <label>Degree slug<input name="degree" value="{{ $filters['degree'] ?? '' }}" placeholder="e.g. masters"></label>
            <label>Funding<select name="funding_classification"><option value="">Any classification</option>@foreach(['fully_funded', 'partially_funded', 'tuition_only', 'stipend_only', 'mixed', 'unknown'] as $funding)<option value="{{ $funding }}" @selected(($filters['funding_classification'] ?? '') === $funding)>{{ str($funding)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
            <label>Cycle key<input name="cycle" value="{{ $filters['cycle'] ?? '' }}" maxlength="80" placeholder="e.g. 2027"></label>
            <label>Deadline from<input type="date" name="deadline_from" value="{{ $filters['deadline_from'] ?? '' }}"></label>
            <label>Deadline to<input type="date" name="deadline_to" value="{{ $filters['deadline_to'] ?? '' }}"></label>
            <label>Sort<select name="sort">@foreach(['newest' => 'Recently published', 'deadline_asc' => 'Deadline: soonest', 'deadline_desc' => 'Deadline: latest', 'title_asc' => 'Title: A–Z', 'title_desc' => 'Title: Z–A'] as $value => $label)<option value="{{ $value }}" @selected(($filters['sort'] ?? 'newest') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Per page<select name="per_page">@foreach([12, 24, 50] as $amount)<option value="{{ $amount }}" @selected((int) ($filters['per_page'] ?? 12) === $amount)>{{ $amount }}</option>@endforeach</select></label>
        </div>
        <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="{{ route('public.scholarships.index') }}">Clear</a></div>
    </form>
    <section class="discovery-section">
        <div class="section-heading"><h2>{{ number_format($results->total()) }} {{ str('opportunity')->plural($results->total()) }}</h2><span class="muted">Results are shown by scholarship cycle.</span></div>
        @include('discovery.partials.cards', ['items' => $results])
        <nav class="pagination-wrap" aria-label="Scholarship results pages">{{ $results->onEachSide(1)->links('pagination::simple-tailwind') }}</nav>
    </section>
@endsection
