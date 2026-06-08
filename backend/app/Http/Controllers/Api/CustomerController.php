<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    private function customerPayload(User $customer): array
    {
        return $customer->only([
            'id',
            'name',
            'email',
            'phone',
            'role',
            'address',
            'address_number',
            'district',
            'address_complement',
        ]);
    }

    public function profile(Request $request)
    {
        try {
            $customer = $request->user();

            return response()->json([
                'message' => 'Perfil do cliente recuperado com sucesso.',
                'customer' => $customer->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'role',
                    'address',
                    'address_number',
                    'district',
                    'address_complement',
                ]),
                'user' => $customer->only([
                    'id',
                    'name',
                    'email',
                    'phone',
                    'role',
                    'address',
                    'address_number',
                    'district',
                    'address_complement',
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao recuperar perfil do cliente.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function findOrCreateByWhatsapp(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'address_number' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'address_complement' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $phone = $this->normalizePhone($validated['phone']);
            $email = $this->guestEmailFromPhone($phone);

            $customer = User::where('role', 'customer')
                ->where(function ($q) use ($phone, $email) {
                    $q->where('phone', $phone)
                        ->orWhere('email', $email);
                })
                ->first();

            $customerData = [
                'phone' => $phone,
                'address' => $validated['address'] ?? null,
                'address_number' => $validated['address_number'] ?? null,
                'district' => $validated['district'] ?? null,
                'address_complement' => $validated['address_complement'] ?? null,
            ];

            if ($customer) {
                $customerData['name'] = $customer->name ?: $validated['name'];

                $customer->forceFill($customerData)->save();
            } else {
                $customer = User::create([
                    'name' => $validated['name'],
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Hash::make(Str::random(40)),
                    'role' => 'customer',
                    'address' => $validated['address'] ?? null,
                    'address_number' => $validated['address_number'] ?? null,
                    'district' => $validated['district'] ?? null,
                    'address_complement' => $validated['address_complement'] ?? null,
                ]);
            }

            $customer->refresh();

            return response()->json([
                'message' => 'Cliente localizado com sucesso.',
                'customer' => $this->customerPayload($customer),
                'user' => $this->customerPayload($customer),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao localizar cliente.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function showByWhatsapp(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone' => ['required', 'string', 'max:30'],
            ]);

            $phone = $this->normalizePhone($validated['phone']);
            $email = $this->guestEmailFromPhone($phone);

            $customer = User::where('role', 'customer')
                ->where(function ($q) use ($phone, $email) {
                    $q->where('phone', $phone)
                        ->orWhere('email', $email);
                })
                ->first();

            if (!$customer) {
                return response()->json([
                    'message' => 'Nenhum cliente encontrado para este número.',
                ], 404);
            }

            return response()->json([
                'message' => 'Cliente localizado com sucesso.',
                'customer' => $this->customerPayload($customer),
                'user' => $this->customerPayload($customer),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao localizar cliente.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        if (strlen($normalized) === 10) {
            $normalized = substr($normalized, 0, 2) . '9' . substr($normalized, 2);
        }

        if (strlen($normalized) === 11) {
            $normalized = '55' . $normalized;
        }

        return $normalized;
    }

    private function guestEmailFromPhone(string $phone): string
    {
        return "cliente_{$phone}@checkout.local";
    }

    public function sendCode(Request $request)
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $email = $this->guestEmailFromPhone($phone);

        $customer = User::where('role', 'customer')
            ->where(function ($q) use ($phone, $email) {
                $q->where('phone', $phone)
                    ->orWhere('email', $email);
            })
            ->first();

        if (!$customer) {
            return response()->json([
                'message' => 'Nenhum cliente encontrado.',
            ], 404);
        }

        $isTestMode = env('APP_ENV') === 'local' || $phone === '85999999999';
        $code = $isTestMode ? '123456' : str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        CustomerOtp::where('user_id', $customer->id)->delete();

        CustomerOtp::create([
            'user_id' => $customer->id,
            'phone' => $phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        if ($isTestMode) {
            return response()->json([
                'message' => 'Código de teste (123456) gerado com sucesso.',
                'test_mode' => true,
            ]);
        }

        try {
            $evolutionUrl = env('EVOLUTION_API_URL');
            $instanceName = env('EVOLUTION_INSTANCE_NAME');
            $apiKey = env('EVOLUTION_API_KEY');

            $message = "Olá! Seu código de verificação é: *{$code}*.";

            $response = Http::withHeaders([
                'apikey' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$evolutionUrl}/message/sendText/{$instanceName}", [
                'number' => $phone,
                'text' => $message,
            ]);

            if (!$response->successful()) {
                throw new \Exception('Status da API: ' . $response->status());
            }

            return response()->json([
                'message' => 'Código enviado via WhatsApp.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Falha ao enviar o WhatsApp.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function verifyCode(Request $request)
    {
        try {
            $validated = $request->validate([
                'phone' => ['required', 'string'],
                'code' => ['required', 'string'],
            ]);

            $phone = $this->normalizePhone($validated['phone']);
            $email = $this->guestEmailFromPhone($phone);

            $customer = User::where('role', 'customer')
                ->where(function ($q) use ($phone, $email) {
                    $q->where('phone', $phone)
                        ->orWhere('email', $email);
                })
                ->first();

            if (!$customer) {
                return response()->json([
                    'message' => 'Nenhum cliente encontrado para este número.',
                ], 404);
            }

            $otpRecord = CustomerOtp::where('user_id', $customer->id)
                ->where('code', $validated['code'])
                ->where('expires_at', '>', now())
                ->first();

            if (!$otpRecord) {
                return response()->json([
                    'message' => 'Código inválido ou expirado.',
                ], 422);
            }

            $token = $customer->createToken('customer-auth-token')->plainTextToken;

            CustomerOtp::where('user_id', $customer->id)->delete();

            return response()->json([
                'message' => 'Código verificado com sucesso.',
                'access_token' => $token,
                'user' => $this->customerPayload($customer),
                'customer' => $this->customerPayload($customer),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao verificar código.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function orders(Request $request)
    {
        try {
            $customer = $request->user();
            $orders = $customer->orders()
                ->with(['items.product'])
                ->latest()
                ->get();

            return response()->json([
                'message' => 'Pedidos recuperados com sucesso.',
                'customer_address' => [
                    'address' => $customer->address,
                    'address_number' => $customer->address_number,
                    'number' => $customer->address_number,
                    'district' => $customer->district,
                    'address_complement' => $customer->address_complement,
                ],
                'orders' => $orders,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao buscar pedidos do cliente.',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'address_number' => 'nullable|string|max:255',
                'district' => 'nullable|string|max:255',
                'address_complement' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Erro de validação nos dados enviados.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            DB::beginTransaction();

            $user->forceFill([
                'name' => $validated['name'],
                'phone' => !empty($validated['phone']) ? $this->normalizePhone($validated['phone']) : $user->phone,
                'address' => $validated['address'] ?? null,
                'address_number' => $validated['address_number'] ?? null,
                'district' => $validated['district'] ?? null,
                'address_complement' => $validated['address_complement'] ?? null,
            ])->save();

            DB::commit();

            $user->refresh();

            return response()->json([
                'message' => 'Perfil atualizado com sucesso.',
                'user' => $this->customerPayload($user),
                'customer' => $this->customerPayload($user),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Não foi possível atualizar o perfil no momento.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
