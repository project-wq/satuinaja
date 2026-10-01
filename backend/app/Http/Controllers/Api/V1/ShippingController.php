<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\RajaOngkirService;
use App\Services\ResiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingController extends Controller
{
    public function __construct(
        private RajaOngkirService $ongkir,
        private ResiService $resi,
    ) {
    }

    /**
     * Cek ongkir: butuh city_id asal (dari toko) & tujuan.
     */
    public function cost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin' => ['required', 'string', 'max:16'],
            'destination' => ['required', 'string', 'max:16'],
            'weight' => ['required', 'integer', 'min:1', 'max:150000'],
            'courier' => ['required', 'string', 'in:jne,pos,tiki,sicepat,jnt,anteraja,ninja,ide,rex,star,sentral,lion,wahana'],
        ]);

        $result = $this->ongkir->costs(
            $data['origin'],
            $data['destination'],
            $data['weight'],
            $data['courier'],
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function cities(): JsonResponse
    {
        $result = $this->ongkir->cities();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Lacak resi. Akses: merchant login atau publik toko.
     */
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'courier' => ['required', 'string', 'max:32'],
            'awb' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
        ]);

        $result = $this->resi->track($data['courier'], $data['awb']);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }
}
