<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:ensure-super-admin', function () {
    $name = (string) config('services.super_admin.name', 'Super Admin');
    $email = (string) config('services.super_admin.email');
    $password = (string) config('services.super_admin.password');

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

Schedule::command('subscriptions:process-expirations')
    ->hourlyAt(5)
    ->withoutOverlapping();

Schedule::command('subscriptions:process-locked-prices')
    ->dailyAt('06:10')
    ->withoutOverlapping();

Schedule::command('ifood:poll-events')
    ->everyTwoMinutes()
    ->withoutOverlapping();

Schedule::command('orders:expire-stale-pending')
    ->hourlyAt(10)
    ->withoutOverlapping();

Schedule::command('orders:expire-unpaid-pix')
    ->everyFiveMinutes()
    ->withoutOverlapping();
