<?php

namespace App\Notifications;

use App\Support\AdminUrl;
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
        return AdminUrl::resetPassword(
            $this->token,
            $notifiable->getEmailForPasswordReset()
        );
    }
}
