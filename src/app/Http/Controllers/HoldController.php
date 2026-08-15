<?php

namespace App\Http\Controllers;

use App\Http\Requests\HoldCreateRequest;
use App\Services\SlotServiceInterface;
use Illuminate\Http\JsonResponse;

class HoldController extends Controller
{
    public function __construct(
        private readonly SlotServiceInterface $slotService,
    ) {}

    public function store(HoldCreateRequest $request, int $slotId): JsonResponse
    {
        $result = $this->slotService->createHold($slotId, $request->header('Idempotency-Key'));

        return response()->json($result['data'], $result['status'])
            ->header('X-Provider', config('slots.provider'));
    }
}
