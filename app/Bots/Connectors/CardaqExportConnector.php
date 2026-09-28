<?php

namespace App\Bots\Connectors;

use App\Bots\Target;

/**
 * Cardaq sends clearing reports by e-mail. The bot logs into the webmail
 * (Hostinger), finds the message whose subject date range covers the report
 * date and downloads its attachments (XLSX and/or CSV).
 */
class CardaqExportConnector extends PlaywrightConnector
{
    public function code(): string
    {
        return 'cardaq-export';
    }

    public function label(): string
    {
        return 'Cardaq e-mail reports (Hostinger webmail)';
    }

    protected function input(Target $target): array
    {
        return [
            'login_url' => $target->account->login_url ?: 'https://mail.hostinger.com/auth/login?p=1&c=all',
            'mail_base_url' => $target->account->setting('mail_base_url', 'https://mail.hostinger.com'),
            'search_query' => $target->account->setting('search_query', 'EXORAPAY FINANCE LTD'),
        ];
    }
}
