<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Mail\Mailables\Address;

/**
 * Puts the Mail settings into Laravel's mail config. AppServiceProvider runs apply() once the mail manager is resolved,
 * before it builds a mailer, so every email (documents, test emails) goes out the way Settings > Mail says. With
 * "Server configuration" the MAIL_ values of the environment stay in charge; a filled sender applies to both. A company
 * can set its own sender for its lead and document emails (senderFor()).
 */
final class MailConfiguration
{
    /** False when the mailer in use only writes email to the log (or keeps it in memory), so nothing reaches anyone. */
    public static function delivers(): bool
    {
        app('mail.manager');

        return ! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['log', 'array'], true);
    }

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
     * The company's own sender, or null to use the default one. A missing address falls back to the default address
     * and a missing name to the company name.
     */
    public static function senderFor(Company $company): ?Address
    {
        if (! $company->mail_from_address && ! $company->mail_from_name) {
            return null;
        }
        app('mail.manager');

        return new Address($company->mail_from_address ?: (string) config('mail.from.address'), $company->mail_from_name ?: $company->name);
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
