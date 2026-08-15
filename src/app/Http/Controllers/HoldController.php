<?php

namespace App\Http\Controllers;

use App\Enums\ResponseHeader;
use App\Exceptions\CapacityExhaustedException;
use App\Exceptions\HoldConflictException;
use App\Exceptions\HoldExpiredException;
use App\Exceptions\HoldNotFoundException;
use App\Exceptions\SlotNotFoundException;
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
        try {
            $data = $this->slotService->createHold($slotId, $request->header('Idempotency-Key'));

            return response()->json($data, 201)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (SlotNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (CapacityExhaustedException $e) {
            return response()->json(['message' => $e->getMessage()], 409)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        }
    }

    public function confirm(int $holdId): JsonResponse
    {
        try {
            $data = $this->slotService->confirmHold($holdId);

            return response()->json($data, 200)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (HoldNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (HoldConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (HoldExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 410)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        }
    }

    public function destroy(int $holdId): JsonResponse
    {
        try {
            $data = $this->slotService->cancelHold($holdId);

            return response()->json($data, 200)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (HoldNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        } catch (HoldConflictException $e) {
            return response()->json(['message' => $e->getMessage()], 409)
                ->header(ResponseHeader::Provider->value, config('slots.provider'));
        }
    }
}
