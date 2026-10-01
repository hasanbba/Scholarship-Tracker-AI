<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Degree;
use App\Models\Region;
use App\Models\Scholarship;
use App\Models\ScholarshipCycle;
use App\Models\ScholarshipSource;
use App\Models\ScholarshipVersion;
use App\Models\Subject;
use App\Models\University;
use App\Models\User;
use App\Models\VerificationRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::factory()->create();
    }

    public function test_only_current_verified_published_cycles_in_active_discovery_are_visible(): void
    {
        $current = $this->makeOpportunity(['title' => 'Current Published Award', 'slug' => 'current-published-award']);
        $this->makeOpportunity(['title' => 'Not Published Award', 'published' => false]);
        $this->makeOpportunity(['title' => 'Pending Award', 'decision' => 'pending']);
        $this->makeOpportunity(['title' => 'Rejected Award', 'decision' => 'rejected']);
        $this->makeOpportunity(['title' => 'Draft Award', 'cycle_status' => 'draft']);
        $this->makeOpportunity(['title' => 'Archived Award', 'cycle_status' => 'archived']);
        $this->makeOpportunity(['title' => 'Expired Award', 'cycle_status' => 'expired']);
        $this->makeOpportunity(['title' => 'Archived Program', 'scholarship_status' => 'archived']);
        $this->makeOpportunity(['title' => 'Inactive Official Source', 'source_status' => 'inactive']);
        $this->makeOpportunity(['title' => 'Past Deadline Open State', 'opening_date' => '2026-01-01', 'deadline' => now(config('app.timezone'))->subDay()->toDateString()]);

        $response = $this->getJson('/api/v1/scholarships')->assertOk();
        $titles = collect($response->json('data.items'))->pluck('title');

        $this->assertTrue($titles->contains('Current Published Award'));
        foreach (['Not Published Award', 'Pending Award', 'Rejected Award', 'Draft Award', 'Archived Award', 'Expired Award', 'Archived Program', 'Inactive Official Source', 'Past Deadline Open State'] as $hidden) {
            $this->assertFalse($titles->contains($hidden), $hidden.' must remain private.');
        }

        $replacement = $this->makeVersion($current['cycle'], 2, 'pending');
        $this->getJson('/api/v1/scholarships/current-published-award')->assertOk()->assertJsonPath('data.scholarship.version_number', 1);
        VerificationRecord::query()->create(['version_id' => $replacement->id, 'status' => 'verified', 'actor_id' => $this->admin->id, 'source_id' => $current['source']->id, 'notes' => 'internal verification note', 'decided_at' => now()->addSeconds(5)]);
        $current['cycle']->forceFill(['published_version_id' => $replacement->id])->save();

        $this->getJson('/api/v1/scholarships/current-published-award')->assertOk()->assertJsonPath('data.scholarship.version_number', 2);
        $this->assertSame(1, $this->getJson('/api/v1/scholarships')->json('data.pagination.total'));
        $this->assertSame([2], collect($this->getJson('/api/v1/scholarships')->json('data.items'))->pluck('version_number')->all());
    }

    public function test_keyword_and_individual_catalog_filters_use_published_snapshot_values(): void
    {
        $opportunity = $this->makeOpportunity([
            'title' => 'Quantum Research Fellowship',
            'description' => 'A distinctive phrase for discovery tests.',
            'university_name' => 'Northstar University',
            'country_name' => 'Exampleland',
            'region_name' => 'North Region',
            'subject_name' => 'Quantum Physics',
            'degree_name' => 'Doctoral Degree',
            'funding_classification' => 'fully_funded',
            'deadline' => '2027-04-10',
        ]);

        foreach ([
            'q' => 'Quantum Research',
            'country' => $opportunity['country']->slug,
            'region' => $opportunity['region']->slug,
            'university' => $opportunity['university']->slug,
            'subject' => $opportunity['subject']->slug,
            'degree' => $opportunity['degree']->slug,
            'funding_classification' => 'fully_funded',
            'deadline_from' => '2027-04-01',
            'deadline_to' => '2027-04-30',
        ] as $filter => $value) {
            $this->getJson('/api/v1/scholarships?'.http_build_query([$filter => $value]))
                ->assertOk()
                ->assertJsonPath('data.pagination.total', 1)
                ->assertJsonPath('data.items.0.title', 'Quantum Research Fellowship');
        }

        $this->getJson('/api/v1/scholarships?q=distinctive%20phrase')->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/scholarships?q=Northstar%20University')->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_combined_filters_remain_composable_and_null_deadlines_do_not_match_ranges(): void
    {
        $matching = $this->makeOpportunity([
            'title' => 'Combined Match',
            'country_name' => 'Sample Republic',
            'subject_name' => 'Applied Mathematics',
            'degree_name' => 'Masters Degree',
            'funding_classification' => 'fully_funded',
            'deadline' => '2027-05-20',
        ]);
        $this->makeOpportunity(['title' => 'Wrong Country', 'country_name' => 'Elsewhere', 'subject_name' => 'Applied Mathematics', 'degree_name' => 'Masters Degree', 'funding_classification' => 'fully_funded', 'deadline' => '2027-05-20']);
        $this->makeOpportunity(['title' => 'Wrong Funding', 'country_name' => 'Sample Republic', 'subject_name' => 'Applied Mathematics', 'degree_name' => 'Masters Degree', 'funding_classification' => 'unknown', 'deadline' => '2027-05-20']);
        $this->makeOpportunity(['title' => 'No Deadline', 'country_name' => 'Sample Republic', 'subject_name' => 'Applied Mathematics', 'degree_name' => 'Masters Degree', 'funding_classification' => 'fully_funded', 'deadline' => null]);

        $queries = [
            ['country' => $matching['country']->slug, 'degree' => $matching['degree']->slug],
            ['country' => $matching['country']->slug, 'subject' => $matching['subject']->slug],
            ['country' => $matching['country']->slug, 'funding_classification' => 'fully_funded'],
            ['university' => $matching['university']->slug, 'deadline_from' => '2027-05-01'],
            ['q' => 'Combined Match', 'country' => $matching['country']->slug, 'funding_classification' => 'fully_funded'],
            ['subject' => $matching['subject']->slug, 'degree' => $matching['degree']->slug, 'deadline_to' => '2027-05-31'],
        ];

        foreach ($queries as $filters) {
            $this->getJson('/api/v1/scholarships?'.http_build_query($filters))->assertOk()->assertJsonPath('data.pagination.total', 1);
        }

        $this->getJson('/api/v1/scholarships?country='.$matching['country']->slug.'&deadline_to=2027-05-31')
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/scholarships?deadline_to=2027-05-31')
            ->assertOk()->assertJsonPath('data.pagination.total', 3);
    }

    public function test_sorting_is_whitelisted_deterministic_and_keeps_missing_deadlines_last(): void
    {
        $this->makeOpportunity(['title' => 'Zulu', 'deadline' => null]);
        $later = $this->makeOpportunity(['title' => 'Beta', 'deadline' => '2027-08-01']);
        $earlier = $this->makeOpportunity(['title' => 'Alpha', 'deadline' => '2027-02-01']);

        $this->getJson('/api/v1/scholarships?sort=deadline_asc')->assertOk()
            ->assertJsonPath('data.items.0.title', 'Alpha')
            ->assertJsonPath('data.items.1.title', 'Beta')
            ->assertJsonPath('data.items.2.title', 'Zulu');
        $this->getJson('/api/v1/scholarships?sort=deadline_desc')->assertOk()
            ->assertJsonPath('data.items.0.title', 'Beta')
            ->assertJsonPath('data.items.2.title', 'Zulu');
        $this->getJson('/api/v1/scholarships?sort=title_asc')->assertOk()->assertJsonPath('data.items.0.title', 'Alpha');
        $this->getJson('/api/v1/scholarships?sort=title_desc')->assertOk()->assertJsonPath('data.items.0.title', 'Zulu');
        $this->assertSame($earlier['cycle']->id, $this->getJson('/api/v1/scholarships')->json('data.items.0.cycle.id'));
        $this->assertSame($later['cycle']->id, $this->getJson('/api/v1/scholarships')->json('data.items.1.cycle.id'));
        $this->getJson('/api/v1/scholarships?sort=deadline;drop')->assertUnprocessable();
    }

    public function test_pagination_returns_totals_bounds_and_empty_pages(): void
    {
        foreach (range(1, 5) as $number) {
            $this->makeOpportunity(['title' => 'Page Award '.$number]);
        }

        $this->getJson('/api/v1/scholarships?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.pagination.current_page', 2)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonCount(2, 'data.items');
        $this->getJson('/api/v1/scholarships?per_page=2&page=9')->assertOk()
            ->assertJsonPath('data.pagination.current_page', 9)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonCount(0, 'data.items');
        $this->getJson('/api/v1/scholarships?per_page=100')->assertUnprocessable();
    }

    public function test_detail_api_and_server_rendered_pages_use_public_snapshot_without_internal_metadata(): void
    {
        $opportunity = $this->makeOpportunity([
            'title' => 'Public Detail Fellowship',
            'slug' => 'public-detail-fellowship',
            'notes' => 'Never expose this internal note.',
            'funding_classification' => 'fully_funded',
            'deadline' => '2027-03-31',
        ]);

        $api = $this->getJson('/api/v1/scholarships/public-detail-fellowship?cycle=2027')
            ->assertOk()
            ->assertJsonPath('data.scholarship.title', 'Public Detail Fellowship')
            ->assertJsonPath('data.scholarship.version_number', 1)
            ->assertJsonPath('data.scholarship.university.name', $opportunity['university']->name)
            ->assertJsonPath('data.scholarship.funding.classification', 'fully_funded')
            ->assertJsonPath('data.scholarship.official_sources.0.source_url', $opportunity['source']->source_url);
        $this->assertStringNotContainsString('Never expose this internal note.', $api->getContent());
        $this->assertStringNotContainsString('internal verification note', $api->getContent());
        $this->assertStringNotContainsString('verification_records', $api->getContent());
        $this->assertStringNotContainsString('actor_id', $api->getContent());
        $this->getJson('/api/v1/scholarships/missing-scholarship')->assertNotFound();
        $this->getJson('/api/v1/scholarships/public-detail-fellowship?unknown=value')->assertUnprocessable();

        $html = $this->get('/scholarships/public-detail-fellowship?cycle=2027')->assertOk()
            ->assertSee('Public Detail Fellowship')
            ->assertSee('name="description"', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('Official application')
            ->assertDontSee('Never expose this internal note.');
        $this->assertStringNotContainsString('<script', $html->getContent());
        $this->get('/scholarships?funding_classification=fully_funded')->assertOk()->assertSee('Public Detail Fellowship');
        $this->get('/scholarships/missing-scholarship')->assertNotFound();
        $this->get('/')->assertOk()->assertSee('Find your next')->assertSee('name="q"', false);
    }

    public function test_catalog_discovery_pages_resolve_active_entities_and_filter_public_snapshots(): void
    {
        $opportunity = $this->makeOpportunity(['title' => 'Catalog Page Award']);
        $this->get('/universities/'.$opportunity['university']->slug)->assertOk()->assertSee('Catalog Page Award')->assertSee('rel="canonical"', false);
        $this->get('/countries/'.$opportunity['country']->slug)->assertOk()->assertSee('Catalog Page Award');
        $this->get('/subjects/'.$opportunity['subject']->slug)->assertOk()->assertSee('Catalog Page Award');
        $this->get('/degrees/'.$opportunity['degree']->slug)->assertOk()->assertSee('Catalog Page Award');

        $opportunity['subject']->forceFill(['status' => 'inactive'])->save();
        $this->get('/subjects/'.$opportunity['subject']->slug)->assertNotFound();
        $this->get('/universities/no-such-university')->assertNotFound();
    }

    public function test_related_discovery_uses_shared_published_subjects(): void
    {
        $first = $this->makeOpportunity(['title' => 'Primary Subject Award']);
        $this->makeOpportunity(['title' => 'Related Subject Award', 'subject_model' => $first['subject']]);

        $this->getJson('/api/v1/scholarships/'.$first['scholarship']->slug)
            ->assertOk()
            ->assertJsonPath('data.related.0.title', 'Related Subject Award');
    }

    public function test_public_listing_uses_bounded_eager_queries_instead_of_one_query_per_result(): void
    {
        foreach (range(1, 8) as $number) {
            $this->makeOpportunity(['title' => 'Query Count Award '.$number]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/scholarships?per_page=12')->assertOk();
        $selectCount = collect(DB::getQueryLog())->filter(fn (array $entry): bool => str_starts_with(strtolower(ltrim($entry['query'])), 'select'))->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(7, $selectCount, 'Public listing should use a bounded number of queries with eager-loaded decisions.');
    }

    private function makeOpportunity(array $options = []): array
    {
        $token = Str::lower(Str::random(8));
        $region = Region::factory()->create(['name' => $options['region_name'] ?? 'Region '.$token, 'slug' => Str::slug($options['region_name'] ?? 'region-'.$token).'-'.$token]);
        $countryName = $options['country_name'] ?? 'Country '.$token;
        $universityName = $options['university_name'] ?? 'University '.$token;
        $country = Country::factory()->create(['region_id' => $region->id, 'name' => $countryName, 'normalized_name' => Str::lower($countryName.' '.$token), 'slug' => Str::slug($countryName).'-'.$token]);
        $university = University::factory()->create(['country_id' => $country->id, 'name' => $universityName, 'normalized_name' => Str::lower($universityName.' '.$token), 'slug' => Str::slug($universityName).'-'.$token]);
        $slug = $options['slug'] ?? 'scholarship-'.$token;
        $scholarship = Scholarship::factory()->create([
            'university_id' => $university->id,
            'title' => $options['title'] ?? 'Scholarship '.$token,
            'slug' => $slug,
            'description' => $options['description'] ?? 'Description '.$token,
            'official_url' => 'https://official.example/'.$slug,
            'lifecycle_status' => $options['scholarship_status'] ?? 'active',
        ]);
        $subjectName = $options['subject_name'] ?? 'Subject '.$token;
        $subject = $options['subject_model'] ?? Subject::factory()->create(['name' => $subjectName, 'normalized_name' => Str::lower($subjectName.' '.$token), 'slug' => Str::slug($subjectName).'-'.$token]);
        $scholarship->subjects()->attach($subject);
        $degreeName = $options['degree_name'] ?? 'Degree '.$token;
        $degree = Degree::factory()->create(['name' => $degreeName, 'normalized_name' => Str::lower($degreeName.' '.$token), 'slug' => Str::slug($degreeName).'-'.$token]);
        $deadline = array_key_exists('deadline', $options) ? $options['deadline'] : '2027-06-30';
        $cycle = ScholarshipCycle::factory()->create([
            'scholarship_id' => $scholarship->id,
            'cycle_key' => '2027',
            'label' => '2027 Cycle',
            'opening_date' => $options['opening_date'] ?? '2027-01-01',
            'deadline' => $deadline,
            'application_url' => 'https://official.example/'.$slug.'/apply',
            'status' => $options['cycle_status'] ?? 'open',
        ]);
        $sourceUrl = 'https://official.example/'.$slug.'/source';
        $source = ScholarshipSource::factory()->create([
            'scholarship_id' => $scholarship->id,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
            'notes' => $options['notes'] ?? 'Never expose this internal note.',
            'status' => $options['source_status'] ?? 'active',
        ]);
        $version = $this->makeVersion($cycle, 1, $options['decision'] ?? 'verified', $source, [
            'scholarship' => ['id' => $scholarship->id, 'university_id' => $university->id, 'title' => $scholarship->title, 'slug' => $scholarship->slug, 'description' => $scholarship->description, 'official_url' => $scholarship->official_url],
            'university' => ['id' => $university->id, 'country_id' => $country->id, 'name' => $university->name, 'slug' => $university->slug, 'official_url' => $university->official_url, 'country' => ['id' => $country->id, 'region_id' => $region->id, 'name' => $country->name, 'normalized_name' => $country->normalized_name, 'slug' => $country->slug, 'iso2' => null, 'iso3' => null], 'region' => ['id' => $region->id, 'name' => $region->name, 'slug' => $region->slug]],
            'cycle' => ['id' => $cycle->id, 'cycle_key' => $cycle->cycle_key, 'label' => $cycle->label, 'opening_date' => $options['opening_date'] ?? '2027-01-01', 'deadline' => $deadline, 'application_url' => $cycle->application_url, 'status' => $cycle->status],
            'subjects' => [['id' => $subject->id, 'name' => $subject->name, 'slug' => $subject->slug]],
            'funding' => ['classification' => $options['funding_classification'] ?? 'unknown', 'notes' => 'Never expose this internal note.', 'tuition_amount' => $options['tuition_amount'] ?? null, 'tuition_currency' => ! empty($options['tuition_amount']) ? 'USD' : null, 'tuition_period' => null],
            'eligibility_rules' => [['id' => 1, 'rule_type' => 'degree', 'operator' => '=', 'normalized_value' => 'graduate', 'unit' => null, 'display_text' => 'Applicants must have a graduate degree.', 'degree_id' => $degree->id, 'degree' => ['id' => $degree->id, 'name' => $degree->name, 'slug' => $degree->slug], 'subject' => null, 'country' => null, 'metadata' => ['internal' => 'do not expose']]],
            'sources' => [['id' => $source->id, 'source_type' => $source->source_type, 'source_name' => $source->source_name, 'source_url' => $source->source_url, 'is_primary' => true, 'status' => 'active']],
        ], false);

        if (! ($options['published'] ?? true)) {
            return compact('cycle', 'version', 'source', 'scholarship', 'university', 'country', 'region', 'subject', 'degree');
        }

        $cycle->forceFill(['published_version_id' => $version->id])->save();

        return compact('cycle', 'version', 'source', 'scholarship', 'university', 'country', 'region', 'subject', 'degree');
    }

    private function makeVersion(ScholarshipCycle $cycle, int $number = 1, string $decision = 'verified', ?ScholarshipSource $source = null, ?array $snapshot = null, bool $publish = false): ScholarshipVersion
    {
        $source ??= ScholarshipSource::query()->where('scholarship_id', $cycle->scholarship_id)->firstOrFail();
        $snapshot ??= [
            'scholarship' => ['slug' => $cycle->scholarship->slug, 'title' => $cycle->scholarship->title],
            'university' => ['name' => $cycle->scholarship->university->name, 'country' => ['slug' => $cycle->scholarship->university->country->slug]],
            'cycle' => ['cycle_key' => $cycle->cycle_key, 'deadline' => '2027-06-30'],
            'funding' => null,
            'eligibility_rules' => [],
            'subjects' => [],
            'sources' => [['id' => $source->id, 'source_type' => $source->source_type, 'source_name' => $source->source_name, 'source_url' => $source->source_url, 'status' => $source->status]],
        ];
        $version = ScholarshipVersion::query()->create(['scholarship_id' => $cycle->scholarship_id, 'cycle_id' => $cycle->id, 'version_number' => $number, 'snapshot' => $snapshot, 'change_type' => 'test_snapshot', 'origin_type' => 'human', 'source_id' => $source->id, 'created_at' => now()->addSeconds($number)]);
        VerificationRecord::query()->create(['version_id' => $version->id, 'status' => $decision, 'actor_id' => $this->admin->id, 'source_id' => $source->id, 'notes' => 'internal verification note', 'decided_at' => now()->addSeconds($number)]);

        if ($publish) {
            $cycle->forceFill(['published_version_id' => $version->id])->save();
        }

        return $version;
    }
}
