<?php

use App\Models\User;
use App\Services\OrderService;
use App\Services\StripeGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

// cPanel cron (every minute, added by server/platform.sh): cd ~/pmshop/current && php artisan schedule:run
Schedule::call(function () {
    $stripe = rescue(fn () => app(StripeGateway::class)->method() ? app(StripeGateway::class) : null, null, false);
    app(OrderService::class)->expireUnpaidCardOrders($stripe);
})->name('expire-unpaid-card-orders')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('shop:expire-orders', function (OrderService $orders) {
    $stripe = rescue(fn () => app(StripeGateway::class)->method() ? app(StripeGateway::class) : null, null, false);
    $this->info('Expired '.$orders->expireUnpaidCardOrders($stripe).' unpaid card order(s).');
})->purpose('Cancel unpaid card orders past the payment timeout and return their stock');

Artisan::command('shop:admin {email} {--name=Admin} {--role=admin} {--password=}', function () {
    $email = mb_strtolower($this->argument('email'));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Not an email address.');

        return 1;
    }
    $role = $this->option('role');
    if (! in_array($role, User::ROLES, true)) {
        $this->error('Role must be admin or manager.');

        return 1;
    }
    $password = $this->option('password') ?: Str::password(16, symbols: false);
    $user = User::query()->firstOrNew(['email' => $email]);
    $user->fill(['name' => $this->option('name'), 'role' => $role, 'is_active' => true, 'password' => $password]);
    $user->save();
    $this->line('Admin login: '.$email);
    if (! $this->option('password')) {
        $this->line('Password:    '.$password);
        $this->warn('Save this password now. It is not shown again.');
    }
})->purpose('Create or reset a staff account');
