<?php

namespace Classes;

use Dompdf\Dompdf;
use Dompdf\Options;

class TicketPdfService
{
  public static function buildTicketsPdf(array $payload, string $outputDir = '../public/tickets'): string
  {
    if (!is_dir($outputDir)) {
      @mkdir($outputDir, 0775, true);
    }

    $date = $payload['trip_data']['date'] ?? '';
    $namePayment = $payload['trip_data']['tickets_name'] ?? 'rh-experience';
    $passengers = $payload['passengers'] ?? [];
    $seats = $payload['trip_data']['seats'] ?? [];
    $registration = $payload['registration'] ?? [];
    $globalRegistrationType = $registration['registration_type'] ?? '';

    $html = self::buildHtmlHeader("Boletos RH Experience");

    $totalPassengers = count($passengers);

    foreach ($passengers as $idx => $pax) {
      $seatId = $pax['seat_id'] ?? ($seats[$idx] ?? '');
      $ticketCode = trim($pax['ticket_code'] ?? '') ?: 'SIN-CODIGO';
      $registrationType = $pax['registration_type'] ?? $globalRegistrationType;
      $clientTypeLabel = self::getRegistrationTypeLabel($registrationType);

      $html .= self::ticketPageHtml([
        'date' => $date,
        'date_label' => self::formatDateForTicket($date),
        'seat_id' => $seatId,
        'first_name' => $pax['first_name'] ?? '',
        'last_name' => $pax['last_name'] ?? '',
        'email' => $pax['email'] ?? '',
        'phone' => $pax['phone'] ?? '',
        'index' => $idx + 1,
        'count' => $totalPassengers,
        'ticket_code' => $ticketCode,
        'is_last' => ($idx + 1) === $totalPassengers,
        'client_type' => $registrationType,
        'client_type_label' => $clientTypeLabel,
      ]);
    }

    $html .= '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safeName = self::safeFilename($namePayment);

    $filename = sprintf(
      '%s/RH-Tickets-%s-%s.pdf',
      rtrim($outputDir, '/'),
      $safeName,
      date('YmdHis')
    );

    file_put_contents($filename, $dompdf->output());

    return $filename;
  }

  public static function buildPaymentSlipPdf(array $payload, string $outputDir = '../public/tickets'): string
  {
    if (!is_dir($outputDir)) {
      @mkdir($outputDir, 0775, true);
    }

    $clientName = $payload['client_name'] ?? 'Cliente';
    $clientEmail = $payload['client_email'] ?? '';
    $tripDate = $payload['trip_date'] ?? '';
    $seats = $payload['seats'] ?? [];
    $ticketsCount = (int) ($payload['tickets_count'] ?? 1);
    $pricePerTicket = (float) ($payload['price_per_ticket'] ?? 0);
    $totalAmount = (float) ($payload['total_amount'] ?? 0);
    $passengers = $payload['passengers'] ?? [];
    $registration = $payload['registration'] ?? [];

    $html = self::buildPaymentSlipHtml([
      'client_name' => $clientName,
      'client_email' => $clientEmail,
      'trip_date' => $tripDate,
      'seats' => $seats,
      'tickets_count' => $ticketsCount,
      'price_per_ticket' => $pricePerTicket,
      'total_amount' => $totalAmount,
      'passengers' => $passengers,
      'registration' => $registration,
    ]);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safeName = self::safeFilename($clientName);

    $filename = sprintf(
      '%s/RH-Ficha-Pago-%s-%s.pdf',
      rtrim($outputDir, '/'),
      $safeName,
      date('YmdHis')
    );

    file_put_contents($filename, $dompdf->output());

    return $filename;
  }

  private static function buildHtmlHeader(string $title): string
  {
    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>{$title}</title>

<style>
  @page {
    margin: 24pt;
  }

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    padding: 0;
    font-family: DejaVu Sans, Arial, sans-serif;
    color: #0f172a;
    background: #ffffff;
  }

  .page {
    width: 100%;
    min-height: 760px;
  }

  .pagebreak {
    page-break-after: always;
  }

