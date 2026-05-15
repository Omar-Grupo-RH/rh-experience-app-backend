<?php

namespace Classes;

use Dompdf\Dompdf;
use Dompdf\Options;

class TicketPdfService
{
  /**
   * Genera un solo PDF con una página por pasajero. Devuelve la ruta del archivo.
   */
  public static function buildTicketsPdf(array $payload, string $outputDir = '../public/tickets'): string
  {
    if (!is_dir($outputDir)) {
      @mkdir($outputDir, 0775, true);
    }

    $date = $payload['trip_data']['date'] ?? '2025-11-10';
    $namePayment = $payload['trip_data']['tickets_name'] ?? 'rh-experience';
    $passengers = $payload['passengers'] ?? [];

    $html = self::buildHtmlHeader("Boletos RH Experience");

    foreach ($passengers as $idx => $pax) {
      $html .= self::ticketPageHtml([
        'date' => $date,
        'seat_id' => $pax['seat_id'] ?? '',
        'first_name' => $pax['first_name'] ?? '',
        'last_name' => $pax['last_name'] ?? '',
        'email' => $pax['email'] ?? '',
        'phone' => $pax['phone'] ?? '',
        'index' => $idx + 1,
        'count' => count($passengers),
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
    $pricePerTicket = (float) ($payload['price_per_ticket'] ?? 800);
    $totalAmount = (float) ($payload['total_amount'] ?? ($ticketsCount * $pricePerTicket));
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
  @page { margin: 24pt; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color: #222; }
  .ticket { border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 28px; }
  .row { display: flex; gap: 16px; }
  .col { flex: 1; }
  .header { display:flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
  .brand { font-size: 18px; font-weight: 700; color: #d63384; }
  .badge { background: #f1f5f9; border: 1px solid #e2e8f0; padding: 4px 8px; border-radius: 8px; font-size: 12px; }
  .title { font-size: 20px; font-weight: 700; margin: 8px 0 12px; }
  .grid { display:grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .item { background:#fafafa; border:1px solid #eee; border-radius:8px; padding:8px; }
  .label { font-size: 11px; color:#6b7280; }
  .footer { font-size: 10px; color:#6b7280; margin-top: 10px; }
  .pagebreak { page-break-after: always; }
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

    $date = htmlspecialchars($d['date'] ?? '', ENT_QUOTES, 'UTF-8');
    $seatId = htmlspecialchars($d['seat_id'] ?? '', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($d['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($d['phone'] ?? '', ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="ticket">
  <div class="header">
    <div class="brand">RH Experience — Boleto - {$date}</div>
  </div>

  <div class="row">
    <div class="col">
      <div class="title">Pasajero: {$fullName}</div>
      <div class="grid">
        <div class="item"><div class="label">Asiento</div><div class="value">{$seatId}</div></div>
        <div class="item"><div class="label">Email</div><div class="value">{$email}</div></div>
        <div class="item"><div class="label">Teléfono</div><div class="value">{$phone}</div></div>
      </div>
      <div class="footer">Por favor, presenta este boleto y una identificación al abordar. Llega 10 minutos antes de la salida.</div>
    </div>
  </div>
</div>
<div class="pagebreak"></div>
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
    $pricePerTicket = (float) ($d['price_per_ticket'] ?? 800);
    $totalAmount = (float) ($d['total_amount'] ?? ($ticketsCount * $pricePerTicket));

    $priceFormatted = '$' . number_format($pricePerTicket, 2) . ' MXN';
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

      $passengersHtml .= "
                <tr>
                    <td>" . ($index + 1) . "</td>
                    <td>{$fullName}</td>
                    <td>{$seatId}</td>
                    <td>{$priceFormatted}</td>
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
  .instructions {
    background: #fff7ed;
    border: 1px solid #fed7aa;
    border-radius: 14px;
    padding: 16px;
    font-size: 12px;
    color: #9a3412;
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
    <div class="subtitle">Ficha de pago para cliente prospecto</div>
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

  <div class="section">
    <div class="section-title">Información de pago</div>

    <div class="instructions">
      <strong>Importante:</strong> Esta ficha corresponde a una reservación para cliente prospecto.
      Para completar el proceso, realiza el pago correspondiente y comparte el comprobante con tu asesor o con el equipo de RH Experience.
      <br><br>
      <strong>Monto por boleto:</strong> {$priceFormatted}<br>
      <strong>Número de boletos:</strong> {$ticketsCount}<br>
      <strong>Total:</strong> {$totalFormatted}
    </div>
  </div>

  <div class="footer">
    Este documento fue generado automáticamente por RH Experience.
  </div>
</body>
</html>
HTML;
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