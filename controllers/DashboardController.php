<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\Trip;
use Model\TripPassenger;

class DashboardController
{

    public static function getTravels()
    {
        $travels = Trip::allWithPassengersCount();
        JsonResponse::send([
            'success' => true,
            'data'    => $travels,
        ], 200);
    }

    public static function getPassengersByTravel()
    {


        $id = $_GET['id'] ?? null;
        if (!$id) {
            JsonResponse::send(['success' => false, 'message' => 'Falta id'], 400);
            exit;
        }

        $trip = Trip::find($id);
        if (!$trip) {
            JsonResponse::send(['success' => false, 'message' => 'RH EXPERIENCE no encontrado'], 404);
            exit;
        }


        $res = $passengers = TripPassenger::allPassengersByTrip($id);
        if (!$res) {
            JsonResponse::send(['success' => false, 'message' => 'No se encontraron pasajeros'], 404);
            exit;
        }

        JsonResponse::send([
            'success' => true,
            'data'    => $passengers,
        ], 200);
    }
}
