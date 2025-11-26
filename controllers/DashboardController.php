<?php

namespace Controllers;

use App\Http\JsonResponse;
use Model\Order;
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

    public static function earnings()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            JsonResponse::send(['success' => false, 'message' => 'Method Not Allowed'], 405);
            exit;
        }

        $date  = $_GET['date']  ?? null;    // '2025-11-19' o null
        $order = $_GET['order'] ?? 'DESC';  // ASC | DESC
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $dataset = Order::earningsWithSummary($date, $order);

        $rowsObj    = $dataset['rows']    ?? [];
        $summaryRaw = $dataset['summary'] ?? [];

        // mapeamos objetos a arrays planos
        $rows = array_map(function ($o) {
            return [
                'id'             => (int) $o->id,
                'buyer_name'     => $o->buyer_name,
                'subtotal_cents' => (int) $o->subtotal_cents / 100,
                'discount_cents' => (int) $o->discount_cents / 100,
                'total_cents'    => (int) $o->total_cents / 100,
                'status'         => $o->status,
                'created_at'     => $o->created_at,
                'trip_date'      => $o->trip_date,
                'trip_title'     => $o->trip_title,
            ];
        }, $rowsObj);

        $summary = [
            'gross_cents'            => isset($summaryRaw['gross_cents']) ? (int) $summaryRaw['gross_cents'] / 100 : 0,
            'net_cents'              => isset($summaryRaw['net_cents']) ? (int) $summaryRaw['net_cents'] / 100 : 0,
            'discount_cents'         => isset($summaryRaw['discount_cents']) ? (int) $summaryRaw['discount_cents'] / 100 : 0,
            'total_orders'           => isset($summaryRaw['total_orders']) ? (int) $summaryRaw['total_orders'] : 0,
            'completed_transactions' => isset($summaryRaw['completed_transactions']) ? (int) $summaryRaw['completed_transactions'] : 0,
        ];

        JsonResponse::send([
            'success' => true,
            'data'    => [
                'rows'    => $rows,
                'summary' => $summary,
            ],
        ], 200);
    }
}
