<?php

namespace App\Http\Controllers;

use App\Models\SpecialEventCalendar;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SpecialEventCalendarController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'country_code' => ['nullable', 'string', 'size:2'],
            'event_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $calendars = SpecialEventCalendar::query()
            ->when(
                isset($validated['country_code']),
                fn ($query) => $query->forCountry($validated['country_code'])
            )
            ->when(
                isset($validated['event_code']),
                fn ($query) => $query->where('event_code', $validated['event_code'])
            )
            ->when(
                array_key_exists('is_active', $validated),
                fn ($query) => $query->where('is_active', $validated['is_active'])
            )
            ->when(
                isset($validated['year']),
                fn ($query) => $query->whereYear('starts_at', $validated['year'])
            )
            ->orderBy('starts_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'success' => true,
            'data' => $calendars,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $this->validatedData($request);
        $calendar = SpecialEventCalendar::create(
            $this->prepareForStorage($validated)
        );

        return response()->json([
            'success' => true,
            'message' => 'Special-event calendar created successfully.',
            'data' => $calendar,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(SpecialEventCalendar $specialEventCalendar)
    {
        return response()->json([
            'success' => true,
            'data' => $specialEventCalendar,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        SpecialEventCalendar $specialEventCalendar
    ) {
        $validated = $this->validatedData(
            $request,
            $specialEventCalendar,
            true
        );
        $specialEventCalendar->update(
            $this->prepareForStorage(
                $validated,
                $specialEventCalendar
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Special-event calendar updated successfully.',
            'data' => $specialEventCalendar->refresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SpecialEventCalendar $specialEventCalendar)
    {
        $specialEventCalendar->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Special-event calendar deactivated successfully.',
        ]);
    }

    private function validatedData(
        Request $request,
        ?SpecialEventCalendar $calendar = null,
        bool $partial = false
    ): array {
        $required = $partial ? 'sometimes' : 'required';
        $countryCode = strtoupper((string) $request->input(
            'country_code',
            $calendar?->country_code
        ));
        $eventCode = (string) $request->input(
            'event_code',
            $calendar?->event_code
        );

        return $request->validate([
            'country_code' => [$required, 'string', 'size:2'],
            'event_code' => [
                $required,
                'string',
                'max:255',
                Rule::exists('achievements', 'code')
                    ->where('category', 'special_event')
                    ->where('is_active', true),
            ],
            'event_key' => [
                $required,
                'string',
                'max:255',
                Rule::unique('special_event_calendars', 'event_key')
                    ->where(fn ($query) => $query
                        ->where('country_code', $countryCode)
                        ->where('event_code', $eventCode))
                    ->ignore($calendar?->id),
            ],
            'timezone' => [$required, 'timezone'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date'],
            'minimum_exam_practices_started' => [
                $required,
                'integer',
                'min:1',
            ],
            'metadata' => ['nullable', 'array'],
            'is_active' => [$partial ? 'sometimes' : 'nullable', 'boolean'],
        ]);
    }

    private function prepareForStorage(
        array $data,
        ?SpecialEventCalendar $calendar = null
    ): array {
        $timezone = $data['timezone'] ?? $calendar?->timezone;
        $startsAt = isset($data['starts_at'])
            ? Carbon::parse($data['starts_at'], $timezone)->utc()
            : $calendar?->starts_at;
        $endsAt = isset($data['ends_at'])
            ? Carbon::parse($data['ends_at'], $timezone)->utc()
            : $calendar?->ends_at;

        abort_if(
            $startsAt && $endsAt && $endsAt->lt($startsAt),
            422,
            'The event end date must be after or equal to its start date.'
        );

        return array_merge($data, [
            'country_code' => isset($data['country_code'])
                ? strtoupper($data['country_code'])
                : $calendar?->country_code,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_active' => $data['is_active'] ?? $calendar?->is_active ?? true,
        ]);
    }
}
