<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Driver;

class DriverController extends Controller
{
    public function index()
    {
        return view('driver-map');
    }
    /**
     * Return marker data for driver
     */
    public function show($driverId): JsonResponse
    {
        try {
            $latest = DB::table('list driver position')
            ->where('Driver Id', $driverId)
            ->orderByDesc('created_at')
            ->first();
            
            if (!$latest) {
                return response()->json(['error' => 'Driver not found'], 404);
            }
            
            $origin = json_decode($latest->Origin, true);
            $driver = json_decode($latest->CurrentPosition, true);
            $destinations = (array) json_decode($latest->Destination, true);
            $stops = (array) json_decode($latest->Stop, true);
            
            if (!$origin || !$driver) {
                return response()->json(['error' => 'Invalid data format'], 422);
            }
            
            if (!is_array($destinations)) {
                $destinations = [];
            }
            return response()->json([
                'start_point' => [
                    'lat' => (float) ($origin['lat'] ?? 0),
                    'lng' => (float) ($origin['lng'] ?? 0),
                ],
                'destination' => collect($destinations)->map(function ($d) {
                    return [
                        'lat' => (float) ($d['lat'] ?? 0),
                        'lng' => (float) ($d['lng'] ?? 0),
                        'status' => $d['status'],
                    ];
                }),
                'driver_position' => [
                    'lat' => (float) ($driver['lat'] ?? 0),
                    'lng' => (float) ($driver['lng'] ?? 0),
                ],
                'stop_point' => collect($stops)->map(function ($s) {
                    return [
                        'lat' => (float) ($s['lat'] ?? 0),
                        'lng' => (float) ($s['lng'] ?? 0),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
        
    }
}
