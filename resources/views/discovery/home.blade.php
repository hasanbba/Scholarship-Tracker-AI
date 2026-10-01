@extends('layouts.public')

@section('content')
    <section class="hero-section discovery-hero">
        <div class="hero-copy">
            <p class="eyebrow"><span class="status-dot"></span> OFFICIAL SOURCES, CURRENT VERIFICATION</p>
            <h1>Find your next<br><em>opportunity.</em></h1>
            <p class="hero-description">Search published scholarship cycles with clear deadlines, funding details, eligibility, and official application sources.</p>
            <form class="discovery-search" action="{{ route('public.scholarships.index') }}" method="get" role="search">
                <label class="sr-only" for="home-q">Search scholarships</label>
                <input id="home-q" type="search" name="q" placeholder="Scholarship, subject, or university" maxlength="200">
                <button class="button button-primary" type="submit">Search</button>
            </form>
            <div class="trust-row"><span><b>01</b> Verified published versions</span><span><b>02</b> Official sources first</span></div>
        </div>
        <div class="hero-art" aria-hidden="true"><div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div><div class="art-card"><span class="art-kicker">A CLEARER WAY TO BEGIN</span><div class="art-lines"><i></i><i></i><i></i></div><div class="art-seal">S</div><span class="art-caption">Your goals. A clearer path.</span></div></div>
    </section>
    <section class="discovery-section">
        <div class="section-heading"><div><p class="eyebrow">CURRENT OPPORTUNITIES</p><h2>Recently published</h2></div><a class="text-link" href="{{ route('public.scholarships.index') }}">Browse all scholarships <span aria-hidden="true">→</span></a></div>
        @include('discovery.partials.cards', ['items' => $featured])
    </section>
@endsection
