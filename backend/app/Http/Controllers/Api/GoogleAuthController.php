<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly AuthController $authController
    ) {}

    public function redirect()
    {
        if (! $this->isConfigured()) {
            return response()->json([
                'message' => 'Login com Google não configurado no servidor.',
            ], 503);
        }

        return Socialite::driver('google')
            ->stateless()
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback()
    {
        if (! $this->isConfigured()) {
            return redirect($this->adminCallbackUrl(['error' => 'google_not_configured']));
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('Google OAuth callback failed', [
                'error' => $e->getMessage(),
            ]);

            return redirect($this->adminCallbackUrl(['error' => 'google_failed']));
        }

        $email = strtolower(trim((string) $googleUser->getEmail()));
        $googleId = trim((string) $googleUser->getId());

        if ($email === '' || $googleId === '') {
            return redirect($this->adminCallbackUrl(['error' => 'google_profile_incomplete']));
        }

        $user = User::query()
            ->where('google_id', $googleId)
            ->first()
            ?? User::query()->where('email', $email)->first();

        if (! $user) {
            return redirect($this->adminCallbackUrl(['error' => 'google_account_not_found']));
        }

        if ($user->role === User::ROLE_CUSTOMER) {
            return redirect($this->adminCallbackUrl(['error' => 'google_not_allowed']));
        }

        if (blank($user->google_id)) {
            $user->forceFill(['google_id' => $googleId])->save();
        }

        if (strcasecmp((string) $user->email, $email) !== 0) {
            return redirect($this->adminCallbackUrl(['error' => 'google_email_mismatch']));
        }

        $code = Str::random(64);
        Cache::put($this->cacheKey($code), $user->id, now()->addMinutes(2));

        return redirect($this->adminCallbackUrl(['code' => $code]));
    }

    public function exchange(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:64'],
        ]);

        $userId = Cache::pull($this->cacheKey($validated['code']));

        if (! $userId) {
            return response()->json([
                'message' => 'Código de login expirado ou inválido. Tente novamente.',
            ], 422);
        }

        $user = User::query()->find($userId);

        if (! $user || $user->role === User::ROLE_CUSTOMER) {
            return response()->json([
                'message' => 'Conta não autorizada para o painel.',
            ], 403);
        }

        return response()->json($this->authController->tokenResponseFor($user));
    }

    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    private function adminCallbackUrl(array $params = []): string
    {
        $base = rtrim((string) config('services.admin.url'), '/');

        $query = http_build_query($params);

        return "{$base}/login/google/callback".($query !== '' ? "?{$query}" : '');
    }

    private function cacheKey(string $code): string
    {
        return "google_auth_code:{$code}";
    }
}
