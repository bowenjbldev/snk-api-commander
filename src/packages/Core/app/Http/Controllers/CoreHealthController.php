<?php

namespace Packages\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Health')]
class CoreHealthController extends Controller
{
    #[Endpoint(
        title: '/',
        description: 'Returns API health status.',
    )]
    #[Response(200, 'Success')]
    public function index(): JsonResponse
    {
        return response()->json([
            'message' => "Snake Laravel API",
        ]);
    }
}
