<?php

namespace Byzic\PterodactylClientApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pterodactyl\Models\User;
use Pterodactyl\Exceptions\Http\ForbiddenException;

class ValidateUserOwnership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws \Pterodactyl\Exceptions\Http\ForbiddenException
     */
    public function handle(Request $request, Closure $next)
    {
        $userId = $request->route('user');
        $authenticatedUser = $request->user();

        // Admin có thể quản lý API keys của bất kỳ user nào
        if ($authenticatedUser->root_admin) {
            return $next($request);
        }

        // User chỉ có thể quản lý API keys của chính mình
        if ((int) $userId !== $authenticatedUser->id) {
            throw new ForbiddenException('You can only manage your own API keys.');
        }

        return $next($request);
    }
}