<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:ensure-super-admin', function () {
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

    $user = \App\Models\User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => $name,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'role' => \App\Models\User::ROLE_SUPER_ADMIN,
        ]
    );

    $this->info("Super admin garantido com sucesso: {$user->email}");

    return self::SUCCESS;
})->purpose('Cria ou atualiza o usuário super admin usando variáveis de ambiente.');
