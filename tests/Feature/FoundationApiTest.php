<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_health_endpoint_returns_safe_success_envelope(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Application is healthy.',
                'data' => ['status' => 'ok'],
            ]);
    }

    public function test_public_and_authenticated_browser_paths_serve_the_spa_shell(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('ScholarSignal')
            ->assertSee('id="app"', false);

        $this->get('/profile')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_guest_receives_401_for_protected_api_and_contract(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_registration_hashes_password_assigns_student_role_and_logs_in(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:8000')->postJson('/api/v1/auth/register', [
            'name' => 'Student One',
            'email' => 'STUDENT@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'student@example.com')
            ->assertJsonPath('data.roles.0', 'student');

        $user = User::query()->where('email', 'student@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertNotSame('correct-horse-battery', $user->password);
    }

    public function test_registration_validation_uses_standard_envelope_and_status(): void
    {
        $this->postJson('/api/v1/auth/register', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Validation failed.')
            ->assertJsonStructure(['errors' => ['name', 'email', 'password']]);
    }

    public function test_login_me_and_logout_work_with_session_authentication(): void
    {
        $user = User::factory()->create(['email' => 'student@example.com', 'password' => Hash::make('correct-horse-battery')]);
        $user->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());

        $this->withHeader('Origin', 'http://localhost:8000')->postJson('/api/v1/auth/login', [
            'email' => 'STUDENT@example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('success', true);

        $this->withHeader('Origin', 'http://localhost:8000')->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('success', true);
    }

    public function test_invalid_login_returns_non_sensitive_error(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'incorrect'])
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'The provided credentials are invalid.',
            ]);
    }

    public function test_student_is_forbidden_from_admin_endpoint(): void
    {
        $student = User::factory()->create();
        $student->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());

        $this->actingAs($student)->getJson('/api/v1/admin/foundation')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_authorized_admin_can_access_admin_foundation_endpoint(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->actingAs($admin)->getJson('/api/v1/admin/foundation')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Administrative foundation access confirmed.',
                'data' => ['authorized' => true],
            ]);
    }

    public function test_super_admin_can_access_admin_foundation_endpoint(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->firstOrFail());

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/foundation')->assertOk();
    }

    public function test_user_can_read_their_own_protected_account(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());

        $this->actingAs($user)->getJson('/api/v1/users/'.$user->id)
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_users_cannot_read_another_users_protected_account(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();
        $actor->roles()->attach(Role::query()->where('name', 'student')->firstOrFail());

        $this->actingAs($actor)->getJson('/api/v1/users/'.$subject->id)
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
