{{-- Body of an emailed document (App\Mail\DocumentMail). Plain, inline-styled HTML that mail clients render. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $label }}</title></head>
<body style="margin: 0; padding: 24px 12px; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.55; color: #1f2937;">
    <div style="max-width: 600px; margin: 0 auto; padding: 24px; background: #ffffff; border-radius: 8px;">
        @if(trim($messageText) !== '')
            <div>{!! nl2br(e($messageText), false) !!}</div>
        @endif
        <p style="margin: 20px 0 0; color: #4b5563;">{{ __(':document is attached as a PDF.', ['document' => $label]) }}</p>
        @if($shareUrl)
            <p style="margin: 12px 0 0;"><a href="{{ $shareUrl }}" style="color: #166534; font-weight: bold;">{{ __('View it online') }}</a></p>
        @endif
        <p style="margin: 24px 0 0; font-size: 13px; color: #6b7280;">{{ $companyName }}</p>
    </div>
</body>
</html>
