<?php

namespace App\Http\Controllers;

use App\Enums\ResponseHeader;
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
            ->header(ResponseHeader::Cache->value, $result['cache'])
            ->header(ResponseHeader::QueryTime->value, $result['query_time'])
            ->header(ResponseHeader::Provider->value, config('slots.provider'));
    }
}
