<?php

namespace App\Http\Controllers;

use App\Support\ConnectionState;
use App\Support\GarminDevices;
use App\Support\LocalUser;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WatchController extends Controller
{
    public function index(): Response
    {
        $user = LocalUser::get();

        // Keep the catalog fresh; cheap for 36 rows.
        GarminDevices::seed();

        return Inertia::render('Watches', [
            'devices' => Device::query()
                ->orderBy('name')
                ->get(['id', 'name', 'product_id', 'is_default'])
                ->map(fn (Device $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'product_id' => (int) $d->product_id,
                    'is_default' => $d->is_default,
                ]),
            'selected' => $user->selected_device_id,
            'mywhoosh' => ConnectionState::myWhoosh($user),
            'garmin' => ConnectionState::garmin($user),
        ]);
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'device_id' => 'required|integer|exists:devices,id',
        ]);

        $device = Device::query()->findOrFail($data['device_id']);
        LocalUser::get()->update(['selected_device_id' => $device->id]);

        return back()->with('success', 'Spoofing watch set to '.$device->name.' for new syncs');
    }
}
