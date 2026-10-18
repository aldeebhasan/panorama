<?php

namespace Aldeebhasan\Panorama\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a queued report could not be produced, after its last attempt.
 *
 * The mail says that it failed, not why: the reason can name tables, hosts or
 * rows its recipient should not see. The reason is kept in the run history and
 * in the failed job, and `$error` carries it for a subclass that wants it.
 */
class ReportFailed extends Notification
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $format,
        public readonly string $error,
        public readonly array $channels = ['mail'],
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(sprintf('Your report could not be generated: %s', $this->name))
            ->line(sprintf('The report "%s" could not be generated.', $this->name))
            ->line('Please try again later, or contact your administrator if it keeps failing.');
    }

    /**
     * For the database and broadcast channels.
     *
     * @return array<string,mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'report' => $this->key,
            'name' => $this->name,
            'format' => $this->format,
            'failed' => true,
        ];
    }
}