  .ticket-shell {
    width: 100%;
    border: 1px solid #dbe3ef;
    border-radius: 24px;
    overflow: hidden;
    background: #ffffff;
  }

  .hero {
    background: #315fa8;
    color: #ffffff;
    padding: 28px 30px;
  }

  .hero-table {
    width: 100%;
    border-collapse: collapse;
  }

  .eyebrow {
    margin: 0;
    font-size: 10px;
    letter-spacing: 2.5px;
    text-transform: uppercase;
    color: #dbeafe;
    font-weight: bold;
  }

  .brand-title {
    margin: 8px 0 0;
    font-size: 28px;
    line-height: 1.1;
    font-weight: 900;
    color: #ffffff;
  }

  .hero-subtitle {
    margin: 8px 0 0;
    font-size: 12px;
    line-height: 1.5;
    color: #e0ecff;
  }

  .status-badge {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 999px;
    background: #ffffff;
    color: #315fa8;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 1px;
    text-transform: uppercase;
  }

  .ticket-body {
    padding: 26px 30px 30px;
  }

  .passenger-card {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 22px;
  }

  .passenger-name {
    font-size: 26px;
    line-height: 1.2;
    font-weight: 900;
    color: #0f172a;
    margin: 0;
  }

  .passenger-label {
    margin: 0 0 6px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.4px;
    color: #64748b;
    font-weight: bold;
  }

  .ticket-code-box {
    text-align: right;
  }

  .ticket-code {
    display: inline-block;
    padding: 10px 12px;
    border-radius: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #0f172a;
    font-size: 12px;
    font-weight: bold;
  }

  .details-grid {
    width: 100%;
    border-collapse: separate;
    border-spacing: 10px;
    margin-left: -10px;
    margin-right: -10px;
  }

  .detail-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 15px;
    vertical-align: top;
  }

  .detail-label {
    display: block;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    color: #64748b;
    font-weight: bold;
    margin-bottom: 6px;
  }

  .detail-value {
    display: block;
    font-size: 15px;
    line-height: 1.35;
    color: #0f172a;
    font-weight: 800;
  }

  .seat-card {
    background: #fff7ed;
    border-color: #fed7aa;
  }

  .seat-card .detail-label {
    color: #c2410c;
  }

  .seat-card .detail-value {
    color: #7c2d12;
    font-size: 28px;
  }

  .date-card {
    background: #eff6ff;
    border-color: #bfdbfe;
  }

  .date-card .detail-label {
    color: #1d4ed8;
  }

  .date-card .detail-value {
    color: #1e3a8a;
  }

  .info-section {
    width: 100%;
    border-collapse: separate;
    border-spacing: 10px;
    margin-left: -10px;
    margin-right: -10px;
    margin-top: 8px;
  }

  .info-box {
    border-radius: 16px;
    padding: 16px;
    vertical-align: top;
  }

  .meeting-box {
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
  }

  .important-box {
    background: #fdf2f8;
    border: 1px solid #fbcfe8;
  }

  .box-title {
    margin: 0 0 8px;
    font-size: 13px;
    font-weight: 900;
    color: #0f172a;
  }

  .box-text {
    margin: 0;
    font-size: 11px;
    line-height: 1.6;
    color: #475569;
  }

  .divider {
    margin: 24px 0;
    border-top: 1px dashed #cbd5e1;
  }

  .boarding-pass {
    width: 100%;
    border-collapse: collapse;
    background: #0f172a;
    border-radius: 18px;
    color: #ffffff;
  }

  .boarding-pass td {
    padding: 16px;
    vertical-align: middle;
  }

  .boarding-label {
    margin: 0 0 4px;
    color: #94a3b8;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 1.2px;
    font-weight: bold;
  }

  .boarding-value {
    margin: 0;
    color: #ffffff;
    font-size: 14px;
    font-weight: 900;
  }

  .boarding-seat {
    text-align: center;
    background: #d63384;
    border-radius: 14px;
  }

  .boarding-seat .boarding-label,
  .boarding-seat .boarding-value {
    color: #ffffff;
  }

  .footer-note {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #e2e8f0;
    text-align: center;
    color: #64748b;
    font-size: 10px;
    line-height: 1.6;
  }

  .small-muted {
    color: #94a3b8;
    font-size: 10px;
  }
