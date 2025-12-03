<?php

namespace Classes;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Email
{
  public $email;
  public $nombre;
  public $subject;
  public $body;

  private $mail;
  private $fecha;
  private $asientos;
  private $tripId;

  public function __construct($email, $nombre, $subject, $fecha, $asientos = '', $tripId = '')
  {
    $this->email   = $email;
    $this->nombre  = $nombre;
    $this->subject = $subject;
    $this->fecha   = $fecha;
    $this->asientos = $asientos;
    $this->tripId   = $tripId;

    // Inicializa PHPMailer y configura SMTP primero
    $this->mail = new PHPMailer(true);
    $this->configurarSMTP();



    // Construye cuerpo HTML + texto plano
    $this->body = $this->buildEmailTemplate();
    $this->mail->Body    = $this->body;
    $this->mail->AltBody = $this->buildPlainTextTemplate();
  }

  private function configurarSMTP(): void
  {
    try {
      // Servidor
      $this->mail->isSMTP();
      $this->mail->Host       = 'smtp.gmail.com';
      $this->mail->SMTPAuth   = true;
      $this->mail->Username   = $_ENV['GMAIL_ACCOUNT'];
      $this->mail->Password   = $_ENV['GMAIL_PASSWORD'];
      $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $this->mail->Port       = 587;

      // Orígenes y destino
      $this->mail->setFrom($_ENV['GMAIL_ACCOUNT'], 'RH Experience');
      $this->mail->addAddress($this->email, $this->nombre);

      // Contenido
      $this->mail->isHTML(true);
      $this->mail->Subject = $this->subject;
      $this->mail->CharSet = 'UTF-8';
    } catch (Exception $e) {
      throw new \Exception("Error configurando SMTP: " . $e->getMessage());
    }
  }



  /**
   * Construye el HTML del email (usa <img> en vez de background-image).
   */
  private function buildEmailTemplate(): string
  {
    // Escapes mínimos
    $nombre = htmlspecialchars($this->nombre ?? '', ENT_QUOTES, 'UTF-8');
    $fecha  = htmlspecialchars($this->fecha ?? '', ENT_QUOTES, 'UTF-8');
    $asientos = htmlspecialchars($this->asientos ?? '', ENT_QUOTES, 'UTF-8');
    $tripId   = htmlspecialchars($this->tripId ?? '', ENT_QUOTES, 'UTF-8');

    $asientosHtml = $this->asientos ? "
            <div class='detail-item'>
                <span><strong>Asientos: </strong></span><span>{$asientos}</span>
            </div>" : "";

    $year = date('Y');

    return "
        <!DOCTYPE html>
        <html lang='es'>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
          <title>Confirmación de Reserva - RH Experience</title>
          <style>
            body { margin:0; padding:0; background:#f4f4f4; font-family: Arial, sans-serif; line-height:1.6; }
            .container { max-width:600px; margin:20px auto; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 0 20px rgba(0,0,0,.1); }
            .header img { display:block; width:100%; height:auto; }
            .header-title { text-align:center; padding:20px; background:#d63384; color:#fff; }
            .header-title h1 { margin:0 0 5px 0; }
            .content { padding:30px; }
            .info-box { background:#f8f9fa; border-left:4px solid #d63384; padding:20px; margin:20px 0; border-radius:8px; }
            .detail-item { display:flex; justify-content:space-between; border-bottom:1px solid #dee2e6; padding:10px 0; }
            .detail-item:last-child { border-bottom:none; }
            .footer { background:#2c3e50; color:#fff; text-align:center; padding:20px; font-size:14px; }
            .success-badge { background:#28a745; color:#fff; padding:10px 20px; border-radius:25px; display:inline-block; margin-bottom:20px; font-weight:bold; }
            .welcome { margin-bottom:10px; }
            .contact { background:#e3f2fd; padding:20px; border-radius:8px; text-align:center; border:1px solid #bbdefb; }
          </style>
        </head>
        <body>
          <div class='container'>
            <div class='header'>
              <div class='header-title'>
                <h1>🚌 RH Experience</h1>
                <p>Tu aventura comienza aquí</p>
              </div>
            </div>

            <div class='content'>
              <div class='success-badge'>✅ RESERVA CONFIRMADA</div>
              <h2 class='welcome'>¡Hola {$nombre}!</h2>
              <p>Tu reserva ha sido confirmada exitosamente. Aquí tienes los detalles de tu viaje:</p>

              <div class='info-box'>
                <h3>📋 Detalles de la Reserva</h3>
                <div class='detail-item'><span><strong>Fecha del viaje:</strong></span><span>{$fecha}</span></div>
                {$asientosHtml}
                {$tripIdHtml}
              </div>

              <div class='info-box'>
                <h3>📍 Punto de encuentro</h3>
                <p><strong>Oficinas de Grupo RH</strong></p>
                <p>Por favor llega 10 minutos antes de la hora de salida.</p>
              </div>

              <div class='contact'>
                <h3>📞 ¿Necesitas ayuda?</h3>
                <p><strong>Email:</strong> rhexperience@gruporh.com</p>
                <p><strong>Teléfono:</strong> +52 999 242 0278</p>
                <p><strong>Horario:</strong> Lunes a Viernes 9:00 - 18:00 hrs</p>
              </div>
            </div>

            <div class='footer'>
              <p>&copy; {$year} RH Experience. Todos los derechos reservados.</p>
              <p>Este es un correo automático, por favor no respondas a este mensaje.</p>
            </div>
          </div>
        </body>
        </html>";
  }

  private function buildPlainTextTemplate(): string
  {
    $year = date('Y');
    $text = "
CONFIRMACIÓN DE RESERVA - RH EXPERIENCE

¡Hola {$this->nombre}!

Tu reserva ha sido confirmada exitosamente.

INFORMACIÓN DE LA RESERVA:
- Fecha del viaje: {$this->fecha}
";

    if ($this->asientos) {
      $text .= "- Asientos reservados: {$this->asientos}\n";
    }

    if ($this->tripId) {
      $text .= "- ID de reserva: #{$this->tripId}\n";
    }

    $text .= "
PUNTO DE ENCUENTRO:
Oficinas de Grupo RH
Por favor llega 30 minutos antes de la hora de salida.

CONTACTO:
Email: rhexperience@gruporh.com
Teléfono: +52 999 242 0278
Horario: Lunes a Viernes 9:00 - 18:00 hrs

© {$year} RH Experience. Todos los derechos reservados.
";
    return $text;
  }

  public function setBody($body): self
  {
    $this->body = $body;
    $this->mail->Body = $body;
    // Mantén AltBody legible (opcional: podrías regenerar desde buildPlainTextTemplate)
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
      error_log("Error enviando email: " . $e->getMessage());
      return false;
    }
  }

  public function getError(): string
  {
    return $this->mail->ErrorInfo;
  }

  // Envío rápido
  public static function sendQuickEmail($to, $name, $subject, $fecha, $asientos = '', $tripId = ''): bool
  {
    $email = new self($to, $name, $subject, $fecha, $asientos, $tripId);
    return $email->send();
  }
}
