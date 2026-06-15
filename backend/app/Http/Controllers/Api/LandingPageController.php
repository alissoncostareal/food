<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingLead;
use App\Services\LandingPageService;
use Illuminate\Http\Request;
use Throwable;

class LandingPageController extends Controller
{
    public function show()
    {
        try {
            $content = LandingPageService::content();

            if (! ($content['published'] ?? true)) {
                return response()->json([
                    'published' => false,
                    'message' => 'Landing page indisponível no momento.',
                ], 503);
            }

            return response()->json([
                'published' => true,
                'content' => $content,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao carregar landing page',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function storeLead(Request $request)
    {
        try {
            $content = LandingPageService::content();

            if (! ($content['lead_form']['enabled'] ?? true)) {
                return response()->json([
                    'message' => 'Formulário indisponível no momento.',
                ], 422);
            }

            $validated = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:30'],
                'store_name' => ['nullable', 'string', 'max:120'],
                'message' => ['nullable', 'string', 'max:1000'],
            ]);

            LandingLead::query()->create($validated);

            return response()->json([
                'message' => $content['lead_form']['success_message']
                    ?? 'Recebemos seu interesse! Em breve entraremos em contato.',
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'error' => 'Erro ao registrar interesse',
                'details' => $e->getMessage(),
            ], 400);
        }
    }
}
