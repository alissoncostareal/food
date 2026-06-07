<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOrderReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Order $order)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database']; // Sempre salva no app

        if ($notifiable->wants_email_notifications) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('🔔 Novo Pedido Recebido!')
            ->greeting('Olá, Lojista!')
            ->line('Você acabou de receber um novo pedido no marketplace.')
            ->line('ID do Pedido: #' . $this->order->id)
            ->line('Valor Total: R$ ' . number_format($this->order->total_amount, 2, ',', '.'))
            ->action('Ver Detalhes do Pedido', url('/dashboard/orders/' . $this->order->id))
            ->line('Lembre-se: mantenha sua assinatura em dia para processar seus pedidos!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
