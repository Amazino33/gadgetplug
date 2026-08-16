<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStoreStock;
use App\Models\Vendor;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\ProductStoreStockObserver;
use App\Observers\VendorObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Order::observe(OrderObserver::class);
        Vendor::observe(VendorObserver::class);
        Product::observe(ProductObserver::class);
        ProductStoreStock::observe(ProductStoreStockObserver::class);
        // Your existing HTTPS force code
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // 2. Add this Super Admin bypass
        Gate::before(function ($user, $ability) {
            return $user->hasRole('super_admin') ? true : null;
        });

        Role::deleting(function (Role $role): void {
            DB::table('model_has_roles')->where('role_id', $role->id)->delete();
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();

            // SET FOREIGN_KEY_CHECKS is MySQL-only syntax and is a hard error on
            // SQLite, which the test suite runs on.
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            }
        });

        Role::deleted(function (Role $role): void {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): Password => Password::min(8)
            ->mixedCase()
            ->letters()
            ->numbers()
            ->symbols()
            ->when(app()->isProduction(), fn ($p) => $p->uncompromised()),
        );
    }
}
