<?php

namespace Classes;

use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;
use RuntimeException;
use mysqli;

class PassengerWaiverService
{
    public const VERSION = '2026-10-29-v1';
    private const TEMPLATE_NAME = 'RH Experience - Carta responsiva';

    private static function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function fullName(array $p): string
    {
        return trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
    }

    private static function phone(array $p): string
    {
        $code = preg_replace('/\D/', '', (string) ($p['phone_country_code'] ?? '52'));
        $number = preg_replace('/\D/', '', (string) ($p['phone'] ?? ''));
        return '+' . $code . ' ' . $number;
    }

    private static function email(array $p): string
    {
        return strtolower(trim((string) ($p['email'] ?? '')));
    }

    private static function signatureBytes(array $waiver): string
    {
        $data = $waiver['signature'] ?? '';
        if (!is_string($data) || !str_starts_with($data, 'data:image/png;base64,') || strlen($data) > 300000) {
            throw new InvalidArgumentException('La firma enviada no es válida.');
        }
        $decoded = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
        if ($decoded === false || strlen($decoded) < 100 || strlen($decoded) > 200000) {
            throw new InvalidArgumentException('El tamaño de la firma no es válido.');
        }
        $meta = @getimagesizefromstring($decoded);
        if (!$meta || $meta['mime'] !== 'image/png' || $meta[0] !== 800 || $meta[1] !== 300) {
            throw new InvalidArgumentException('La firma debe ser una imagen PNG de 800×300.');
        }
        return $decoded;
    }

