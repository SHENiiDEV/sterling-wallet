<?php

namespace App\Bots\Connectors;

use App\Bots\Target;

/**
 * Cardaq sends clearing reports by e-mail. The bot logs into the webmail of
 * the account (Hostinger or PrivateEmail, setting `mail_provider`), finds the
 * message whose subject date range covers the report date and downloads its
 * attachments (XLSX and/or CSV).
 */
class CardaqExportConnector extends PlaywrightConnector
{
    public const MAIL_PROVIDERS = [
        'hostinger' => [
            'label' => 'Hostinger webmail',
            'login_url' => 'https://mail.hostinger.com/auth/login?p=1&c=all',
            'base_url' => 'https://mail.hostinger.com',
        ],
        'privateemail' => [
            'label' => 'PrivateEmail (Namecheap) webmail',
            'login_url' => 'https://privateemail.com/login',
            'base_url' => 'https://privateemail.com',
        ],
    ];

    public function code(): string
    {
        return 'cardaq-export';
    }

    public function label(): string
    {
        return 'Cardaq e-mail reports';
    }

    protected function input(Target $target): array
    {
        $account = $target->account;
        $mail = (string) $account->setting('mail_provider', 'hostinger');
        $defaults = self::MAIL_PROVIDERS[$mail] ?? self::MAIL_PROVIDERS['hostinger'];

        return [
            'mail_provider' => isset(self::MAIL_PROVIDERS[$mail]) ? $mail : 'hostinger',
            'login_url' => $account->login_url ?: $defaults['login_url'],
            'mail_base_url' => $account->setting('mail_base_url', $defaults['base_url']),
            // Hostinger: searched as is. PrivateEmail: "{query}, Processing period {dd.mm.yyyy}".
            'search_query' => $account->setting('search_query', 'EXORAPAY FINANCE LTD'),
            // Optional: what exactly is searched, e.g. "{query}, Processing period {date}" ({date} = dd.mm.yyyy).
            'search_template' => $account->setting('search_template'),
        ];
    }
}
