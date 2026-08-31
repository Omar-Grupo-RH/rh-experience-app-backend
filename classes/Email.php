<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Email
{
  public string $email;
  public string $nombre;
  public string $subject;
  public string $body;

  private PHPMailer $mail;
  private string $fecha;
  private string $asientos;
  private string $tripId;
  private string $context;
  private string $paymentNotice;

  public function __construct(
    string $email,
    string $nombre,
    string $subject,
    string $fecha,
    string $asientos = '',
    string $tripId = '',
    string $context = 'reservation',
    string $paymentNotice = ''
  ) {
    $this->email = $email;
    $this->nombre = $nombre;
    $this->subject = $subject;
    $this->fecha = $fecha;
    $this->asientos = $asientos;
    $this->tripId = $tripId;
    $this->context = $context;
    $this->paymentNotice = $paymentNotice;

    $this->mail = new PHPMailer(true);
    $this->configurarSMTP();

    $this->body = $this->buildEmailTemplate();
    $this->mail->Body = $this->body;
    $this->mail->AltBody = $this->buildPlainTextTemplate();
  }

  private function configurarSMTP(): void
  {
    try {
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

      $this->mail->isSMTP();
      $this->mail->Host = $host;
      $this->mail->SMTPAuth = true;
      $this->mail->Username = $username;
      $this->mail->Password = $password;

      $this->mail->SMTPSecure = $encryption === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

      $this->mail->Port = $port;

      $this->mail->CharSet = PHPMailer::CHARSET_UTF8;
      $this->mail->Encoding = PHPMailer::ENCODING_BASE64;

      $this->mail->setFrom($from, $fromName);
      $this->mail->addReplyTo($from, $fromName);
      $this->mail->addAddress($this->email, $this->nombre);

      $this->mail->isHTML(true);
      $this->mail->Subject = $this->subject;
    } catch (Exception $e) {
      throw new \Exception("Error configurando SMTP: " . $e->getMessage());
    }
  }

  private function buildEmailTemplate(): string
  {
    $nombre = htmlspecialchars($this->nombre ?? '', ENT_QUOTES, 'UTF-8');
    $fecha = htmlspecialchars($this->fecha ?? '', ENT_QUOTES, 'UTF-8');
    $asientos = htmlspecialchars($this->asientos ?? '', ENT_QUOTES, 'UTF-8');
    $tripId = htmlspecialchars($this->tripId ?? '', ENT_QUOTES, 'UTF-8');
    $paymentNotice = htmlspecialchars($this->paymentNotice ?? '', ENT_QUOTES, 'UTF-8');

    $asientosHtml = $this->asientos ? "
      <tr>
        <td style='padding:14px 0;border-bottom:1px solid #e5e7eb;'>
          <span style='display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;'>Asientos</span>
          <span style='display:block;margin-top:4px;font-size:16px;font-weight:800;color:#111827;'>{$asientos}</span>
        </td>
      </tr>
    " : "";

    $tripIdHtml = $this->tripId ? "
      <tr>
        <td style='padding:14px 0;border-bottom:1px solid #e5e7eb;'>
          <span style='display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;'>ID de reserva</span>
          <span style='display:block;margin-top:4px;font-size:16px;font-weight:800;color:#111827;'>#{$tripId}</span>
        </td>
      </tr>
    " : "";

    $paymentNoticeHtml = $this->paymentNotice ? "
      <table width='100%' cellpadding='0' cellspacing='0' style='margin:24px 0;'>
        <tr>
          <td style='background:#fff1f2;border:1px solid #fecdd3;border-radius:18px;padding:20px;'>
            <h3 style='margin:0 0 10px;font-size:17px;color:#9f1239;font-weight:900;'>
              Motivo del cobro
            </h3>
            <p style='margin:0;font-size:14px;line-height:1.7;color:#9f1239;'>
              {$paymentNotice}
            </p>
          </td>
        </tr>
      </table>
    " : "";

    $year = date('Y');

    $isRescheduled = $this->context === 'rescheduled';

    $headerTitle = $isRescheduled ? 'Reserva reagendada' : 'Reserva confirmada';

    $headerSubtitle = $isRescheduled
      ? 'Actualizamos tu reservación. Adjuntamos tu boleto con la nueva fecha.'
      : 'Tu aventura ya está lista. Adjuntamos tu boleto en este correo.';

    $badgeText = $isRescheduled ? 'Reagendado exitosamente' : 'Confirmación exitosa';

    $introText = $isRescheduled
      ? 'Tu reservación ha sido reagendada correctamente. Por favor, checa los detalles principales para que vivas el RH Experience.'
      : 'Tu reservación ha sido registrada correctamente. Te compartimos los detalles principales de tu RH Experience.';

    $statusText = $isRescheduled ? 'Boleto actualizado' : 'Boleto generado';

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirmación de reserva - RH Experience</title>
</head>

<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 14px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #e5e7eb;box-shadow:0 18px 45px rgba(15,23,42,.08);">
          
          <tr>
            <td style="background:#315fa8;padding:34px 32px;color:#ffffff;">
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td>
                    <p style="margin:0 0 8px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.22em;color:rgba(255,255,255,.74);">
                      RH Experience
                    </p>

                    <h1 style="margin:0;font-size:30px;line-height:1.15;font-weight:900;color:#ffffff;">
                      {$headerTitle}
                    </h1>

                    <p style="margin:12px 0 0;font-size:15px;line-height:1.6;color:rgba(255,255,255,.82);">
                      {$headerSubtitle}
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <tr>
            <td style="padding:32px;">
              <div style="display:inline-block;background:#ecfdf5;color:#15803d;border:1px solid #bbf7d0;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;margin-bottom:22px;">
                {$badgeText}
              </div>

              <h2 style="margin:0 0 10px;font-size:24px;line-height:1.3;color:#0f172a;font-weight:900;">
                ¡Hola {$nombre}!
              </h2>

              <p style="margin:0 0 22px;font-size:15px;line-height:1.7;color:#475569;">
                {$introText}
              </p>

              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:4px 18px;margin:24px 0;">
                <tr>
                  <td style="padding:18px 0;border-bottom:1px solid #e5e7eb;">
                    <span style="display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Fecha del viaje</span>
                    <span style="display:block;margin-top:4px;font-size:18px;font-weight:900;color:#111827;">{$fecha}</span>
                  </td>
                </tr>

                {$asientosHtml}
                {$tripIdHtml}

                <tr>
                  <td style="padding:16px 0;">
                    <span style="display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;">Estado</span>
                    <span style="display:block;margin-top:4px;font-size:16px;font-weight:800;color:#15803d;">{$statusText}</span>
                  </td>
                </tr>
              </table>

              {$paymentNoticeHtml}

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                <tr>
                  <td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:18px;padding:20px;">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#9a3412;font-weight:900;">
                      Punto de encuentro
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#7c2d12;">
                      <a href="https://maps.app.goo.gl/w822T4dPZ1JaKc6N6" target="_blank"><strong>Oficinas de Grupo RH</strong><br></a>
                      El experience se realiza de 9:00 AM a 6:30 PM.<br>Te recomendamos llegar 10 minutos antes para aprovechar al máximo la experiencia.
                    </p>
                  </td>
                </tr>
              </table>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;">
                <tr>
                  <td style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:18px;padding:20px;">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#1e3a8a;font-weight:900;">
                      Antes de abordar
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#1e40af;">
                      Presenta tu boleto adjunto y una identificación. Si reservaste más de un asiento, este correo incluye la información correspondiente a tus pasajeros.
                    </p>
                  </td>
                </tr>
              </table>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;padding:0;overflow:hidden;">
                <tr>
                  <td style="background:#0891b2;padding:22px 24px;">
                    <p style="margin:0 0 6px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:rgba(255,255,255,.75);">
                      Recomendaciones
                    </p>

                    <h3 style="margin:0;font-size:22px;line-height:1.25;color:#ffffff;font-weight:900;">
                      Antes de tu aventura
                    </h3>
                  </td>
                </tr>

                <tr>
                  <td style="padding:24px;">
                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="width:44px;vertical-align:top;">
                          <div style="width:36px;height:36px;border-radius:12px;background:#fce7f3;color:#d63384;text-align:center;line-height:36px;font-size:14px;font-weight:900;">
                            ATV
                          </div>
                        </td>

                        <td style="vertical-align:top;">
                          <h4 style="margin:0 0 10px;font-size:18px;color:#d63384;font-weight:900;text-transform:uppercase;">
                            Cuatrimotos
                          </h4>

                          <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;color:#475569;">
                            <li>Usa playera de <strong style="color:#d63384;">manga larga dry fit</strong>.</li>
                            <li>Lleva pantalón <strong style="color:#d63384;">tipo explorer</strong> de tela gruesa.</li>
                            <li><strong style="color:#d63384;">Retira piercings</strong> que puedan estar en contacto con el casco.</li>
                            <li>Si tienes cabello largo, úsalo con <strong style="color:#d63384;">trenza</strong>. Evita chongos o coletas.</li>
                          </ul>
                        </td>
                      </tr>
                    </table>

                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                      <tr>
                        <td style="width:44px;vertical-align:top;">
                          <div style="width:36px;height:36px;border-radius:12px;background:#ccfbf1;color:#0f766e;text-align:center;line-height:36px;font-size:18px;font-weight:900;">
                            C
                          </div>
                        </td>

                        <td style="vertical-align:top;">
                          <h4 style="margin:0 0 10px;font-size:18px;color:#0f8b9b;font-weight:900;text-transform:uppercase;">
                            Cenotes
                          </h4>

                          <p style="margin:0 0 10px;font-size:13px;line-height:1.6;color:#64748b;">
                            No es obligatorio meterse, pero te aseguramos que te encantará.
                          </p>

                          <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;color:#0f8b9b;font-weight:800;">
                            <li>Traje de baño debajo de tu ropa.</li>
                            <li>Gorra, chanclas y toalla.</li>
                          </ul>
                        </td>
                      </tr>
                    </table>

                    <table width="100%" cellpadding="0" cellspacing="0">
                      <tr>
                        <td style="background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:18px;">
                          <h4 style="margin:0 0 10px;font-size:18px;color:#f59e0b;font-weight:900;text-transform:uppercase;">
                            ¡Importante!
                          </h4>

                          <ul style="margin:0;padding-left:18px;font-size:14px;line-height:1.7;color:#9a3412;">
                            <li>Si tienes alguna condición médica, <strong>indícalo a la brevedad</strong>.</li>
                            <li>Te recomendamos <strong>no llevar artículos de valor</strong>.</li>
                            <li><strong>Escucha atentamente las instrucciones</strong> de tu guía de RH Experience.</li>
                          </ul>
                        </td>
                      </tr>
                    </table>

                    <p style="margin:22px 0 0;font-size:15px;line-height:1.6;color:#0f172a;">
                      Recuerda llegar a tiempo y prepárate para vivir una
                      <strong style="color:#0891b2;">experiencia inolvidable</strong>.
                    </p>
                  </td>
                </tr>
              </table>

              <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:20px;">
                <tr>
                  <td align="center">
                    <h3 style="margin:0 0 10px;font-size:17px;color:#0f172a;font-weight:900;">
                      ¿Necesitas ayuda?
                    </h3>

                    <p style="margin:0;font-size:14px;line-height:1.7;color:#475569;">
                      <strong>Email:</strong> experience@gruporh.mx<br>
                      <strong>Teléfono:</strong> +52 990 375 6551<br>
                      <strong>Horario:</strong> Lunes a viernes de 9:00 a 18:00 hrs
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
                Este correo fue enviado automáticamente. Por favor, no respondas a este mensaje.
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

  private function buildPlainTextTemplate(): string
  {
    $year = date('Y');

    $isRescheduled = $this->context === 'rescheduled';

    $title = $isRescheduled
      ? 'RESERVA REAGENDADA - RH EXPERIENCE'
      : 'CONFIRMACIÓN DE RESERVA - RH EXPERIENCE';

    $intro = $isRescheduled
      ? 'Tu reservación ha sido reagendada correctamente. Por favor, checa los detalles principales para que vivas el RH Experience.'
      : 'Tu reserva ha sido confirmada exitosamente.';

    $text = "
{$title}

¡Hola {$this->nombre}!

{$intro}

DETALLES DE LA RESERVA:
- Fecha del viaje: {$this->fecha}
";

    if ($this->asientos) {
      $text .= "- Asientos reservados: {$this->asientos}\n";
    }

    if ($this->tripId) {
      $text .= "- ID de reserva: #{$this->tripId}\n";
    }

    if ($this->paymentNotice) {
      $text .= "\nMOTIVO DEL COBRO:\n{$this->paymentNotice}\n";
    }

    $text .= "
PUNTO DE ENCUENTRO:
Oficinas de Grupo RH.
Por favor llega 10 minutos antes de la hora de salida.

ANTES DE ABORDAR:
Presenta tu boleto adjunto y una identificación.

RECOMENDACIONES ANTES DE TU AVENTURA:

CUATRIMOTOS:
- Playera de manga larga dry fit.
- Pantalón tipo explorer de tela gruesa.
- Retirar piercings que puedan estar en contacto con el casco.
- Si tienes cabello largo, úsalo con trenza. Evita chongos o coletas.

CENOTES:
No es obligatorio meterse, pero te aseguramos que te encantará.
- Traje de baño debajo de tu ropa.
- Gorra, chanclas y toalla.

IMPORTANTE:
- Si tienes alguna condición médica, indícalo a la brevedad.
- Te recomendamos no llevar artículos de valor.
- Escucha atentamente las instrucciones de tu guía de RH Experience.

Recuerda llegar a tiempo y prepárate para vivir una experiencia inolvidable.

CONTACTO:
Email: experience@gruporh.mx
Teléfono: +52 990 375 6551
Horario: Lunes a viernes de 9:00 a 18:00 hrs.

© {$year} RH Experience. Todos los derechos reservados.
";

    return $text;
  }

  public function setBody($body): self
  {
    $this->body = $body;
    $this->mail->Body = $body;
    $this->mail->AltBody = strip_tags($body);

    return $this;
  }

  public function addAttachment($path, $name = ''): self
  {
    try {
      $this->mail->addAttachment($path, $name);
    } catch (Exception $e) {
      throw new \Exception("Error añadiendo adjunto: " . $e->getMessage());
    }

    return $this;
  }

  public function send(): bool
  {
    try {
      $this->mail->send();
      return true;
    } catch (Exception $e) {
      error_log("Error enviando email: " . $this->mail->ErrorInfo);
      return false;
    }
  }

  public function getError(): string
  {
    return $this->mail->ErrorInfo;
  }

  public static function sendQuickEmail(
    $to,
    $name,
    $subject,
    $fecha,
    $asientos = '',
    $tripId = ''
  ): bool {
    $email = new self($to, $name, $subject, $fecha, $asientos, $tripId);
    return $email->send();
  }
}
