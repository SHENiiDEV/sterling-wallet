<?php

namespace App\Mail;

use App\Models\Client;
use App\Reports\Generation\ClientDailyStatement;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The consolidated daily report of a client, rendered when the mail is
 * built so it always reflects the reports as they are now.
 */
class ClientDailyReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Client $client, public string $date) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('Daily report %s — %s', $this->date, $this->client->name));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.client-daily-report', with: ['client' => $this->client, 'date' => $this->date]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $statement = app(ClientDailyStatement::class);
        $date = CarbonImmutable::parse($this->date);
        $name = 'daily-report_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $this->client->name).'_'.$this->date;

        return [
            Attachment::fromData(fn () => $statement->render($this->client, $date), "{$name}.pdf")->withMime('application/pdf'),
            Attachment::fromData(fn () => $statement->xlsx($this->client, $date), "{$name}.xlsx")
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
