<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ManualController extends Controller
{
    public function index()
    {
        return Inertia::render('Manual/Index');
    }
}
