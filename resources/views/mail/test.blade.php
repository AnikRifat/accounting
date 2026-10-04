{{-- Body of a test email (App\Mail\TestMail). Plain, inline-styled HTML that mail clients render. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('Test email from :app', ['app' => $appName]) }}</title></head>
<body style="margin: 0; padding: 24px 12px; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.55; color: #1f2937;">
    <div style="max-width: 600px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 8px;">
        <p style="margin: 0; font-weight: bold;">{{ __('Your mail settings work.') }}</p>
        <p style="margin: 12px 0 0;">{{ __(':app can send email. Invoices and other documents will go out the same way.', ['app' => $appName]) }}</p>
        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280;">{{ __('Sent by :name on :time.', ['name' => $senderName, 'time' => $sentAt]) }}</p>
    </div>
</body>
</html>
