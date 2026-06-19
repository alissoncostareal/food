<?php

namespace App\Notifications;

use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreMember;
use App\Support\AdminUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StoreTeamInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Store $store,
        public StoreInvitation $invitation,
        public string $inviterName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $roleLabel = $this->invitation->role === StoreMember::ROLE_MANAGER ? 'Gerente' : 'Operação';

        return (new MailMessage)
            ->subject('Convite para equipe — '.$this->store->name)
            ->greeting('Olá!')
            ->line('Você foi convidado(a) para integrar a equipe de **'.$this->store->name.'** no PartiuMenu como **'.$roleLabel.'**.')
            ->line('Convite enviado por '.$this->inviterName.'.')
            ->action('Aceitar convite e criar acesso', AdminUrl::invite($this->invitation->token))
            ->line('Este link expira em 7 dias.')
            ->line('Se você não esperava este convite, pode ignorar este e-mail.')
            ->salutation('Equipe PartiuMenu');
    }
}
