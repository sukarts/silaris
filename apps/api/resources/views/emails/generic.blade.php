<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2430;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
  <tr><td align="center">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:10px;border:1px solid #e3e6ea;">
      <tr><td style="padding:22px 32px 10px;font-size:15px;font-weight:bold;letter-spacing:3px;">
        SILA<span style="color:#e8663d;">RIS</span>
      </td></tr>
      <tr><td style="padding:6px 32px 8px;font-size:17px;font-weight:bold;">{{ $title }}</td></tr>
      @foreach ($lines as $line)
      <tr><td style="padding:4px 32px;font-size:14px;line-height:1.55;color:#3a4150;">{!! nl2br(e($line)) !!}</td></tr>
      @endforeach
      @if (!empty($code))
      <tr><td style="padding:14px 32px;">
        <div style="background:#f4f5f7;border:1px solid #e3e6ea;border-radius:8px;padding:12px 16px;font-family:Consolas,Menlo,monospace;font-size:15px;letter-spacing:1px;word-break:break-all;">{{ $code }}</div>
      </td></tr>
      @endif
      @if (!empty($ctaUrl))
      <tr><td style="padding:16px 32px;">
        <a href="{{ $ctaUrl }}" style="display:inline-block;background:#e8663d;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:11px 22px;border-radius:8px;">{{ $ctaLabel ?? 'Ouvrir SILARIS' }}</a>
      </td></tr>
      @endif
      <tr><td style="padding:18px 32px 22px;font-size:12px;color:#8a93a3;border-top:1px solid #eef0f3;">
        Message automatique envoyé par SILARIS — merci de ne pas y répondre.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
