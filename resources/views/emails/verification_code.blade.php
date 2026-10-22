<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; background:#f6f6f6; padding:24px;">
    <div style="max-width:420px; margin:0 auto; background:#fff; border-radius:8px; padding:32px; text-align:center;">
        <h2 style="margin-top:0;">{{ config('app.name') }}</h2>
        <p>Tu código de verificación es:</p>
        <p style="font-size:32px; font-weight:bold; letter-spacing:8px; margin:24px 0;">{{ $code }}</p>
        <p style="color:#666; font-size:14px;">Este código expira en 10 minutos. Si tú no lo solicitaste, ignora este correo.</p>
    </div>
</body>
</html>