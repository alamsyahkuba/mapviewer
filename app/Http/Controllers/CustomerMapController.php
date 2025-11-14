<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CustomerMapController extends Controller
{
    public function index()
    {
        return view('customer-map');
    }
}
