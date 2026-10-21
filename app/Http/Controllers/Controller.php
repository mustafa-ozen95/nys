<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Yetkilendirme Policy'lerde yapılır; controller'lar $this->authorize()
     * ve authorizeResource() ile onlara delege eder.
     */
    use AuthorizesRequests;
}
