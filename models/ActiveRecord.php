<?php

namespace Model;

class ActiveRecord
{

    // Base DE DATOS
    protected static $db;
    protected static $tabla = '';
    protected static $columnasDB = [];

    // Alertas y Mensajes
    protected static $alertas = [];

    // Definir la conexión a la BD - includes/database.php
    public static function setDB($database)
    {
        self::$db = $database;
    }

    public static function getDB()
    {
        return self::$db;
    }

    // Setear un tipo de Alerta
    public static function setAlerta($tipo, $mensaje)
    {
        static::$alertas[$tipo][] = $mensaje;
    }

    // Obtener las alertas
    public static function getAlertas()
    {
        return static::$alertas;
    }

    // Validación que se hereda en modelos
    public function validar()
    {
        static::$alertas = [];
        return static::$alertas;
    }

    // Consulta SQL para crear un objeto en Memoria (Active Record)
    public static function consultarSQL($query)
    {
        // Consultar la base de datos
        $resultado = self::$db->query($query);

        // Iterar los resultados
        $array = [];
        while ($registro = $resultado->fetch_assoc()) {
            $array[] = static::crearObjeto($registro);
        }

        // liberar la memoria
        $resultado->free();

        // retornar los resultados
        return $array;
    }

    // Crea el objeto en memoria que es igual al de la BD
    protected static function crearObjeto($registro)
    {
        $objeto = new static;

        foreach ($registro as $key => $value) {
            if (property_exists($objeto, $key)) {
                $objeto->$key = $value;
            }
        }
        return $objeto;
    }

    // Identificar y unir los atributos de la BD
    public function atributos()
    {
        $atributos = [];
        foreach (static::$columnasDB as $columna) {
            if ($columna === 'id') continue;
            $atributos[$columna] = $this->$columna;
        }
        return $atributos;
    }

    // Sanitizar los datos antes de guardarlos en la BD
    public function sanitizarAtributos()
    {
        $atributos = $this->atributos();
        $san = [];
        foreach ($atributos as $k => $v) {
            if (is_null($v)) {
                $san[$k] = null;
            } elseif (is_bool($v)) {
                $san[$k] = $v ? 1 : 0;
            } elseif (is_int($v) || is_float($v)) {
                $san[$k] = $v;
            } else { // string
                $san[$k] = self::$db->escape_string($v);
            }
        }
        return $san;
    }


    // Sincroniza BD con Objetos en memoria
    public function sincronizar($args = [])
    {
        foreach ($args as $key => $value) {
            if (property_exists($this, $key) && !is_null($value)) {
                $this->$key = is_string($value) ? trim($value) : $value;
            }
        }
    }


    // Registros - CRUD
    public function guardar()
    {
        $resultado = '';
        if (!is_null($this->id)) {
            // actualizar
            $resultado = $this->actualizar();
        } else {
            // Creando un nuevo registro
            $resultado = $this->crear();
        }
        return $resultado;
    }

