<?php

namespace App\Services\Payments;

use RuntimeException;

/** ispwatch no contestó o contestó un error pasajero (timeout, 429, 5xx): se reintenta. */
final class IspwatchUnavailable extends RuntimeException {}
