<?php

namespace App\Http\Controllers;

use App\Http\Concerns\NormalizesRequestPayload;
use App\Http\Concerns\ResolvesShopAtelierId;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, NormalizesRequestPayload, ResolvesShopAtelierId, ValidatesRequests;
}
