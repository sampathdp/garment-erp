<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset / password setup link. Queued (shared hosting: processed by cron).
 * The link points to the frontend, which posts the token to /api/v1/auth/reset-password.
 * Tokens are stored hashed, expire after auth.passwords.users.expire minutes and are single-use.
 */
final class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $token,
        public readonly bool $invitation = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function urlFor(string $email, #[\SensitiveParameter] string $token): string
    {
        return rtrim((string) config('api.frontend_url'), '/').'/reset-password/?'.http_build_query(['token' => $token, 'email' => $email]);
    }

    public function toMail(User $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $url = self::urlFor($notifiable->email, $this->token);

        if ($this->invitation) {
            return (new MailMessage)
                ->subject('Set up your '.config('app.name').' account')
                ->greeting('Hello '.$notifiable->name.',')
                ->line('An account has been created for you. Choose a password to start using the system.')
                ->action('Set password', $url)
                ->line("This link expires in {$minutes} minutes and can be used once. Ask your administrator for a new link if it expires.");
        }

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->line('We received a request to reset the password for your account.')
            ->action('Reset password', $url)
            ->line("This link expires in {$minutes} minutes and can be used once.")
            ->line('If you did not request a reset, no action is needed and your password stays the same.');
    }
}
