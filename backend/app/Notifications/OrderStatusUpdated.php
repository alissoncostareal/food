<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusUpdated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(public Order $order)
     {
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
        $statusLabel = $this->order->status_label ?? $this->order->status;
        return (new MailMessage)
            ->subject("🍔 Pedido #{$this->order->id} Atualizado!")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("O status do seu pedido na loja **{$this->order->store->name}** mudou.")
            ->line("Novo status: **{$statusLabel}**.")
            ->action('Ver Meu Pedido', url('/meus-pedidos/' . $this->order->id))
            ->line('Se precisar de ajuda, entre em contato com a loja diretamente.')
            ->salutation('Aproveite sua refeição!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'status'   => $this->order->status,
            'store_name' => $this->order->store->name,
            'message'  => "Seu pedido agora está: " . ($this->order->status_label ?? $this->order->status)
        ];
    }
}
