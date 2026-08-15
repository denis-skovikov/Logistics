<?php

namespace App\Http\Controllers;

use App\Http\Requests\SlotIndexRequest;
use App\Services\SlotServiceInterface;
use Illuminate\Http\JsonResponse;

class SlotController extends Controller
{
    public function __construct(
        private readonly SlotServiceInterface $slotService,
    ) {}

    public function index(SlotIndexRequest $request): JsonResponse
    {
        $result = $this->slotService->getSlots();

        $data = $result['data'];

        if ($request->has('limit')) {
            $data = array_slice($data, 0, (int) $request->query('limit'));
        }

        return response()->json($data)
            ->header('X-Cache', $result['cache'])
            ->header('X-Query-Time', $result['query_time'])
            ->header('X-Provider', config('slots.provider'));
    }
}
