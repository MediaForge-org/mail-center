<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messages\EmailHtml;
use App\Messages\RemoteImages\Consent;
use App\Messages\RemoteImages\Fetcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RemoteImageController extends Controller
{
    public function consent(Request $request, int $id, Consent $consent)
    {
        $input = $request->validate(['mode' => ['required', 'in:once,always,block']]);
        $row = MessageController::readableMessage($request, $id)->select('messages.id', 'messages.from_address')->first();
        abort_if($row === null, 404);
        $grant = null;
        if ($input['mode'] === 'once') {
            $grant = $consent->issue($request, $id);
        } else {
            $address = Consent::address($row->from_address);
            abort_if($address === null, 422);
            $key = ['user_id' => (int) $request->user()->id, 'address' => $address];
            if ($input['mode'] === 'always') {
                DB::table('remote_content_allowlist')->upsert([array_merge($key, ['created_at' => now(), 'updated_at' => now()])], ['user_id', 'address'], ['updated_at']);
            } else {
                DB::table('remote_content_allowlist')->where($key)->delete();
                // Revocation also invalidates this session's one-time grants for this message.
                $request->session()->put('remote_images', array_filter($request->session()->get('remote_images', []), fn ($value) => $value['message'] !== $id));
            }
        }

        return response()->json(['grant' => $grant, 'always' => $consent->allowlisted((int) $request->user()->id, $row->from_address)])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request)
    {
        $input = $request->validate(['grant' => ['required', 'regex:/\A[a-f0-9]{64}\z/']]);
        $request->session()->forget('remote_images.'.$input['grant']);

        return response()->noContent();
    }

    public function proxy(Request $request, Consent $consent, Fetcher $fetcher)
    {
        abort_unless(array_keys($request->query()) === ['token'] && is_string($request->query('token')) && strlen($request->query('token')) <= 2048, 404);
        $data = $consent->decode($request->query('token'), (int) $request->user()->id);
        $row = MessageController::readableMessage($request, $data['message'])
            ->where('message_bodies.sanitizer_version', EmailHtml::VERSION)->where('messages.parse_status', '<>', 'failed')
            ->select('messages.id', 'messages.from_address', 'message_bodies.remote_resources')->first();
        abort_if($row === null, 404);
        abort_unless($consent->permits($request, $row, $data['grant']), 404);
        $resources = json_decode($row->remote_resources, true);
        $url = $resources[$data['resource']] ?? null;
        abort_unless(is_string($url) && hash('sha256', $url) === $data['resource'], 404);
        try {
            $image = $fetcher->fetch($url);
        } catch (\Throwable) {
            // Deliberately never report upstream exceptions, URLs, IPs or redirect details.
            return response('', 404, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
        }

        return response($image['bytes'], 200, [
            'Content-Type' => $image['mime'], 'Content-Length' => (string) strlen($image['bytes']),
            'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
