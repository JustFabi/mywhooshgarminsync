<?php

namespace App\Http\Controllers;

use App\Support\ConnectionState;
use App\Services\MyWhoosh\MyWhooshService;
use App\Support\LocalUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    public function index(): Response
    {
        $user = LocalUser::get();

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

        $service = new MyWhooshService(LocalUser::get());

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

    public function disconnectMyWhoosh(): RedirectResponse
    {
        (new MyWhooshService(LocalUser::get()))->disconnect();

        return back()->with('success', 'MyWhoosh connection removed');
    }

    public function connectGarmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'remember' => 'boolean',
        ]);

        $service = new \App\Services\Garmin\GarminService(LocalUser::get());

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

    public function disconnectGarmin(): RedirectResponse
    {
        (new \App\Services\Garmin\GarminService(LocalUser::get()))->disconnect();

        return back()->with('success', 'Garmin connection removed');
    }
}
