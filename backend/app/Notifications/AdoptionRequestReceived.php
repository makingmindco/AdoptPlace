<?php

namespace App\Notifications;

use App\Models\AdoptionRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdoptionRequestReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AdoptionRequest $adoptionRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Novo interesse de adoção no AdoptPlace')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Uma pessoa demonstrou interesse em adotar '.$this->adoptionRequest->pet?->name.'.')
            ->line('Acesse o portal da sua organização para revisar o pedido com segurança.')
            ->action('Abrir portal', rtrim(config('app.frontend_url'), '/').'/dashboard');
    }
}
