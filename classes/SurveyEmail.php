<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class SurveyEmail
{
  public string $email;
  public string $name;
  public string $tripTitle;
  public string $tripDate;
  public string $surveyUrl;

  public function __construct(
    string $email,
    string $name,
    string $tripTitle,
    string $tripDate,
    string $surveyUrl
  ) {
    $this->email = $email;
    $this->name = $name;
    $this->tripTitle = $tripTitle;
    $this->tripDate = $tripDate;
    $this->surveyUrl = $surveyUrl;
  }

  public function send(): bool
  {
    $mail = new PHPMailer(true);

    try {
      $mail->isSMTP();
      $mail->Host =  'smtp.gmail.com';
      $mail->SMTPAuth = true;
      $mail->Username = $_ENV['GMAIL_ACCOUNT'];
      $mail->Password = $_ENV['GMAIL_PASSWORD'];
      $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $mail->Port = 587;

      $mail->CharSet = 'UTF-8';

      $mail->setFrom(
        $_ENV['GMAIL_ACCOUNT'],
        'RH Experience'
      );

      $mail->addAddress($this->email, $this->name);

      $mail->isHTML(true);
      $mail->Subject = 'Queremos conocer tu experiencia';

      $mail->Body = $this->buildHtml();
      $mail->AltBody = $this->buildText();

      return $mail->send();
    } catch (Exception $e) {
      error_log('Error enviando encuesta: ' . $mail->ErrorInfo);
      return false;
    }
  }

  private function buildHtml(): string
  {
    $name = htmlspecialchars($this->name ?: 'Hola', ENT_QUOTES, 'UTF-8');
    $tripTitle = htmlspecialchars($this->tripTitle, ENT_QUOTES, 'UTF-8');
    $tripDate = htmlspecialchars($this->tripDate, ENT_QUOTES, 'UTF-8');
    $surveyUrl = htmlspecialchars($this->surveyUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Encuesta RH Experience</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#1f2937;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:30px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e5e7eb;">
          <tr>
            <td style="background:#315fa8;padding:28px;color:#ffffff;">
              <h1 style="margin:0;font-size:26px;">RH Experience</h1>
              <p style="margin:8px 0 0;font-size:14px;">Gracias por vivir esta experiencia con nosotros.</p>
            </td>
          </tr>

          <tr>
            <td style="padding:28px;">
              <h2 style="margin:0 0 12px;font-size:22px;color:#111827;">
                Hola {$name}
              </h2>

              <p style="font-size:15px;line-height:1.6;color:#4b5563;">
                Queremos conocer tu opinión sobre tu experiencia en:
              </p>

              <div style="background:#f1f5f9;border-radius:14px;padding:18px;margin:20px 0;">
                <p style="margin:0;font-size:13px;color:#64748b;">RH Experience</p>
                <p style="margin:5px 0 0;font-size:18px;font-weight:bold;color:#111827;">{$tripTitle}</p>
                <p style="margin:5px 0 0;font-size:14px;color:#64748b;">Fecha: {$tripDate}</p>
              </div>

              <p style="font-size:15px;line-height:1.6;color:#4b5563;">
                Tu respuesta nos ayuda a mejorar cada viaje, cada detalle y cada experiencia.
              </p>

              <div style="text-align:center;margin:30px 0;">
                <a href="{$surveyUrl}" target="_blank" style="display:inline-block;background:#d63384;color:#ffffff;text-decoration:none;padding:14px 24px;border-radius:12px;font-weight:bold;font-size:15px;">
                  Responder encuesta
                </a>
              </div>

              <p style="font-size:13px;color:#64748b;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
              </p>

              <p style="font-size:12px;word-break:break-all;color:#315fa8;">
                {$surveyUrl}
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding:18px 28px;background:#f8fafc;color:#94a3b8;font-size:12px;text-align:center;">
              Este correo fue enviado automáticamente por RH Experience.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
  }

  private function buildText(): string
  {
    return "Hola {$this->name}, gracias por vivir RH Experience. Responde nuestra encuesta aquí: {$this->surveyUrl}";
  }
}