</style>
</head>

<body>
HTML;
  }

  private static function ticketPageHtml(array $d): string
  {
    $fullName = htmlspecialchars(
      trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')),
      ENT_QUOTES,
      'UTF-8'
    );

    if ($fullName === '') {
      $fullName = 'Pasajero RH Experience';
    }

    $date = htmlspecialchars($d['date'] ?? '', ENT_QUOTES, 'UTF-8');
    $dateLabel = htmlspecialchars($d['date_label'] ?? $date, ENT_QUOTES, 'UTF-8');
    $seatId = htmlspecialchars($d['seat_id'] ?? '', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($d['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($d['phone'] ?? '', ENT_QUOTES, 'UTF-8');
    $index = (int) ($d['index'] ?? 1);
    $count = (int) ($d['count'] ?? 1);
    $ticketCode = htmlspecialchars($d['ticket_code'] ?? 'SIN-CODIGO', ENT_QUOTES, 'UTF-8');
    $clientTypeLabel = htmlspecialchars($d['client_type_label'] ?? 'Sin tipo', ENT_QUOTES, 'UTF-8');

    $pagebreak = !empty($d['is_last']) ? '' : '<div class="pagebreak"></div>';

    return <<<HTML
<div class="page">
  <div class="ticket-shell">
    <div class="hero">
      <table class="hero-table">
        <tr>
          <td>
            <p class="eyebrow">Boleto oficial</p>
            <h1 class="brand-title">RH Experience</h1>
            <p class="hero-subtitle">
              Presenta este boleto y una identificación al abordar.
            </p>
          </td>
          <td align="right" width="190">
            <span class="status-badge">Reserva confirmada</span>
          </td>
        </tr>
      </table>
    </div>

    <div class="ticket-body">
      <table class="passenger-card">
        <tr>
          <td>
            <p class="passenger-label">Pasajero</p>
            <h2 class="passenger-name">{$fullName}</h2>
            <p class="small-muted">Boleto {$index} de {$count}</p>
          </td>
          <td class="ticket-code-box" width="190">
            <p class="passenger-label">Código de boleto</p>
            <span class="ticket-code">{$ticketCode}</span>
          </td>
        </tr>
      </table>

      <table class="details-grid">
        <tr>
          <td class="detail-card date-card" width="50%">
            <span class="detail-label">Fecha del viaje</span>
            <span class="detail-value">{$dateLabel}</span>
          </td>

          <td class="detail-card seat-card" width="50%">
            <span class="detail-label">Asiento</span>
            <span class="detail-value">{$seatId}</span>
          </td>
        </tr>

        <tr>
          <td class="detail-card" width="50%">
            <span class="detail-label">Tipo de cliente</span>
            <span class="detail-value">{$clientTypeLabel}</span>
          </td>

          <td class="detail-card" width="50%">
            <span class="detail-label">Código de boleto</span>
            <span class="detail-value">{$ticketCode}</span>
          </td>
        </tr>

        <tr>
          <td class="detail-card" width="50%">
            <span class="detail-label">Correo</span>
            <span class="detail-value">{$email}</span>
          </td>

          <td class="detail-card" width="50%">
            <span class="detail-label">Teléfono</span>
            <span class="detail-value">{$phone}</span>
          </td>
        </tr>
      </table>

      <table class="info-section">
        <tr>
          <td class="info-box meeting-box" width="50%">
            <h3 class="box-title">Punto de encuentro</h3>
            <p class="box-text">
              Oficinas de Grupo RH.<br>
              Llega 10 minutos antes de la hora de salida.
            </p>
          </td>

          <td class="info-box important-box" width="50%">
            <h3 class="box-title">Importante</h3>
            <p class="box-text">
              Conserva este boleto. Será solicitado al abordar junto con una identificación oficial.
            </p>
          </td>
        </tr>
      </table>

      <div class="divider"></div>

      <table class="boarding-pass">
        <tr>
          <td width="28%">
            <p class="boarding-label">RH Experience</p>
            <p class="boarding-value">Boleto de abordaje</p>
          </td>

          <td width="30%">
            <p class="boarding-label">Fecha</p>
            <p class="boarding-value">{$date}</p>
          </td>

          <td width="22%">
            <p class="boarding-label">Código</p>
            <p class="boarding-value">{$ticketCode}</p>
          </td>

          <td width="20%" class="boarding-seat">
            <p class="boarding-label">Asiento</p>
            <p class="boarding-value">{$seatId}</p>
          </td>
        </tr>
      </table>

      <p class="footer-note">
        Este documento fue generado automáticamente por RH Experience.<br>
        Para dudas o cambios, comunícate con el equipo de RH Experience.
      </p>
    </div>
  </div>
</div>
{$pagebreak}
HTML;
  }

  private static function buildPaymentSlipHtml(array $d): string
  {
    $clientName = htmlspecialchars($d['client_name'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');
    $clientEmail = htmlspecialchars($d['client_email'] ?? '', ENT_QUOTES, 'UTF-8');
    $tripDate = htmlspecialchars($d['trip_date'] ?? '', ENT_QUOTES, 'UTF-8');

    $seats = is_array($d['seats'] ?? null)
      ? implode(', ', $d['seats'])
      : ($d['seats'] ?? '');

    $seats = htmlspecialchars($seats, ENT_QUOTES, 'UTF-8');

    $ticketsCount = (int) ($d['tickets_count'] ?? 1);
    $pricePerTicket = (float) ($d['price_per_ticket'] ?? 0);
    $totalAmount = (float) ($d['total_amount'] ?? 0);

    $totalFormatted = '$' . number_format($totalAmount, 2) . ' MXN';

    $registration = $d['registration'] ?? [];

    $development = htmlspecialchars($registration['development'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');
    $advisorName = htmlspecialchars($registration['advisor_name'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');
    $advisorCode = htmlspecialchars($registration['advisor_code'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');
    $origin = htmlspecialchars($registration['origin'] ?? 'No registrado', ENT_QUOTES, 'UTF-8');

    $passengersHtml = '';

    foreach (($d['passengers'] ?? []) as $index => $p) {
      $fullName = htmlspecialchars(
        trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')),
        ENT_QUOTES,
        'UTF-8'
      );

      $seatId = htmlspecialchars($p['seat_id'] ?? '', ENT_QUOTES, 'UTF-8');
      $paymentStatus = $p['payment_status'] ?? 'free';

      $passengerRegistrationType =
        $p['registration_type']
        ?? $p['client_type']
        ?? $registration['registration_type']
        ?? '';

      $clientTypeLabel = htmlspecialchars(
        self::getRegistrationTypeLabel($passengerRegistrationType),
        ENT_QUOTES,
        'UTF-8'
      );

      if (array_key_exists('amount', $p)) {
        $amount = (float) $p['amount'];
      } else {
        $amount = $paymentStatus === 'free' ? 0 : $pricePerTicket;
      }

      $amountFormatted = '$' . number_format($amount, 2) . ' MXN';

      $passengersHtml .= "
                <tr>
                    <td>" . ($index + 1) . "</td>
                    <td>{$fullName}</td>
                    <td>{$clientTypeLabel}</td>
                    <td>{$seatId}</td>
                    <td>{$amountFormatted}</td>
                </tr>
            ";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ficha de pago RH Experience</title>
<style>
  @page { margin: 28pt; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    color: #1f2937;
    background: #ffffff;
  }

  .header {
    background: #315fa8;
    color: #ffffff;
    padding: 22px;
    border-radius: 14px;
    margin-bottom: 22px;
  }

  .brand {
    font-size: 24px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .subtitle {
    font-size: 13px;
    opacity: .9;
  }

  .badge {
    display: inline-block;
    background: #fce7f3;
    color: #be185d;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 12px;
  }

  .section {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 16px;
  }

  .section-title {
    font-size: 15px;
    font-weight: 800;
    margin-bottom: 12px;
    color: #111827;
  }

  .grid {
    width: 100%;
  }

  .grid td {
    width: 50%;
    padding: 8px;
    background: #f9fafb;
    border: 1px solid #f1f5f9;
    vertical-align: top;
  }

  .label {
    display: block;
    font-size: 10px;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 3px;
  }

  .value {
    font-size: 13px;
    font-weight: 700;
    color: #111827;
  }

  table.passengers {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }

  table.passengers th {
    background: #f3f4f6;
    text-align: left;
    padding: 9px;
    border-bottom: 1px solid #e5e7eb;
  }

  table.passengers td {
    padding: 9px;
    border-bottom: 1px solid #e5e7eb;
  }

  .total-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 18px;
    text-align: right;
  }

  .total-label {
    font-size: 12px;
    color: #1d4ed8;
    font-weight: 700;
    text-transform: uppercase;
  }

  .total-amount {
    font-size: 30px;
    color: #1e3a8a;
    font-weight: 900;
    margin-top: 4px;
  }

  .footer {
    margin-top: 20px;
    font-size: 10px;
    color: #6b7280;
    text-align: center;
  }
</style>
</head>

<body>
  <div class="header">
    <div class="brand">RH Experience</div>
    <div class="subtitle">Ficha de pago RH Experience</div>
  </div>

  <div class="badge">PAGO PENDIENTE</div>

  <div class="section">
    <div class="section-title">Datos de la reservación</div>

    <table class="grid">
      <tr>
        <td>
          <span class="label">Cliente</span>
          <span class="value">{$clientName}</span>
        </td>
        <td>
          <span class="label">Correo</span>
          <span class="value">{$clientEmail}</span>
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Fecha del viaje</span>
          <span class="value">{$tripDate}</span>
        </td>
        <td>
          <span class="label">Asientos</span>
          <span class="value">{$seats}</span>
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Desarrollo de interés</span>
          <span class="value">{$development}</span>
        </td>
        <td>
          <span class="label">Origen</span>
          <span class="value">{$origin}</span>
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Asesor</span>
          <span class="value">{$advisorName}</span>
        </td>
        <td>
          <span class="label">Código asesor</span>
          <span class="value">{$advisorCode}</span>
        </td>
      </tr>
    </table>
  </div>

  <div class="section">
    <div class="section-title">Detalle de boletos</div>

    <table class="passengers">
      <thead>
        <tr>
          <th>#</th>
          <th>Pasajero</th>
          <th>Tipo de cliente</th>
          <th>Asiento</th>
          <th>Importe</th>
        </tr>
      </thead>
      <tbody>
        {$passengersHtml}
      </tbody>
    </table>
  </div>

  <div class="total-box">
    <div class="total-label">Total a pagar</div>
    <div class="total-amount">{$totalFormatted}</div>
  </div>

  <div class="footer">
    Este documento fue generado automáticamente por RH Experience.
  </div>
</body>
</html>
HTML;
  }

  private static function formatDateForTicket(string $date): string
  {
    if (!$date) {
      return 'Fecha por confirmar';
    }

    try {
      $dt = new \DateTime($date);

      $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
      ];

      $day = $dt->format('j');
      $month = $months[(int) $dt->format('n')] ?? $dt->format('m');
      $year = $dt->format('Y');

      return "{$day} de {$month} de {$year}";
    } catch (\Throwable $e) {
      return $date;
    }
  }

  private static function getRegistrationTypeLabel(?string $type): string
  {
    return match ($type) {
      'internal' => 'Personal interno',
      'alliance' => 'Alianza',
      'prize' => 'Premio',
      'investor' => 'Inversionista',
      'prospect' => 'Cliente prospecto',
      'family_friend' => 'Familiares y Amigos',
      default => 'Sin tipo',
    };
  }

  private static function safeFilename(string $value): string
  {
    $value = trim($value);

    if ($value === '') {
      return 'rh-experience';
    }

    $value = preg_replace('/[^A-Za-z0-9_\-]/', '-', $value);

    return trim($value, '-') ?: 'rh-experience';
  }
}