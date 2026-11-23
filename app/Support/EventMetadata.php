<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EventMetadata
{
    public static function validated(Request $request, Community $community): array
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Community::EVENTS))],
            'organizer' => 'required|string|max:150', 'venue' => 'required|string|max:180',
            'event_url' => 'nullable|url:https|max:1024',
            'starts_at' => 'required|date_format:Y-m-d\\TH:i', 'ends_at' => 'nullable|date_format:Y-m-d\\TH:i',
        ]);
        $start = $community->localEventTime($data['starts_at'], 'starts_at');
        $end = isset($data['ends_at']) ? $community->localEventTime($data['ends_at'], 'ends_at') : null;
        if ($start->lte(now()) || $start->gt(now()->addYear())) {
            throw ValidationException::withMessages(['starts_at' => 'Choose a future start within one year.']);
        }
        if ($end !== null && ($end->lte($start) || $end->gt($start->addDays(7)))) {
            throw ValidationException::withMessages(['ends_at' => 'Choose an end after the start and within seven days.']);
        }
        $data['starts_at'] = $start->format('Y-m-d H:i:s');
        $data['ends_at'] = $end?->format('Y-m-d H:i:s');
        $data['event_url'] ??= null;

        return $data;
    }
}
