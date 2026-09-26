<?php

namespace App\Messages\RemoteImages;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Consent
{
    public static function address(?string $address): ?string
    {
        $address = strtolower(trim((string) $address));

        return strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    public function allowlisted(int $userId, ?string $address): bool
    {
        $address = self::address($address);

        return $address !== null && DB::table('remote_content_allowlist')->where('user_id', $userId)->where('address', $address)->exists();
    }

    public function permits(Request $request, object $message, ?string $grant): bool
    {
        if ($grant === null || $grant === '') {
            return $this->allowlisted((int) $request->user()->id, $message->from_address);
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $grant)) {
            return false;
        }
        $value = $request->session()->get('remote_images.'.$grant);

        return is_array($value) && $value['user'] === (int) $request->user()->id
            && $value['message'] === (int) $message->id && $value['expires'] > time();
    }

    public function issue(Request $request, int $id): string
    {
        $grants = array_filter($request->session()->get('remote_images', []), fn ($value) => $value['expires'] > time());
        $grants = array_slice($grants, -31, null, true);
        $grant = bin2hex(random_bytes(32));
        $grants[$grant] = ['user' => (int) $request->user()->id, 'message' => $id, 'expires' => time() + 600];
        $request->session()->put('remote_images', $grants);

        return $grant;
    }

    public function token(int $user, int $message, string $resource, ?string $grant): string
    {
        // Laravel authenticated encryption includes an HMAC; payload contains no destination URL.
        return Crypt::encryptString(json_encode(['user' => $user, 'message' => $message, 'resource' => $resource, 'grant' => $grant, 'expires' => time() + 300], JSON_THROW_ON_ERROR));
    }

    public function decode(string $token, int $user): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_keys($data) !== ['user', 'message', 'resource', 'grant', 'expires']
                || $data['user'] !== $user || ! is_int($data['message']) || $data['message'] < 1
                || ! is_int($data['expires']) || $data['expires'] <= time()
                || ! is_string($data['resource']) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['resource'])
                || ($data['grant'] !== null && ! is_string($data['grant']))) {
                abort(404);
            }

            return $data;
        } catch (\Throwable) {
            abort(404);
        }
    }
}
