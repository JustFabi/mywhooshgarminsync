<?php

namespace App\Http\Controllers;

use App\Support\ConnectionState;
use App\Services\MyWhoosh\MyWhooshService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Connections', [
            'mywhoosh' => ConnectionState::myWhoosh($user),
            'garmin' => ConnectionState::garmin($user),
        ]);
    }

    public function connectMyWhoosh(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        $service = new MyWhooshService($request->user());

        try {
            $service->login(
                $data['email'],
                $data['password'],
                (bool) ($data['remember'] ?? false),
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'MyWhoosh: '.$e->getMessage());
        }

        return back()->with('success', 'MyWhoosh connected as '.$data['email']);
    }

    public function disconnectMyWhoosh(Request $request): RedirectResponse
    {
        (new MyWhooshService($request->user()))->disconnect();

        return back()->with('success', 'MyWhoosh connection removed');
    }

    public function connectGarmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        $service = new \App\Services\Garmin\GarminService($request->user());

        try {
            $service->login(
                $data['email'],
                $data['password'],
                (bool) ($data['remember'] ?? false),
            );
        } catch (\App\Services\Garmin\GarminException $e) {
            return back()->with('error', 'Garmin: '.$e->getMessage());
        }

        return back()->with('success', 'Garmin Connect connected as '.$data['email']);
    }

    public function disconnectGarmin(Request $request): RedirectResponse
    {
        (new \App\Services\Garmin\GarminService($request->user()))->disconnect();

        return back()->with('success', 'Garmin connection removed');
    }
}
