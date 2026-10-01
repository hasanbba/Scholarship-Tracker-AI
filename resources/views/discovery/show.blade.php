@extends('layouts.public')

@section('content')
    <article class="scholarship-detail">
        <p class="eyebrow">VERIFIED SCHOLARSHIP CYCLE</p>
        <h1>{{ $scholarship['title'] }}</h1>
        <p class="detail-byline">
            @if(!empty($scholarship['university']['name']))<a href="{{ route('public.universities.show', $scholarship['university']['slug']) }}">{{ $scholarship['university']['name'] }}</a>@endif
            @if(!empty($scholarship['university']['country']['name']))<span>·</span><a href="{{ route('public.countries.show', $scholarship['university']['country']['slug']) }}">{{ $scholarship['university']['country']['name'] }}</a>@endif
            <span>·</span>{{ $scholarship['cycle']['label'] ?? $scholarship['cycle']['cycle_key'] ?? 'Scholarship cycle' }}
        </p>
        <div class="detail-layout">
            <div class="detail-main">
                @if($scholarship['description'])<section class="detail-panel"><h2>About this scholarship</h2><p class="detail-copy">{{ $scholarship['description'] }}</p></section>@endif
                @if($scholarship['subjects'])<section class="detail-panel"><h2>Subjects</h2><div class="tag-list">@foreach($scholarship['subjects'] as $subject)<a class="tag" href="{{ route('public.subjects.show', $subject['slug']) }}">{{ $subject['name'] }}</a>@endforeach</div></section>@endif
                @if($scholarship['eligibility'])<section class="detail-panel"><h2>Eligibility</h2><ul class="detail-list">@foreach($scholarship['eligibility'] as $rule)<li>{{ $rule['text'] ?: trim(implode(' ', array_filter([$rule['type'], $rule['operator'], is_scalar($rule['value']) ? $rule['value'] : null, $rule['unit']]))) }}@if($rule['degree'] && $rule['degree_slug']) <a href="{{ route('public.degrees.show', $rule['degree_slug']) }}">{{ $rule['degree'] }}</a>@endif @if($rule['subject'] && $rule['subject_slug']) · <a href="{{ route('public.subjects.show', $rule['subject_slug']) }}">{{ $rule['subject'] }}</a>@endif</li>@endforeach</ul></section>@endif
                @if($scholarship['official_sources'])<section class="detail-panel"><h2>Official sources</h2><ul class="detail-list">@foreach($scholarship['official_sources'] as $source)<li><a href="{{ $source['source_url'] }}" rel="nofollow noopener" target="_blank">{{ $source['source_name'] }}</a></li>@endforeach</ul></section>@endif
                @if($relatedItems->isNotEmpty())<section class="discovery-section related-section"><div class="section-heading"><h2>Related opportunities</h2></div>@include('discovery.partials.cards', ['items' => $relatedItems])</section>@endif
            </div>
            <aside class="detail-aside">
                <section class="detail-panel"><h2>Cycle details</h2><dl class="detail-facts">
                    <div><dt>Opening date</dt><dd>{{ $scholarship['cycle']['opening_date'] ?: 'Not stated' }}</dd></div>
                    <div><dt>Deadline</dt><dd>{{ $scholarship['cycle']['deadline'] ?: 'Not stated' }}</dd></div>
                    <div><dt>Funding</dt><dd>{{ str($scholarship['funding']['classification'] ?? 'unknown')->replace('_', ' ')->title() }}</dd></div>
                    <div><dt>Verified</dt><dd>{{ $scholarship['verified_at'] ? \Illuminate\Support\Carbon::parse($scholarship['verified_at'])->toDateString() : 'Verified' }}</dd></div>
                </dl>
                @if($scholarship['funding'])<a class="text-link funding-link" href="#funding">View funding details <span aria-hidden="true">↓</span></a>@endif
                @if($scholarship['application_url'])<a class="button button-primary button-wide" href="{{ $scholarship['application_url'] }}" rel="nofollow noopener" target="_blank">Official application <span aria-hidden="true">↗</span></a>@endif
                </section>
                @if($scholarship['funding'])<section class="detail-panel funding-panel" id="funding"><h2>Funding details</h2><p class="muted">Unknown amounts remain unstated.</p><ul class="detail-list">@foreach($scholarship['funding'] as $key => $benefit)@if(is_array($benefit) && ($benefit['amount'] !== null || $benefit['period'] !== null))<li><strong>{{ str($key)->replace('_', ' ')->title() }}:</strong> {{ $benefit['amount'] === null ? 'Amount not stated' : trim($benefit['amount'].' '.($benefit['currency'] ?? '')) }}{{ $benefit['period'] ? ' / '.$benefit['period'] : '' }}</li>@endif @endforeach</ul></section>@endif
            </aside>
        </div>
    </article>
@endsection
