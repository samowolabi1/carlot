<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Accounts\Actions\LogInWithPassword;
use App\Domain\Accounts\Actions\SendOtp;
use App\Domain\Accounts\Actions\VerifyOtp;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Accounts\Models\User;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Auth\OtpLoginController;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Signing in from the app: the same one-time codes (WhatsApp or email, which also create the
 * account) or a password, answered with a Sanctum token for the device. Tokens last
 * `sanctum.expiration` (90 days) and show on Account → Sign-in and security, where they can be revoked.
 */
class AuthController extends Controller
{
    /** POST /api/v1/auth/code: send a code. */
    public function code(Request $request, SendOtp $sendOtp): JsonResponse
    {
        [$method, $to] = $this->destination($request);

        try {
            if ($method === 'email') {
                $sendOtp->toEmail($to);

                return response()->json(['sent_to' => OtpLoginController::maskEmail($to), 'channel' => 'email'], 202);
            }
            ['channel' => $channel] = $sendOtp->run($to, channel: $request->input('channel') === 'sms' ? 'sms' : null);
        } catch (OtpException $e) {
            throw ValidationException::withMessages([$method === 'email' ? 'email' : 'phone' => $e->getMessage()]);
        }

        return response()->json(['sent_to' => PhoneNumber::mask($to), 'channel' => $channel], 202);
    }

    /** POST /api/v1/auth/token: check the code and issue a token. */
    public function token(Request $request, VerifyOtp $verifyOtp): JsonResponse
    {
        [$method, $to] = $this->destination($request);
        $data = $request->validate([
            'code' => ['required', 'digits:'.config('lotlink.otp.length')],
            'device_name' => ['required', 'string', 'max:60'],
        ]);

        try {
            $user = $method === 'email' ? $verifyOtp->forEmail($to, $data['code']) : $verifyOtp->run($to, $data['code']);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return $this->issue($user, $data['device_name']);
    }

    /** POST /api/v1/auth/password: email or phone and password (accounts that added one). */
    public function password(Request $request, LogInWithPassword $logIn): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => ['required', 'string', 'max:60'],
        ]);

        return $this->issue($logIn->attempt($data['login'], $data['password'], $request->ip()), $data['device_name']);
    }

    /** DELETE /api/v1/auth/token: sign this device out. */
    public function logout(Request $request): JsonResponse
    {
        // Only Sanctum tokens here (auth:sanctum on a bearer token), so this signs out just this device.
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    /** GET /api/v1/me */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => ApiPresenter::user($request->user())]);
    }

    /** PATCH /api/v1/me: the name new accounts add (the web asks on /welcome). */
    public function update(Request $request): JsonResponse
    {
        $request->user()->update($request->validate(['name' => ['required', 'string', 'max:80']]));

        return response()->json(['data' => ApiPresenter::user($request->user())]);
    }

    private function issue(User $user, string $device): JsonResponse
    {
        $token = $user->createToken(Str::limit(trim($device), 60, ''), ['app']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String()
                ?? now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String(),
            'data' => ApiPresenter::user($user),
            'needs_name' => blank($user->name),
        ], 201);
    }

    /** @return array{0: 'email'|'whatsapp', 1: string} */
    private function destination(Request $request): array
    {
        if ($request->input('method') === 'email') {
            return ['email', Str::lower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']))];
        }

        $request->validate(['phone' => ['required', 'string', 'max:32']]);
        try {
            return ['whatsapp', PhoneNumber::normalize((string) $request->input('phone'))];
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }
    }
}
