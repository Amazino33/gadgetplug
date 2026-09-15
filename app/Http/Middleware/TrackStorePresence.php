<?php

namespace App\Http\Middleware;

use App\Services\ActiveStore;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackStorePresence
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $vendor = filament()->getTenant();
        $user = auth()->user();

        if ($vendor && $user) {
            $storeId = ActiveStore::currentId();
            
            if ($storeId) {
                DB::table('store_user')
                    ->where('store_id', $storeId)
                    ->where('user_id', $user->id)
                    ->update(['last_active_at' => now()]);
            }
        }

        return $response;
    }
}
