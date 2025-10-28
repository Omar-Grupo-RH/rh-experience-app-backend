<?php

namespace Classes;

use Dompdf\Dompdf;
use Dompdf\Options;

class TicketPdfService
{
  /**
   * Genera un solo PDF con una página por pasajero. Devuelve la ruta del archivo.
   * $payload es el arreglo que muestras en tu mensaje.
   */
  public static function buildTicketsPdf(array $payload, string $outputDir = '../public/tickets'): string
  {
    // Asegura carpeta de salida
    if (!is_dir($outputDir)) {
      @mkdir($outputDir, 0775, true);
    }

    $tripId       = $payload['trip_id'] ?? '';
    $dateIso      = $payload['date'] ?? '';
    $namePayment = $payload['tickets_name'] ?? 'rh-experience';
    $passengers   = $payload['passengers'] ?? [];
    $seats        = $payload['seats'] ?? [];

    // Formato de fecha para mostrar (puedes usar tu formatearFecha)
    $dateForHuman = function (string $iso) {
      // YYYY-MM-DD -> DD/MM/YYYY rápido
      $p = explode('-', $iso);
      return count($p) === 3 ? "{$p[2]}/{$p[1]}/{$p[0]}" : $iso;
    };

    // Construimos HTML con página por pasajero
    $html = self::buildHtmlHeader("Boletos RH Experience");
    foreach ($passengers as $idx => $pax) {
      $ticketCode = self::buildTicketCode($tripId, $dateIso, $pax['seat_id'] ?? '', $pax['email'] ?? '');
      $html .= self::ticketPageHtml([
        'trip_id'      => $tripId,
        'date_human'   => $dateForHuman($dateIso),
        'date_iso'     => $dateIso,
        'seat_id'      => $pax['seat_id'] ?? '',
        'first_name'   => $pax['first_name'] ?? '',
        'last_name'    => $pax['last_name'] ?? '',
        'email'        => $pax['email'] ?? '',
        'phone'        => $pax['phone'] ?? '',
        'ticket_code'  => $ticketCode,
        'qr_data_uri'  => $qrDataUri,
        'index'        => $idx + 1,
        'count'        => count($passengers),
      ]);
    }


    // Configurar Dompdf
    $options = new Options();
    $options->set('isRemoteEnabled', true); // por si usas logos remotos
    $options->set('defaultFont', 'DejaVu Sans'); // buena cobertura UTF-8

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();



    $filename = sprintf('%s/RH-Tickets-Trip-%s-%s.pdf', rtrim($outputDir, '/'), $namePayment, date('YmdHis'));
    file_put_contents($filename, $dompdf->output());

    return $filename;
  }

  private static function buildTicketCode(string $tripId, string $dateIso, string $seatId, string $email): string
  {
    // 18 chars: hash truncado + checksum simple
    $raw = strtoupper(bin2hex(hash('sha256', $tripId . '|' . $dateIso . '|' . $seatId . '|' . strtolower($email) . '|' . microtime(true), true)));
    $code = substr($raw, 0, 17);
    $checksum = substr(strval(array_sum(array_map('ord', str_split($code))) % 10), -1);
    return $code . $checksum;
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
    $fullName = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));

    $html = <<<HTML
<div class="ticket">
  <div class="header">
    <div class="brand">RH Experience — Boleto</div>
    
  </div>

  <div class="row">
    <div class="col">
      <div class="title">Pasajero: {$fullName}</div>
      <div class="grid">
        <div class="item"><div class="label">Trip ID</div><div class="value">{$d['trip_id']}</div></div>
        <div class="item"><div class="label">Fecha</div><div class="value">{$d['date_human']}</div></div>
        <div class="item"><div class="label">Asiento</div><div class="value">{$d['seat_id']}</div></div>
        <div class="item"><div class="label">Código de Boleto</div><div class="value">{$d['ticket_code']}</div></div>
        <div class="item"><div class="label">Email</div><div class="value">{$d['email']}</div></div>
        <div class="item"><div class="label">Teléfono</div><div class="value">{$d['phone']}</div></div>
     
      </div>
      <div class="footer">Por favor, presenta este boleto y una identificación al abordar. Llega 10 minutos antes de la salida.</div>
    </div>
    
  </div>
</div>
<div class="pagebreak"></div>
HTML;

    return $html;
  }
}