    public static function allWithPassengersCount($orden = 'DESC')
    {
        $orden = strtoupper($orden) === 'ASC' ? 'ASC' : 'DESC';

        $query = "SELECT
        t.id,
        t.trip_date,
        t.title,
        t.total_seats,
        COUNT(tp.id) AS unavaible_seats,
        COUNT(tp.id) AS passengers_count,
        GREATEST(COALESCE(t.total_seats, 15) - COUNT(tp.id), 0) AS avaible_seats
    FROM " . static::$tabla . " t
    LEFT JOIN tickets tk 
        ON t.id = tk.trip_id
    LEFT JOIN ticket_passengers tp 
        ON tk.id = tp.ticket_id
    WHERE t.is_active = 1
    GROUP BY 
        t.id, 
        t.trip_date, 
        t.title, 
        t.total_seats
    ORDER BY t.trip_date {$orden}";

        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    public static function allPassengersByTrip($id, $orden = 'DESC')
    {
        $query = "
        SELECT 
            tp.id as passenger_id,
            tp.first_name,
            tp.last_name,
            tp.email,
            tp.phone,
            tp.born_date,
            tp.seat_id,
            tp.allergies,
            tp.created_at,
            tk.id as ticket_id,
            tk.seat_numbers,
            t.trip_date,
            t.title
        FROM " . static::$tabla . " t
        INNER JOIN tickets tk ON t.id = tk.trip_id
        INNER JOIN ticket_passengers tp ON tk.id = tp.ticket_id
        WHERE t.id = {$id}  
        ORDER BY t.trip_date {$orden};
        ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    // Obtener todos los Registros
    public static function all($orden = 'DESC', $columna = 'id')
    {
        $orden = strtoupper($orden);

        if (!in_array($orden, ['ASC', 'DESC'])) {
            $orden = 'DESC';
        }

        if (!in_array($columna, static::$columnasDB)) {
            $columna = 'id';
        }

        $query = "SELECT * FROM " . static::$tabla . " ORDER BY {$columna} {$orden}";
        $resultado = self::consultarSQL($query);

        return $resultado;
    }

    // Busca un registro por su id
    public static function find($id)
    {
        $query = "SELECT * FROM " . static::$tabla  . " WHERE id = {$id}";
        $resultado = self::consultarSQL($query);
        return array_shift($resultado);
    }

    // Obtener Registros con cierta cantidad
    public static function get($limite)
    {
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY id DESC LIMIT {$limite} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    //paginar los registros
    public static function paginar($por_pagina, $offset)
    {
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY id DESC LIMIT {$por_pagina} OFFSET {$offset} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }
    //paginar los registros con un where
    public static function paginarWhereAll($columna, $valor, $por_pagina, $offset)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = '{$valor}' ORDER BY id DESC LIMIT {$por_pagina} OFFSET {$offset} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    // Busqueda Where con Columna 
    public static function whereAll($columna, $valor)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = '{$valor}'";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    //Obtiene el numero total de registros
    public static function total($columna = '', $valor = '')
    {
        $query = "SELECT COUNT(*) FROM " . static::$tabla;
        if ($columna) {
            $query .= " WHERE {$columna} = {$valor}";
        }
        $resultado = self::$db->query($query);
        $total = $resultado->fetch_array();
        return array_shift($total);
    }
    //Obtiene el numero total de registros con un Array Where
    public static function totalArray($array = [])
    {
        $query = "SELECT COUNT(*) FROM " . static::$tabla . " WHERE ";
        foreach ($array as $key => $value) {
            if ($key == array_key_last($array)) {
                $query .= " {$key} = '{$value}'";
            } else {
                $query .= " {$key} = '{$value}' AND ";
            }
        }
        $resultado = self::$db->query($query);
        $total = $resultado->fetch_array();
        return array_shift($total);
    }


    // Busqueda Where con Columna 
    public static function where($columna, $valor)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = '{$valor}'";
        $resultado = self::consultarSQL($query);
        return array_shift($resultado);
    }


    public static function earningsWithSummary(?string $date = null, string $orden = 'DESC')
    {
        $orden = strtoupper($orden) === 'ASC' ? 'ASC' : 'DESC';

        // mismo where para filas y resumen
        $where = '1=1';

        if ($date) {
            $dateEscaped = self::$db->escape_string($date);
            $where .= " AND o.created_at = '{$dateEscaped}'";
        }

        // FILAS (para la tabla)
        $queryRows = "
            SELECT
                o.id,
                b.full_name      AS buyer_name,
                b.email,
                b.phone,
                b.address,
                b.city,
                b.state,
                b.zip,
                o.subtotal_cents,
                o.discount_cents,
                o.total_cents,
                o.status,
                o.created_at,
                tr.trip_date,
                tr.title         AS trip_title
            FROM " . static::$tabla . " o
            INNER JOIN buyers  b  ON o.buyer_id  = b.id
            INNER JOIN tickets tk ON o.ticket_id = tk.id
            INNER JOIN trips  tr  ON tk.trip_id  = tr.id
            WHERE {$where}
            ORDER BY o.created_at {$orden}
        ";

        $rows = self::consultarSQL($queryRows);

        // RESUMEN (para los CardInfo)
        $querySummary = "
            SELECT
                COALESCE(SUM(o.subtotal_cents),0)          AS gross_cents,
                COALESCE(SUM(o.total_cents),0)             AS net_cents,
                COALESCE(SUM(o.discount_cents),0)          AS discount_cents,
                COUNT(*)                                   AS total_orders,
                SUM(CASE WHEN o.status = 'pagado' THEN 1 ELSE 0 END) AS completed_transactions
            FROM orders o
            WHERE {$where}
        ";

        $res = self::$db->query($querySummary);
        $summary = $res->fetch_assoc();

        return [
            'rows'    => $rows,
            'summary' => $summary,
        ];
    }


    // Busqueda Where con Columna en 2 tablas
    public static function whereUnion($tablaUnion, $columna, $valor)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = '{$valor}' UNION SELECT * FROM {$tablaUnion} WHERE {$columna} = '{$valor}'";
        $resultado = self::consultarSQL($query);
        return array_shift($resultado);
    }

    //Retornar los registros por un orden
    public static function ordenar($columna, $orden)
    {
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY {$columna}  {$orden} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }
    //Retornar por orden con un limite
    public static function ordenarLimite($columna, $orden, $limite)
    {
        $query = "SELECT * FROM " . static::$tabla . " ORDER BY {$columna}  {$orden} LIMIT {$limite} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }
    //Retornar por orden con un limite
    public static function ordenarLimiteWhere($columna, $valor, $orden, $limite)
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE {$columna} = '{$valor}' ORDER BY {$columna}  {$orden} LIMIT {$limite} ";
        $resultado = self::consultarSQL($query);
        return $resultado;
    }

    // Busqueda Where con multiples opciones
    public static function whereArray($array = [])
    {
        $query = "SELECT * FROM " . static::$tabla . " WHERE ";
        foreach ($array as $key => $value) {
            if ($key == array_key_last($array)) {
                $query .= " {$key} = '{$value}'";
            } else {
                $query .= " {$key} = '{$value}' AND ";
            }
        }
        $resultado = self::consultarSQL($query);
        return $resultado;
    }


    // crea un nuevo registro
    public function crear()
    {
        $attrs = $this->sanitizarAtributos();
        $cols  = array_keys($attrs);

        $vals = array_map(function ($v) {
            if (is_null($v)) return 'NULL';
            if (is_int($v) || is_float($v)) return (string)$v;
            return "'" . $v . "'"; // ya viene escapado en sanitizarAtributos()
        }, array_values($attrs));

        $query = "INSERT INTO `" . static::$tabla . "` (" . join(', ', array_map(fn($c) => "`$c`", $cols)) . ")
              VALUES (" . join(', ', $vals) . ")";

        $resultado = self::$db->query($query);
        return ['resultado' => $resultado, 'id' => self::$db->insert_id];
    }



    // Actualizar el registro
    public function actualizar()
    {
        // Sanitizar los datos
        $atributos = $this->sanitizarAtributos();

        // Iterar para ir agregando cada campo de la BD
        $valores = [];
        foreach ($atributos as $key => $value) {
            if (is_null($value)) {
                // NULL sin comillas
                $valores[] = "{$key}=NULL";
            } elseif (is_int($value) || is_float($value)) {
                // números sin comillas
                $valores[] = "{$key}={$value}";
            } else {
                // strings con comillas (ya escapados en sanitizarAtributos)
                $valores[] = "{$key}='{$value}'";
            }
        }

        // Consulta SQL
        $query = "UPDATE " . static::$tabla . " SET ";
        $query .=  join(', ', $valores);
        $query .= " WHERE id = '" . self::$db->escape_string($this->id) . "' ";
        $query .= " LIMIT 1 ";

        // Actualizar BD
        $resultado = self::$db->query($query);
        return ['resultado' => $resultado, 'id' => $this->id];
    }


    // Eliminar un Registro por su ID
    public function eliminar()
    {
        $query = "DELETE FROM "  . static::$tabla . " WHERE id = " . self::$db->escape_string($this->id) . " LIMIT 1";
        $resultado = self::$db->query($query);
        return $resultado;
    }
}
