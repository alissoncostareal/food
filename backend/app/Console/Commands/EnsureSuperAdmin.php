<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Throwable;

class EnsureSuperAdmin extends Command
{
    protected $signature = 'app:ensure-super-admin';

    protected $description = 'Cria ou atualiza o usuário super admin usando variáveis de ambiente.';

    public function handle(): int
    {
        try {
            $name = env('SUPER_ADMIN_NAME', 'Super Admin');
            $email = env('SUPER_ADMIN_EMAIL');
            $password = env('SUPER_ADMIN_PASSWORD');

            if (blank($email)) {
                $this->error('SUPER_ADMIN_EMAIL não configurado.');
                return self::FAILURE;
            }

            if (blank($password)) {
                $this->error('SUPER_ADMIN_PASSWORD não configurado.');
                return self::FAILURE;
            }

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make($password),
                    'role' => User::ROLE_SUPER_ADMIN,
                ]
            );

            $this->info("Super admin garantido com sucesso: {$user->email}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Erro ao criar/atualizar super admin: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
