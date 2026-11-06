<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdvanceOrderCancelled extends Notification
{
    public function __construct(public int $orderId) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject(__('Pedido cancelado'))
            ->greeting(__('Hola :name', ['name' => $notifiable->name]))
            ->line(__('No pudimos confirmar tu pedido :order y lo cancelamos.', ['order' => $this->orderId]))
            ->line(__('Puedes consultar el estado de la liberación o devolución del pago en tu pedido.'));
    }
}