    /** Tinta negra opaca/antialias con alfa original; fondo totalmente transparente. */
    private static function normalizedSignature(string $bytes): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Se requiere la extensión GD para procesar firmas.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            throw new InvalidArgumentException('No se pudo leer la firma.');
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $out = imagecreatetruecolor($width, $height);
        if (!$out) {
            imagedestroy($source);
            throw new RuntimeException('No se pudo crear la imagen de firma.');
        }
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $transparent);
        $blackColors = [];
        $visiblePixels = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($source, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha >= 126) continue;
                if ($alpha < 100) $visiblePixels++;
                if (!isset($blackColors[$alpha])) {
                    $blackColors[$alpha] = imagecolorallocatealpha($out, 0, 0, 0, $alpha);
                }
                imagesetpixel($out, $x, $y, $blackColors[$alpha]);
            }
        }
        imagedestroy($source);
        if ($visiblePixels < 20) {
            imagedestroy($out);
            throw new InvalidArgumentException('La firma parece estar vacía.');
        }
        ob_start();
        imagepng($out);
        $png = ob_get_clean();
        imagedestroy($out);
        if (!is_string($png) || $png === '') {
            throw new RuntimeException('No se pudo guardar la firma normalizada.');
        }
        return $png;
    }

    public static function validate(array $passenger, string $tripId, string $seatId): void
    {
        $waiver = $passenger['waiver'] ?? null;
        if (!is_array($waiver) || ($waiver['version'] ?? '') !== self::VERSION || ($waiver['accepted'] ?? false) !== true) {
            throw new InvalidArgumentException('Falta aceptar la carta responsiva.');
        }
        $for = $waiver['signed_for'] ?? null;
        if (
            !is_array($for)
            || (string) ($for['trip_id'] ?? '') !== $tripId
            || (string) ($for['seat'] ?? '') !== $seatId
            || trim((string) ($for['full_name'] ?? '')) !== self::fullName($passenger)
            || strtolower(trim((string) ($for['email'] ?? ''))) !== self::email($passenger)
            || trim((string) ($for['phone'] ?? '')) !== self::phone($passenger)
        ) {
            throw new InvalidArgumentException('La carta no coincide con los datos del pasajero.');
        }
        self::normalizedSignature(self::signatureBytes($waiver));
    }

    private static function templateId(mysqli $db): int
    {
        $name = self::TEMPLATE_NAME;
        $version = self::VERSION;
        $stmt = $db->prepare('SELECT id FROM waiver_templates WHERE name = ? AND version = ? AND is_active = 1 LIMIT 1');
        if (!$stmt) throw new RuntimeException('No se pudo consultar la plantilla.');
        $stmt->bind_param('ss', $name, $version);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) throw new RuntimeException('No hay una plantilla de responsiva activa.');
        return (int) $row['id'];
    }

    private static function html(array $p, string $png, string $signedAt): string
    {
        $name = self::esc(self::fullName($p));
        $phone = self::esc(self::phone($p));
        $email = self::esc(self::email($p));
        $day = date('d', strtotime($signedAt));
        $year = date('Y', strtotime($signedAt));
        $months = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
        $month = $months[(int) date('n', strtotime($signedAt))];
        $signature = base64_encode($png);
        return <<<HTML
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><style>
@page { size: letter portrait; margin: 70px 85px 65px 85px; }
body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 10px; line-height: 1.5; }
p { margin: 0 0 12px; }
.date { text-align: right; margin-bottom: 18px; }
.details { margin-bottom: 18px; }
.details p { margin: 0 0 5px; }
.data { font-weight: normal; }
.heading { font-weight: bold; margin-top: 16px; margin-bottom: 12px; }
.signature-block { margin-top: 20px; page-break-inside: avoid; }
.signature-row { width: 100%; border-collapse: collapse; }
.signature-label { width: 51%; vertical-align: bottom; white-space: nowrap; }
.signature-place { width: 49%; vertical-align: bottom; text-align: center; }
.signature-place img { max-width: 200px; height: 65px; display: block; margin: 0 auto 2px; }
.signature-place .signer { font-size: 9.5px; border-top: 1px solid #000; padding-top: 3px; }
</style></head><body>
<p class="date">Mérida, Yucatán, a los {$day} días del mes de {$month} año {$year}.</p>
<div class="details">
<p>Nombre completo: <span class="data">{$name}</span></p>
<p>Teléfono: <span class="data">{$phone}</span> &nbsp; Correo: <span class="data">{$email}</span></p>

</div>
<p>Declaro que participo voluntariamente en las actividades de RH Experience y me comprometo a seguir en todo momento las indicaciones de seguridad y uso proporcionadas por el personal de Grupo RH.</p>
<p class="heading">RESPONSABILIDAD POR DAÑOS</p>
<p>Me comprometo a utilizar responsablemente los vehículos RZR/Racer, mobiliario, instalaciones, accesorios y demás equipo utilizado durante la experiencia.</p>
<p>Acepto que, si ocasiono algún daño por negligencia, uso indebido o incumplimiento de las indicaciones recibidas, deberé cubrir el costo comprobable de su reparación o, cuando corresponda, de su reposición.</p>
<p>El daño será documentado mediante fotografías, video y/o reporte de incidencia. No serán responsabilidad del participante los desperfectos preexistentes, desgaste normal o fallas mecánicas que no hayan sido ocasionadas por su conducta.</p>
<p class="heading">GARANTÍA Y COBRO</p>
<p>Cuando la actividad o equipo lo requiera, Grupo RH podrá solicitar previamente una garantía, informando al participante su monto y condiciones antes del inicio de la actividad.</p>
<p>Si la experiencia concluye sin daños atribuibles al participante, la garantía será liberada o devuelta conforme al medio utilizado. En caso de daño comprobado, se aplicará el procedimiento previamente informado para cubrir el costo correspondiente.</p>
<p>Si el daño supera el importe de la garantía, la diferencia será notificada al participante y deberá cubrirse mediante los medios oficiales de pago de Grupo RH.</p>
<p>Autorizo recibir cualquier notificación relacionada con una incidencia a través del teléfono, WhatsApp y/o correo electrónico proporcionados en esta carta.</p>
<p>Declaro haber leído y comprendido estas condiciones y acepto cumplirlas durante mi participación en RH Experience.</p>
<div class="signature-block"><table class="signature-row"><tr>
<td class="signature-label">Nombre y firma del participante:</td>
<td class="signature-place"><img src="data:image/png;base64,{$signature}" alt="Firma"><div class="signer">{$name}</div></td>
</tr></table></div>
</body></html>
HTML;
    }

    public static function save(mysqli $db, int $passengerId, array $passenger, string $storageRoot, array &$createdFiles): void
    {
        $waiver = $passenger['waiver'] ?? null;
        if (!is_array($waiver)) throw new InvalidArgumentException('Falta la carta responsiva.');
        $templateId = self::templateId($db);
        $signature = self::normalizedSignature(self::signatureBytes($waiver));
        $date = date('Y/m');
        $signatureFolder = "signatures/{$date}";
        $pdfFolder = "waivers/{$date}";
        foreach ([$signatureFolder, $pdfFolder] as $relative) {
            $dir = rtrim($storageRoot, '/') . '/' . $relative;
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException('No se pudo crear el directorio de responsivas.');
            }
        }
        $random = bin2hex(random_bytes(24));
        $signaturePath = $signatureFolder . '/' . $random . '.png';
        $pdfPath = $pdfFolder . '/' . $random . '.pdf';
        $signatureFile = rtrim($storageRoot, '/') . '/' . $signaturePath;
        $pdfFile = rtrim($storageRoot, '/') . '/' . $pdfPath;
        if (file_put_contents($signatureFile, $signature, LOCK_EX) === false) {
            throw new RuntimeException('No se pudo almacenar la firma.');
        }
        $createdFiles[] = $signatureFile;
        @chmod($signatureFile, 0600);
        $signedAt = date('Y-m-d H:i:s');
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($passenger, $signature, $signedAt), 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        if (file_put_contents($pdfFile, $dompdf->output(), LOCK_EX) === false) {
            throw new RuntimeException('No se pudo almacenar la carta firmada.');
        }
        $createdFiles[] = $pdfFile;
        @chmod($pdfFile, 0600);
        $completed = json_encode([
            'full_name' => self::fullName($passenger),
            'phone' => self::phone($passenger),
            'email' => self::email($passenger),
            'identity_type' => 'INE',
            'template_version' => self::VERSION,
            'signature_path' => $signaturePath,
            'accepted' => true,
            'signed_at' => $signedAt,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $hash = hash_file('sha256', $pdfFile);
        if ($hash === false) throw new RuntimeException('No se pudo calcular hash del PDF.');
        $method = 'drawn';
        $email = self::email($passenger);
        $stmt = $db->prepare("INSERT INTO passenger_waivers (ticket_passenger_id, template_id, completed_data, signed_pdf_path, signed_pdf_sha256, signature_method, consent_accepted_at, signed_at, signer_email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'signed')");
        if (!$stmt) throw new RuntimeException('No se pudo preparar el guardado de la responsiva.');
        $stmt->bind_param('iisssssss', $passengerId, $templateId, $completed, $pdfPath, $hash, $method, $signedAt, $signedAt, $email);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('No se pudo registrar la responsiva.');
        }
        $stmt->close();
    }
}