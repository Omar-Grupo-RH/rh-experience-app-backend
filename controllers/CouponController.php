<?php

namespace Controllers;

use Throwable;
use App\Http\JsonResponse;
use Model\Coupon;
use App\Time\Clock;

class CouponController
{

    private static function readBody(): array
    {
        $payload = $_POST;
        $isJson  = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;

        if (empty($payload) && $isJson) {
            $raw = file_get_contents('php://input');
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                JsonResponse::send(['success' => false, 'message' => 'JSON inválido'], 400);
            }
        }

        // Normaliza strings
        foreach ($payload as $k => $v) {
            if (is_string($v)) $payload[$k] = trim($v);
        }
        return $payload;
    }



    /* =======================
       GET /api/clients  (mostrar)
       ======================= */
    public static function index(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:GET');
        header('Allow: GET');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $coupons = Coupon::all();
        JsonResponse::send([
            'success' => true,
            'data' => $coupons
        ], 200);
    }

    public static function show(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $id = $_GET['id'] ?? null; // si usas query param
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }

        $coupon = Coupon::find($id);
        if (!$coupon) {
            JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon encontrado',
            'data' => $coupon,
        ], 200); // ✅ 200 en GET
    }



    public static function store(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();
        $coupon  = new Coupon($payload);
        $today = Clock::today();

        $coupon->code = strtoupper(preg_replace('/\s+/', '_', trim($coupon->code)));
        // Validar que el code sea unico
        try {
            if (Coupon::where('code', $coupon->code)) {
                JsonResponse::send(['success' => false, 'error' => 'El codigo de descuento ya está en uso.'], 422);
            }
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error de base de datos (unicidad codigo)'], 500);
        }

        if ($coupon->discount < 0) {
            JsonResponse::send(['success' => false, 'error' => 'El porcentaje de descuento no puede ser negativo.'], 422);
            exit;
        }
        if ($coupon->usage_limit < 0) {
            JsonResponse::send(['success' => false, 'error' => 'La cantidad de uso no puede ser negativo.'], 422);
            exit;
        }

        if ($coupon->expiry_date < $today) {
            JsonResponse::send(['success' => false, 'error' => 'No puedes elegir una fecha pasada.'], 422);
            exit;
        }


        // Guardar
        try {
            $result = $coupon->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al guardar el cupon'], 500);
        }

        if (empty($result['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo crear el cupon'], 500);
        }
        $coupon->id = $result['id'] ?? null;
        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon creado correctamente',
            'data' => $coupon
        ], 200);
    }


    public static function update(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();
        $id = $payload['id'] ?? null;
        $today = Clock::today();

        $payload['code'] = strtoupper(preg_replace('/\s+/', '_', trim($payload['code'])));
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
        }
        $coupon = Coupon::find($id);
        if (!$coupon) {
            JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
        }

        if (!empty($payload['code']) && $payload['code'] !== $coupon->code) {
            $codeExist = Coupon::where('code', $payload['code']);
            if ($codeExist && (int)$codeExist->id !== (int)$coupon->id) {
                JsonResponse::send(['success' => false, 'error' => 'El codigo ya está en uso.'], 422);
            }
        }

        if ($payload['discount'] < 0) {
            JsonResponse::send(['success' => false, 'error' => 'El porcentaje de descuento no puede ser negativo.'], 422);
            exit;
        }
        if ($payload['usage_limit'] < 0) {
            JsonResponse::send(['success' => false, 'error' => 'La cantidad de uso no puede ser negativo.'], 422);
            exit;
        }

        if ($payload['expiry_date'] < $today) {
            JsonResponse::send(['success' => false, 'error' => 'No puedes elegir una fecha pasada.'], 422);
            exit;
        }
        foreach (['code', 'discount', 'usage_limit', 'expiry_date', 'is_active', 'description'] as $k) {
            if (array_key_exists($k, $payload)) {
                $coupon->$k = trim((string)$payload[$k]);
            }
        }
        try {
            $result = $coupon->guardar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al actualizar el cliente'], 500);
        }
        if (empty($result['resultado'])) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo actualizar el cupon'], 500); // 👈 mensaje corregido
        }
        JsonResponse::send([
            'success' => true,
            'message' => 'Cupon actualizado',
            'data' => $coupon
        ], 200);
    }



    public static function destroy(): void
    {
        header('Access-Control-Allow-Origin: http://localhost:3000'); // tu front
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods:POST');
        header('Allow: POST');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $payload = self::readBody();
        $id = $payload['id'] ?? null;

        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id del cupon'], 400);
        }

        try {
            $coupon = Coupon::find($id);
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al buscar el cupon'], 500);
        }

        if (!$coupon) {
            JsonResponse::send(['success' => false, 'message' => 'Cupon no encontrado'], 404);
        }

        try {
            $res = $coupon->eliminar();
        } catch (Throwable $e) {
            JsonResponse::send(['success' => false, 'message' => 'Error al eliminar el cupon'], 500);
        }

        if (!$res) {
            JsonResponse::send(['success' => false, 'message' => 'No se pudo eliminar el cupon'], 500);
        }

        JsonResponse::send([
            'success' => true,
            'message' => 'cupon eliminado correctamente.',
        ], 200);
    }
}