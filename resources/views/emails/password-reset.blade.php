<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Restablecer contraseña</title>
    </head>
    <body style="margin:0;background:#f4f7fb;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:48px 16px;">
            <tr>
                <td align="center">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:700px;background:#ffffff;border-radius:10px;overflow:hidden;">
                        <tr>
                            <td align="center" style="padding:34px 32px 10px;">
                                <img src="{{ asset('assets/logo-orvix.svg') }}" alt="Orvix Prestamos" width="150" style="display:block;height:auto;margin:0 auto 28px;">
                                <h1 style="margin:0;color:#08132b;font-size:36px;line-height:1.15;font-weight:800;">Restablece<br><span style="color:#0d9488;">tu contraseña</span></h1>
                                <p style="margin:26px 0 0;color:#172033;font-size:16px;line-height:1.7;">Hola {{ $user->name }}, recibimos una solicitud para recuperar el acceso a tu cuenta de Orvix Prestamos.</p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:28px 40px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#eaf7f5;border-radius:8px;">
                                    <tr>
                                        <td align="center" style="padding:34px 30px;">
                                            <h2 style="margin:0 0 24px;color:#08132b;font-size:22px;line-height:1.3;">Datos de recuperacion</h2>
                                            <p style="margin:0;color:#64748b;font-size:13px;">Correo</p>
                                            <p style="margin:7px 0 22px;color:#08132b;font-size:16px;font-weight:700;">{{ $user->email }}</p>
                                            <p style="margin:0;color:#64748b;font-size:13px;">Vigencia del enlace</p>
                                            <p style="margin:7px 0 28px;color:#08132b;font-size:16px;font-weight:700;">{{ $expiresIn }} minutos</p>
                                            <a href="{{ $resetUrl }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;border-radius:7px;padding:14px 26px;font-size:15px;font-weight:800;">Generar nueva contraseña</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:0 40px 36px;">
                                <p style="margin:0 0 12px;color:#475569;font-size:14px;line-height:1.6;">Si el boton no abre, copia y pega este enlace en tu navegador:</p>
                                <p style="margin:0;word-break:break-all;color:#0d9488;font-size:13px;line-height:1.6;"><a href="{{ $resetUrl }}" style="color:#0d9488;">{{ $resetUrl }}</a></p>
                                <p style="margin:28px 0 0;color:#64748b;font-size:13px;line-height:1.6;">Si no solicitaste este cambio, puedes ignorar este mensaje. Tu contraseña actual seguira activa.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
</html>
