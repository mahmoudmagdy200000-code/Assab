<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

class BroadcastAuthController extends BaseController
{
    public function authenticate(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        Log::info('Broadcasting auth', [
            'user_id' => $user->getKey(),
            'user_type' => get_class($user),
            'channel' => $request->input('channel_name'),
            'socket_id' => $request->input('socket_id'),
        ]);

        return Broadcast::auth($request);
    }
}
