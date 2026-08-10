<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ManualController extends Controller
{
    /** Manual dentro de la app (requiere sesión). */
    public function index()
    {
        return Inertia::render('Manual/Index');
    }

    /**
     * Manual PÚBLICO (/ayuda): cualquiera con el link lo abre, sin login.
     *
     * Comparte el contenido con index() —el mismo componente Vue—, así que no
     * hay dos textos que mantener. Es la versión que se le puede pasar a un ISP
     * que todavía no tiene cuenta.
     */
    public function publicIndex()
    {
        return Inertia::render('Manual/Public');
    }
}
