<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\OutboundMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    public function sendResetLink(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $email = strtolower($validated['email']);
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $this->canResetFromAdmin($user)) {
            return response()->json([
                'message' => 'Se o e-mail estiver cadastrado no painel, você receberá um link para redefinir a senha.',
            ]);
        }

        try {
            OutboundMail::assertConfigured();

            $status = Password::sendResetLink(['email' => $email]);

            if ($status === Password::RESET_THROTTLED) {
                return response()->json([
                    'message' => 'Aguarde um minuto antes de solicitar outro e-mail de recuperação.',
                ], 429);
            }

            if ($status !== Password::RESET_LINK_SENT) {
                throw new \RuntimeException((string) $status);
            }

            return response()->json([
                'message' => 'Se o e-mail estiver cadastrado no painel, você receberá um link para redefinir a senha.',
            ]);
        } catch (Throwable $e) {
            Password::broker()->deleteToken($user);

            Log::warning('Password reset e-mail failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            $details = config('app.debug') ? $e->getMessage() : null;

            if (! OutboundMail::isConfigured()) {
                $details = 'Configure MAIL_USERNAME e MAIL_PASSWORD (chave SMTP do Brevo) no Render.';
            } elseif ($details === null) {
                $details = 'Falha ao enviar pelo Brevo. Confirme remetente verificado e credenciais SMTP no Render.';
            }

            return response()->json([
                'message' => 'Não foi possível enviar o e-mail agora. Tente novamente em instantes.',
                'details' => $details,
            ], 503);
        }
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        try {
            $user = User::query()->where('email', $validated['email'])->first();

            if (! $user || ! $this->canResetFromAdmin($user)) {
                return response()->json([
                    'message' => 'Link inválido ou expirado. Solicite um novo e-mail de recuperação.',
                ], 422);
            }

            $status = Password::reset(
                $validated,
                function (User $user, string $password) {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();

                    event(new PasswordReset($user));
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                return response()->json([
                    'message' => 'Senha redefinida com sucesso. Faça login com a nova senha.',
                ]);
            }

            return response()->json([
                'message' => 'Link inválido ou expirado. Solicite um novo e-mail de recuperação.',
                'details' => __($status),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Erro ao redefinir senha.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function canResetFromAdmin(User $user): bool
    {
        return $user->isMerchantUser()
            || $user->isSuperAdmin()
            || $user->hasRole(User::ROLE_ADMIN);
    }
}
