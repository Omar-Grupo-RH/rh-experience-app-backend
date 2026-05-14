<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\Code;

class CodesController
{
    public static function index()
    {
        try {
            $type = $_GET['type'] ?? null;

            if ($type) {
                self::validateType($type);
                $codes = Code::allByType($type);
            } else {
                $codes = Code::all();
            }

            JsonResponse::send([
                'success' => true,
                'data' => $codes,
            ], 200);

            return;
        } catch (\InvalidArgumentException $e) {
            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

            return;
        } catch (\Throwable $e) {
            error_log('Error listando códigos: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al obtener los códigos.',
            ], 500);

            return;
        }
    }

    public static function show()
    {
        try {
            $id = $_GET['id'] ?? null;

            if (!$id) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El ID es obligatorio.',
                ], 422);
                return;
            }

            $code = Code::find((int) $id);

            if (!$code) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Código no encontrado.',
                ], 404);
                return;
            }

            JsonResponse::send([
                'success' => true,
                'data' => $code,
            ], 200);

            return;
        } catch (\Throwable $e) {
            error_log('Error obteniendo código: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al obtener el código.',
            ], 500);

            return;
        }
    }

    public static function store()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido.',
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();

            $code = new Code([
                'type' => $payload['type'] ?? '',
                'name' => $payload['name'] ?? '',
                'code' => $payload['code'] ?? '',
            ]);

            $errores = $code->validar();

            if (!empty($errores)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Hay errores de validación.',
                    'errors' => $errores,
                ], 422);

                return;
            }

            $result = $code->guardar();

            if (empty($result['resultado'])) {
                throw new \Exception('No se pudo guardar el código.');
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Código creado correctamente.',
                'id' => $result['id'] ?? null,
            ], 201);

            return;
        } catch (\mysqli_sql_exception $e) {
            error_log('MySQL error creando código: ' . $e->getMessage());

            if ((int) $e->getCode() === 1062) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Ya existe un código igual para este tipo.',
                ], 409);

                return;
            }

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al crear el código.',
            ], 500);

            return;
        } catch (\Throwable $e) {
            error_log('Error creando código: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al crear el código.',
            ], 500);

            return;
        }
    }

    public static function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' ) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido.',
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();
            $id = $payload['id'] ?? ($_GET['id'] ?? null);

            if (!$id) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El ID es obligatorio.',
                ], 422);
                return;
            }

            $code = Code::find((int) $id);

            if (!$code) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Código no encontrado.',
                ], 404);
                return;
            }

            $code->sincronizar([
                'type' => $payload['type'] ?? $code->type,
                'name' => $payload['name'] ?? $code->name,
                'code' => $payload['code'] ?? $code->code,
            ]);

            $code->code = strtoupper(trim($code->code));

            $errores = $code->validar();

            if (!empty($errores)) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Hay errores de validación.',
                    'errors' => $errores,
                ], 422);

                return;
            }

            $result = $code->guardar();

            if (empty($result['resultado'])) {
                throw new \Exception('No se pudo actualizar el código.');
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Código actualizado correctamente.',
            ], 200);

            return;
        } catch (\mysqli_sql_exception $e) {
            error_log('MySQL error actualizando código: ' . $e->getMessage());

            if ((int) $e->getCode() === 1062) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Ya existe un código igual para este tipo.',
                ], 409);

                return;
            }

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al actualizar el código.',
            ], 500);

            return;
        } catch (\Throwable $e) {
            error_log('Error actualizando código: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al actualizar el código.',
            ], 500);

            return;
        }
    }

    public static function delete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            JsonResponse::send([
                'success' => false,
                'message' => 'Método no permitido.',
            ], 405);
            return;
        }

        try {
            $payload = self::readBody();
            $id = $payload['id'] ?? ($_GET['id'] ?? null);

            if (!$id) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El ID es obligatorio.',
                ], 422);
                return;
            }

            $code = Code::find((int) $id);

            if (!$code) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Código no encontrado.',
                ], 404);
                return;
            }

            $result = $code->eliminar();

            if (empty($result)) {
                throw new \Exception('No se pudo eliminar el código.');
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Código eliminado correctamente.',
            ], 200);

            return;
        } catch (\Throwable $e) {
            error_log('Error eliminando código: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al eliminar el código.',
            ], 500);

            return;
        }
    }

    public static function validate()
    {
        try {
            $type = $_GET['type'] ?? null;
            $codeValue = $_GET['code'] ?? null;

            if (!$type || !$codeValue) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'El tipo y el código son obligatorios.',
                ], 422);

                return;
            }

            self::validateType($type);

            $code = Code::findByTypeAndCode($type, $codeValue);

            if (!$code) {
                JsonResponse::send([
                    'success' => false,
                    'message' => 'Código no válido.',
                    'valid' => false,
                ], 404);

                return;
            }

            JsonResponse::send([
                'success' => true,
                'message' => 'Código válido.',
                'valid' => true,
                'data' => $code,
            ], 200);

            return;
        } catch (\InvalidArgumentException $e) {
            JsonResponse::send([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

            return;
        } catch (\Throwable $e) {
            error_log('Error validando código: ' . $e->getMessage());

            JsonResponse::send([
                'success' => false,
                'message' => 'Ocurrió un error al validar el código.',
            ], 500);

            return;
        }
    }

    private static function readBody(): array
    {
        $payload = $_POST;

        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('El cuerpo de la solicitud no es un JSON válido.');
            }

            $payload = $decoded;
        }

        return $payload;
    }

    private static function validateType(string $type): void
    {
        $allowedTypes = ['department', 'alliance', 'discount'];

        if (!in_array($type, $allowedTypes, true)) {
            throw new \InvalidArgumentException('El tipo de código no es válido.');
        }
    }
}