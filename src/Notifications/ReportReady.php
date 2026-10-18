<?php

namespace Aldeebhasan\Panorama\Notifications;

use Aldeebhasan\Panorama\Queue\CompletedReport;
use Illuminate\Mail\Attachment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once a queued report has been rendered and stored.
 *
 * The mail attaches the report when it is small enough, and names where it
 * was stored when it is not. Extend it and point `panorama.queue.notifications.ready`
 * at the subclass to change the wording, the channels or the attachment.
 */
class ReportReady extends Notification
{
    /**
     * @param list<string> $channels
     * @param int $attachUpTo the largest report attached to a mail, in bytes; 0 never attaches
     */
    public function __construct(
        public readonly CompletedReport $report,
        public readonly array $channels = ['mail'],
        public readonly int $attachUpTo = 10 * 1024 * 1024,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $this->channels;
    }

    public function attaches(): bool
    {
        return $this->report->bytes <= $this->attachUpTo;
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(sprintf('Your report is ready: %s', $this->report->name))
            ->line(sprintf(
                'The report "%s" has finished: %d %s, as %s.',
                $this->report->name,
                $this->report->rows,
                $this->report->rows === 1 ? 'row' : 'rows',
                strtoupper($this->report->format),
            ));

        if (!$this->attaches()) {
            return $mail->line(sprintf(
                'It is too large to attach, and was saved as %s.',
                $this->report->path,
            ));
        }

        return $mail
            ->line('It is attached to this message.')
            ->attach(
                Attachment::fromStorageDisk($this->report->disk, $this->report->path)
                    ->as($this->report->filename)
                    ->withMime($this->report->contentType),
            );
    }

    /**
     * For the database and broadcast channels.
     *
     * @return array<string,mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->report->toArray();
    }
}
