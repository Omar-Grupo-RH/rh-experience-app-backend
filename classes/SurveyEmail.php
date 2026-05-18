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
      $this->configureSMTP($mail);

      $mail->addAddress($this->email, $this->name);

      $mail->isHTML(true);
      $mail->Subject = 'Queremos conocer tu experiencia';
      $mail->Body = $this->buildHtml();
      $mail->AltBody = $this->buildText();

      return $mail->send();
    } catch (Exception $e) {
      error_log('Error enviando encuesta: ' . $mail->ErrorInfo);
      return false;
    } catch (\Throwable $e) {
      error_log('Error enviando encuesta: ' . $e->getMessage());
      return false;
    }
  }

  private function configureSMTP(PHPMailer $mail): void
  {
    $host = $_ENV['MAIL_HOST'] ?? '';
    $username = $_ENV['MAIL_USERNAME'] ?? '';
    $password = $_ENV['MAIL_PASSWORD'] ?? '';
    $from = $_ENV['MAIL_FROM'] ?? $username;
    $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'RH Experience';
    $port = (int) ($_ENV['MAIL_PORT'] ?? 465);
    $encryption = strtolower($_ENV['MAIL_ENCRYPTION'] ?? 'ssl');

    if (!$host || !$username || !$password || !$from) {
      throw new \Exception('Faltan variables SMTP en el .env.');
    }

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;

    $mail->SMTPSecure = $encryption === 'ssl'
      ? PHPMailer::ENCRYPTION_SMTPS
      : PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port = $port;

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    $mail->setFrom($from, $fromName);
    $mail->addReplyTo($from, $fromName);
  }

  private function buildHtml(): string
  {
    $name = htmlspecialchars($this->name ?: 'Explorador', ENT_QUOTES, 'UTF-8');
    $tripTitle = htmlspecialchars($this->tripTitle, ENT_QUOTES, 'UTF-8');
    $tripDate = htmlspecialchars($this->tripDate, ENT_QUOTES, 'UTF-8');
    $surveyUrl = htmlspecialchars($this->surveyUrl, ENT_QUOTES, 'UTF-8');
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Encuesta RH Experience</title>
</head>

<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 14px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 18px 45px rgba(15,23,42,.08);">
          
          <tr>
            <td style="background:#315fa8;padding:34px 32px;color:#ffffff;">
              <p style="margin:0 0 8px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.22em;color:rgba(255,255,255,.74);">
                RH Experience
              </p>

              <h1 style="margin:0;font-size:30px;line-height:1.15;font-weight:900;color:#ffffff;">
                Queremos conocer tu experiencia
              </h1>

              <p style="margin:12px 0 0;font-size:15px;line-height:1.6;color:rgba(255,255,255,.82);">
                Gracias por ser parte de esta aventura. Tu opinión nos ayuda a mejorar cada recorrido.
              </p>
            </td>
          </tr>

          <tr>
            <td style="padding:32px;">
              <div style="display:inline-block;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;margin-bottom:22px;">
                Encuesta de retroalimentación
              </div>

              <h2 style="margin:0 0 10px;font-size:24px;line-height:1.3;color:#0f172a;font-weight:900;">
                ¡Hola {$name}!
              </h2>

              <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#475569;">
                Queremos agradecerte nuevamente por vivir RH Experience con nosotros. Te invitamos a responder este breve cuestionario para conocer tus impresiones y sugerencias.
              </p>

              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:4px 18px;margin:24px 0;">
                <tr>
                  <td style="padding:18px 0;border-bottom:1px solid #e5e7eb;">
                    <span style="display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">
                      RH Experience
                    </span>
                    <span style="display:block;margin-top:4px;font-size:18px;font-weight:900;color:#111827;">
                      {$tripTitle}
                    </span>
                  </td>
                </tr>

                <tr>
                  <td style="padding:18px 0;">
                    <span style="display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">
                      Fecha del viaje
                    </span>
                    <span style="display:block;margin-top:4px;font-size:18px;font-weight:900;color:#111827;">
                      {$tripDate}
                    </span>
                  </td>
                </tr>
              </table>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                <tr>
                  <td style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:18px;padding:20px;">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#14532d;font-weight:900;">
                      Tu opinión es importante
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#166534;">
                      Tus respuestas nos ayudan a mejorar la organización, el acompañamiento, los recorridos y cada detalle de las próximas experiencias.
                    </p>
                  </td>
                </tr>
              </table>

              <div style="text-align:center;margin:32px 0 28px;">
                <a href="{$surveyUrl}" target="_blank" style="display:inline-block;background:#d63384;color:#ffffff;text-decoration:none;padding:15px 26px;border-radius:14px;font-weight:900;font-size:15px;box-shadow:0 10px 24px rgba(214,51,132,.22);">
                  Responder encuesta
                </a>
              </div>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                <tr>
                  <td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:18px;padding:20px;">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#9a3412;font-weight:900;">
                      Tiempo estimado
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#7c2d12;">
                      El cuestionario es breve y solo tomará unos minutos. Responde con total honestidad; tus comentarios serán revisados por nuestro equipo.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:24px 0 8px;font-size:13px;color:#64748b;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
              </p>

              <p style="margin:0;font-size:12px;line-height:1.6;word-break:break-all;color:#315fa8;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px;">
                {$surveyUrl}
              </p>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:20px;">
                <tr>
                  <td align="center">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#0f172a;font-weight:900;">
                      Gracias por ayudarnos a mejorar
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#475569;">
                      Cada comentario nos acerca a crear experiencias más memorables para clientes, inversionistas y aliados.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="background:#0f172a;padding:22px 32px;text-align:center;">
              <p style="margin:0;font-size:13px;line-height:1.6;color:#cbd5e1;">
                &copy; {$year} RH Experience. Todos los derechos reservados.
              </p>

              <p style="margin:8px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">
                Este correo fue enviado automáticamente por RH Experience.
              </p>
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
    return "
ENCUESTA RH EXPERIENCE

Hola {$this->name},

Gracias por vivir RH Experience con nosotros.

Queremos conocer tu opinión sobre:
{$this->tripTitle}
Fecha: {$this->tripDate}

Tu respuesta nos ayuda a mejorar cada viaje, cada detalle y cada experiencia.

Responde la encuesta aquí:
{$this->surveyUrl}

Gracias por ayudarnos a mejorar.
RH Experience
";
  }
}