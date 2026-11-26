<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use App\Support\PushDevices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PushDeviceController extends Controller
{
    public function store(Request $request)
    {
        $site = $request->attributes->get('site');
        abort_unless(PushDevices::available($site), 404);
        $data = $request->validate([
            'endpoint' => 'required|string|max:2048',
            'keys.p256dh' => 'required|string|size:87',
            'keys.auth' => 'required|string|size:22',
        ]);
        if (! PushDevices::endpointValid($data['endpoint']) || ! PushDevices::keyValid($data['keys']['p256dh'], 65, true)
            || ! PushDevices::keyValid($data['keys']['auth'], 16)) {
            throw ValidationException::withMessages(['subscription' => 'This browser subscription is not supported.']);
        }
        $hash = hash('sha256', $data['endpoint']);
        $member = $request->user()->id;
        DB::transaction(function () use ($site, $data, $hash, $member) {
            User::lockForUpdate()->findOrFail($member);
            $existing = DB::table('push_devices')->where('endpoint_hash', $hash)->lockForUpdate()->first();
            if ($existing && ((int) $existing->user_id !== $member || (int) $existing->site_id !== $site->id)) {
                abort(409, 'Remove the existing browser subscription before registering another account.');
            }
            if (! $existing && DB::table('push_devices')->where('user_id', $member)->count() >= 10) {
                abort(422, 'Remove an existing device before registering another.');
            }
            $values = ['subscription' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'updated_at' => now()];
            if ($existing) {
                DB::table('push_devices')->where('id', $existing->id)->update($values);
            } else {
                DB::table('push_devices')->insert($values + ['user_id' => $member, 'site_id' => $site->id, 'endpoint_hash' => $hash, 'created_at' => now()]);
                Audit::record('push.device-registered', $member, $site->id);
            }
        }, attempts: 3);

        return response()->json(['registered' => true])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $device)
    {
        abort_unless(PushDevices::enabled(), 404);
        $member = $request->user()->id;
        $site = $request->attributes->get('site');
        DB::transaction(function () use ($device, $member, $site) {
            $deleted = DB::table('push_devices')->where('id', $device)->where('user_id', $member)->delete();
            abort_unless($deleted, 404);
            Audit::record('push.device-removed', $member, $site->id);
        });

        return redirect('/account/notifications')->with('status', 'Device registration removed from your account.');
    }

    public function unsubscribe(Request $request)
    {
        abort_unless(PushDevices::enabled(), 404);
        $data = $request->validate(['endpoint' => 'required|string|max:2048']);
        $member = $request->user()->id;
        $site = $request->attributes->get('site');
        DB::transaction(function () use ($data, $member, $site) {
            $deleted = DB::table('push_devices')->where('user_id', $member)->where('site_id', $site->id)
                ->where('endpoint_hash', hash('sha256', $data['endpoint']))->delete();
            if ($deleted) {
                Audit::record('push.device-removed', $member, $site->id);
            }
        });

        return response()->json(['removed' => true])->header('Cache-Control', 'private, no-store');
    }
}
