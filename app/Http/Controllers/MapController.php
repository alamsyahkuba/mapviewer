<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Location;

class MapController extends Controller
{
    public function index()
    {
        return view('map');
    }

    public function addLocation(Request $request)
    {
        $loc = new Location();
        $loc->latitude = $request->latitude;
        $loc->longitude = $request->longitude;
        $loc->title = $request->title ?? 'Custom Pin';
        $loc->icon_url = $request->icon_url ?? null;
        $loc->save();

        return response()->json(['success' => true]);
    }

    public function getLocations()
    {
        return response()->json(Location::select('id', 'latitude', 'longitude', 'title', 'icon_url')->get());
    }

}
