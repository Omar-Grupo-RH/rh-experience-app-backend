<?php

namespace Controllers;

use App\Http\JsonResponse;

use Model\Trip;
use Model\TripPassenger;

class DashboardController
{

    public static function getTravels()
    {
        $travels = Trip::allWithPassengersCount('ASC');
        JsonResponse::send([
            'success' => true,
            'data'    => $travels,
        ], 200);
    }

    public static function getPassengersByTravel()
    {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            JsonResponse::send([
                'success' => false,
                'message' => 'Falta id'
            ], 400);
            return;
        }

        $trip = Trip::find((int) $id);

        if (!$trip) {
            JsonResponse::send([
                'success' => false,
                'message' => 'RH EXPERIENCE no encontrado'
            ], 404);
            return;
        }

        $passengers = TripPassenger::allPassengersWithRegistrationByTrip((int) $id);

        JsonResponse::send([
            'success' => true,
            'data' => $passengers,
        ], 200);

        return;
    }

    
}