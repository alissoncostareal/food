<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'http://localhost/api/v1/auth/google/callback',
            'services.admin.url' => 'http://admin.test',
        ]);

        Cache::flush();
    }

    private function fakeGoogleUser(string $id = 'google-123', string $email = 'merchant@example.com'): SocialiteUserContract
    {
        $user = \Mockery::mock(SocialiteUserContract::class);
        $user->shouldReceive('getId')->andReturn($id);
        $user->shouldReceive('getEmail')->andReturn($email);
        $user->shouldReceive('getName')->andReturn('Merchant User');

        return $user;
    }

    private function mockGoogleProvider(?SocialiteUserContract $user = null, bool $redirect = false): void
    {
        $provider = \Mockery::mock('Laravel\Socialite\Two\AbstractProvider');
        $provider->shouldReceive('stateless')->andReturnSelf();

        if ($redirect) {
            $provider->shouldReceive('scopes')->with(['openid', 'profile', 'email'])->andReturnSelf();
            $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
        } else {
            $provider->shouldReceive('user')->andReturn($user ?? $this->fakeGoogleUser());
        }

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    #[Test]
    public function redirect_returns_service_unavailable_when_google_is_not_configured(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.google.redirect' => null,
        ]);

        $this->getJson('/api/v1/auth/google/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Login com Google não configurado no servidor.');
    }

    #[Test]
    public function redirect_sends_user_to_google_when_configured(): void
    {
        $this->mockGoogleProvider(redirect: true);

        $this->get('/api/v1/auth/google/redirect')
            ->assertRedirect('https://accounts.google.com/o/oauth2/auth');
    }

    #[Test]
    public function callback_links_google_id_and_redirects_with_exchange_code(): void
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_STORE_OWNER,
            'email' => 'merchant@example.com',
            'google_id' => null,
        ]);

        $this->mockGoogleProvider($this->fakeGoogleUser('google-abc', 'merchant@example.com'));

        $response = $this->get('/api/v1/auth/google/callback');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('http://admin.test/login/google/callback?code=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertSame(64, strlen($query['code']));

        $merchant->refresh();
        $this->assertSame('google-abc', $merchant->google_id);
        $this->assertTrue(Cache::has('google_auth_code:'.$query['code']));
    }

    #[Test]
    public function callback_rejects_customer_accounts(): void
    {
        User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'email' => 'customer@example.com',
        ]);

        $this->mockGoogleProvider($this->fakeGoogleUser('google-customer', 'customer@example.com'));

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect('http://admin.test/login/google/callback?error=google_not_allowed');
    }

    #[Test]
    public function callback_rejects_unknown_email(): void
    {
        $this->mockGoogleProvider($this->fakeGoogleUser('google-new', 'unknown@example.com'));

        $this->get('/api/v1/auth/google/callback')
            ->assertRedirect('http://admin.test/login/google/callback?error=google_account_not_found');
    }

    #[Test]
    public function exchange_returns_token_for_valid_code(): void
    {
        $merchant = User::factory()->create([
            'role' => User::ROLE_STORE_OWNER,
            'email' => 'merchant@example.com',
            'google_id' => 'google-abc',
        ]);

        $code = Str::random(64);
        Cache::put("google_auth_code:{$code}", $merchant->id, now()->addMinutes(2));

        $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('message', 'Login realizado com sucesso')
            ->assertJsonPath('user.email', 'merchant@example.com')
            ->assertJsonStructure(['access_token', 'user']);

        $this->assertFalse(Cache::has("google_auth_code:{$code}"));
    }

    #[Test]
    public function exchange_rejects_invalid_or_expired_code(): void
    {
        $this->postJson('/api/v1/auth/google/exchange', ['code' => Str::random(64)])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Código de login expirado ou inválido. Tente novamente.');
    }
}
