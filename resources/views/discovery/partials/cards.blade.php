@if(count($items))
    <div class="scholarship-grid">
        @foreach($items as $item)
            @php($url = route('public.scholarships.show', ['slug' => $item['slug'], 'cycle' => $item['cycle']['cycle_key'] ?? null]))
            <article class="scholarship-card">
                <div class="card-topline"><span class="status-chip"><span class="status-dot"></span> Verified</span><span class="funding-label">{{ str($item['funding']['classification'] ?? 'unknown')->replace('_', ' ')->title() }}</span></div>
                <h3><a href="{{ $url }}">{{ $item['title'] }}</a></h3>
                <p class="card-university">{{ $item['university']['name'] ?? 'University' }}@if(!empty($item['university']['country']['name'])) · {{ $item['university']['country']['name'] }}@endif</p>
                <p class="card-cycle">{{ $item['cycle']['label'] ?? $item['cycle']['cycle_key'] ?? 'Current cycle' }}</p>
                <p class="card-description">{{ \Illuminate\Support\Str::limit(strip_tags($item['description'] ?? ''), 150) }}</p>
                @if($item['subjects'])<div class="tag-list">@foreach(collect($item['subjects'])->take(3) as $subject)<a class="tag" href="{{ route('public.subjects.show', $subject['slug']) }}">{{ $subject['name'] }}</a>@endforeach</div>@endif
                <div class="card-footer"><span>Deadline <strong>{{ $item['cycle']['deadline'] ?: 'Not stated' }}</strong></span><a class="text-link" href="{{ $url }}">View details <span aria-hidden="true">→</span></a></div>
            </article>
        @endforeach
    </div>
@else
    <div class="empty-discovery"><h3>No published opportunities match these filters.</h3><p>Try a broader keyword or clear one or more filters.</p></div>
@endif
