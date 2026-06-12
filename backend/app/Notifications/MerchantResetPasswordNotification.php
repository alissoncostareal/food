<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class MerchantResetPasswordNotification extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->resetUrl($notifiable);

        return (new MailMessage)
            ->subject('Redefinir senha — PartiuMenu')
            ->greeting('Olá!')
            ->line('Recebemos um pedido para redefinir a senha da sua conta no painel PartiuMenu.')
            ->line('Se você não solicitou isso, ignore este e-mail.')
            ->action('Redefinir senha', $url)
            ->line('Este link expira em ' . config('auth.passwords.users.expire') . ' minutos.')
            ->salutation('Equipe PartiuMenu');
    }

    protected function resetUrl($notifiable): string
    {
        $baseUrl = rtrim((string) config('services.admin.url'), '/');

        return $baseUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
