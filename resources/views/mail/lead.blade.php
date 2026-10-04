{{-- Body of an email to a CRM lead (App\Mail\LeadMail). Plain, inline-styled HTML that mail clients render. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $subjectLine }}</title></head>
<body style="margin: 0; padding: 24px 12px; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.55; color: #1f2937;">
    <div style="max-width: 600px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 8px;">
        <div>{!! nl2br(e($messageText), false) !!}</div>
        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280;">{{ $companyName }}</p>
    </div>
</body>
</html>
