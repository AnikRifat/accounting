<?php

namespace App\Support;

/**
 * Puts the Mail settings into Laravel's mail config. AppServiceProvider runs apply() once the mail manager is resolved,
 * before it builds a mailer, so every email (documents, test emails) goes out the way Settings > Mail says. With
 * "Server configuration" the MAIL_ values of the environment stay in charge; a filled sender applies to both.
 */
final class MailConfiguration
{
    public static function apply(): void
    {
        if (Configuration::get('mail.mailer') === 'smtp') {
            config(['mail.default' => 'smtp', 'mail.mailers.smtp' => self::smtp()]);
        }
        if (($address = Configuration::get('mail.from_address')) !== '') {
            config(['mail.from.address' => $address, 'mail.from.name' => Configuration::get('mail.from_name') ?: Configuration::get('app_name')]);
        }
    }

    /**
     * The SMTP mailer. STARTTLS is required when chosen (no silent fallback to plain text), SSL/TLS connects encrypted
     * from the start, and None turns encryption off. A short timeout keeps a wrong host from holding the page.
     *
     * @return array<string, mixed>
     */
    private static function smtp(): array
    {
        $encryption = Configuration::get('mail.encryption');

        return [...config('mail.mailers.smtp'),
            'transport' => 'smtp', 'url' => null, 'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => Configuration::get('mail.host'), 'port' => Configuration::get('mail.port'),
            'username' => Configuration::get('mail.username') ?: null, 'password' => Configuration::get('mail.password') ?: null,
            'require_tls' => $encryption === 'tls', 'auto_tls' => $encryption !== 'none', 'timeout' => 15,
        ];
    }
}
