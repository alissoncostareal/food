<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use Illuminate\Http\Request;

class UserPreferenceController extends Controller
{
    public function show(Request $request)
    {
        $preferences = $this->preferencesFor($request->user());

        return response()->json([
            'preferences' => [
                'new_order_sound_enabled' => $preferences->new_order_sound_enabled,
                'new_order_sound_unlocked' => $preferences->new_order_sound_unlocked,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'new_order_sound_enabled' => ['sometimes', 'boolean'],
            'new_order_sound_unlocked' => ['sometimes', 'boolean'],
        ]);

        $preferences = $this->preferencesFor($request->user());
        $preferences->update($validated);

        return response()->json([
            'message' => 'Preferências atualizadas.',
            'preferences' => [
                'new_order_sound_enabled' => $preferences->new_order_sound_enabled,
                'new_order_sound_unlocked' => $preferences->new_order_sound_unlocked,
            ],
        ]);
    }

    private function preferencesFor($user): UserPreference
    {
        return UserPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'new_order_sound_enabled' => true,
                'new_order_sound_unlocked' => false,
            ]
        );
    }
}
