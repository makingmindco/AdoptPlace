<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordController extends Controller
{
    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        $data['email'] = strtolower(trim($data['email']));
        try {
            Password::sendResetLink($data);
        } catch (\Throwable $exception) {
            Log::warning('Password reset delivery failed');
        }

        return response()->json(['message' => 'Se houver uma conta com esse e-mail, você receberá as instruções de recuperação.']);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $data['email'] = strtolower(trim($data['email']));
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'session_version' => Str::random(40)])->save();
            $user->tokens()->delete();
            event(new PasswordReset($user));
        });
        abort_unless($status === Password::PASSWORD_RESET, 422, 'Link inválido ou expirado. Solicite outro link.');

        return response()->json(['message' => 'Senha atualizada. Entre novamente.']);
    }
}
