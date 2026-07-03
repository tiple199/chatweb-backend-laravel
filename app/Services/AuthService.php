<?php

namespace App\Services;

use App\Models\AuthToken;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use App\Mail\SendOtpMail;
use App\Mail\ResetPasswordMail;
use Carbon\Carbon;
use Exception;

class AuthService
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    protected function handleRateLimit(string $email, string $token, int $timeMinutes, string $type)
    {
        $oneHourAgo = Carbon::now()->subHour();

        $count = AuthToken::where('email', $email)
            ->where('type', $type)
            ->where('created_at', '>=', $oneHourAgo)
            ->count();

        if ($count >= 5) {
            // throw new Exception("Too many requests. Please try again later.", 429);
        }

        $latest = AuthToken::where('email', $email)
            ->where('type', $type)
            ->orderByDesc('created_at')
            ->first();

        if ($latest && Carbon::now()->diffInSeconds($latest->created_at) < 60) {
            // throw new Exception("Wait 60 seconds", 429);
        }

        AuthToken::where('email', $email)
            ->where('type', $type)
            ->where('is_used', false)
            ->update(['is_used' => true]);

        return AuthToken::create([
            'token' => $token,
            'type' => $type,
            'email' => $email,
            'expires_at' => Carbon::now()->addMinutes($timeMinutes),
        ]);
    }

    public function sendOtp(string $email)
    {
        $emailExists = User::where('email', $email)->exists();
        if ($emailExists) {
            throw new Exception("Email already exists.", 400);
        }

        $otp = (string) rand(100000, 999999);
        $time = env('TIME_LIMIT_OTP', 15); // Default 15 mins if not set

        $this->handleRateLimit($email, $otp, $time, 'VERIFY_EMAIL');

        Mail::to($email)->send(new SendOtpMail($otp));

        return ['message' => 'OTP sent successfully'];
    }

    public function register(array $data)
    {
        $emailExists = User::where('email', $data['email'])->exists();
        if ($emailExists) {
            throw new Exception("Email already exists.", 400);
        }

        $otpRecord = AuthToken::where('email', $data['email'])
            ->where('token', $data['otp'])
            ->where('is_used', false)
            ->where('type', 'VERIFY_EMAIL')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$otpRecord) {
            throw new Exception("Invalid or expired OTP", 400);
        }

        AuthToken::where('email', $data['email'])
            ->where('type', 'VERIFY_EMAIL')
            ->where('is_used', false)
            ->update(['is_used' => true]);

        $user = $this->userRepository->create([
            'full_name' => $data['fullName'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'is_verified' => true,
        ]);

        $token = JWTAuth::fromUser($user);

        return [
            'user' => $user,
            'accessToken' => $token,
            'refreshToken' => $token, // For JWT, using same for now or implement refresh token logic
        ];
    }

    public function forgotPassword(string $email)
    {
        $emailExists = User::where('email', $email)->exists();
        if (!$emailExists) {
            throw new Exception("Email not found.", 404);
        }

        $otp = (string) rand(100000, 999999);
        $time = env('RATE_LIMIT_FORGOT_PASSWORD', 15); // Default 15 mins

        $this->handleRateLimit($email, $otp, $time, 'RESET_PASSWORD');

        Mail::to($email)->send(new SendOtpMail($otp));

        return ['message' => 'OTP sent successfully'];
    }

    public function resetPassword(string $token, string $password)
    {
        $tokenRecord = AuthToken::where('token', $token)
            ->where('is_used', false)
            ->where('type', 'RESET_PASSWORD')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$tokenRecord) {
            throw new Exception("Invalid or expired token", 400);
        }

        User::where('email', $tokenRecord->email)->update([
            'password' => Hash::make($password)
        ]);

        $tokenRecord->update(['is_used' => true]);

        return ['message' => 'Password reset successfully'];
    }

    public function login(array $credentials)
    {
        if (!$token = auth('api')->attempt($credentials)) {
            return false;
        }

        $user = User::where('email', $credentials['email'])->first();

        return [
            'user' => $user,
            'accessToken' => $token,
            'refreshToken' => $token,
        ];
    }

    public function logout()
    {
        auth('api')->logout();
        return true;
    }

    public function refreshToken()
    {
        try {
            $newToken = auth('api')->refresh();
            return [
                'accessToken' => $newToken,
                'refreshToken' => $newToken,
            ];
        } catch (\Exception $e) {
            return false;
        }
    }
}
