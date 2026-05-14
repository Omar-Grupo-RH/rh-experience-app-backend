<?php

namespace Model;

class Code extends ActiveRecord
{
    protected static $tabla = 'codes';

    protected static $columnasDB = [
        'id',
        'type',
        'name',
        'code'
    ];

    public ?int $id;
    public string $type;
    public string $name;
    public string $code;

    public function __construct($args = [])
    {
        $this->id = $args['id'] ?? null;
        $this->type = trim($args['type'] ?? '');
        $this->name = trim($args['name'] ?? '');
        $this->code = strtoupper(trim($args['code'] ?? ''));
    }

    public function validar(): array
    {
        $errores = [];

        $allowedTypes = ['department', 'alliance', 'discount'];

        if (!$this->type || !in_array($this->type, $allowedTypes, true)) {
            $errores[] = 'El tipo de código no es válido.';
        }

        if (!$this->name) {
            $errores[] = 'El nombre es obligatorio.';
        }

        if (strlen($this->name) > 120) {
            $errores[] = 'El nombre no puede tener más de 120 caracteres.';
        }

        if (!$this->code) {
            $errores[] = 'El código es obligatorio.';
        }

        if (strlen($this->code) > 80) {
            $errores[] = 'El código no puede tener más de 80 caracteres.';
        }

        if ($this->code && !preg_match('/^[A-Z0-9\-_]+$/', $this->code)) {
            $errores[] = 'El código solo puede contener letras, números, guiones y guion bajo.';
        }

        return $errores;
    }

    public static function allByType(string $type): array
    {
        $db = self::getDB();

        $query = "SELECT * FROM " . static::$tabla . " WHERE type = ? ORDER BY id DESC";

        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $type);
        $stmt->execute();

        $result = $stmt->get_result();

        $codes = [];

        while ($row = $result->fetch_assoc()) {
            $codes[] = static::crearObjeto($row);
        }

        return $codes;
    }

    public static function findByTypeAndCode(string $type, string $code): ?self
    {
        $db = self::getDB();

        $code = strtoupper(trim($code));

        $query = "SELECT * FROM " . static::$tabla . " WHERE type = ? AND code = ? LIMIT 1";

        $stmt = $db->prepare($query);
        $stmt->bind_param('ss', $type, $code);
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row ? static::crearObjeto($row) : null;
    }
}