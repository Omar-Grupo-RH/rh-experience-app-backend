-- Capacidad de la unidad por RH Experience: 8 o 15 pasajeros.
-- La columna trips.total_seats ya existe; solo se normalizan nulos y se fija el default en 15
-- para que los registros antiguos y los creados sin capacidad sigan funcionando igual.

UPDATE trips SET total_seats = 15 WHERE total_seats IS NULL OR total_seats <= 0;

ALTER TABLE trips
    MODIFY total_seats INT NOT NULL DEFAULT 15;
