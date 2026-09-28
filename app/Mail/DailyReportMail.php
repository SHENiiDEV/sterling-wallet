<?php

namespace App\Mail;

use App\Models\DailyReportTask;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyReportMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public DailyReportTask $task) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Daily report %s — MID %s', $this->task->report_date->toDateString(), $this->task->merchantMid->mid),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.daily-report', with: ['task' => $this->task]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $disk = config('sterling.reports.disk');

        return array_values(array_map(
            fn (string $path) => Attachment::fromStorageDisk($disk, $path),
            array_filter([$this->task->generated_pdf_path, $this->task->generated_xlsx_path, $this->task->generated_operations_path]),
        ));
    }
}
