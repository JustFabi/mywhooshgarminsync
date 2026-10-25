<?php

namespace App\Services\MyWhoosh;

use App\Models\User;

/**
 * Per-user MyWhoosh facade: resolves the connected account and transparently
 * re-logs-in once if the stored session is rejected and a (remembered)
 * password is available.
 */
final class MyWhooshService
{
    public function __construct(private readonly User $user) {}

    /**
     * Run a MyWhoosh client call, refreshing the session once on
     * authentication failure when possible.
     *
     * @template T
     *
     * @param  callable(MyWhooshClient): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        $account = $this->user->myWhoosh;
        if ($account === null) {
            throw new MyWhooshAuthException('MyWhoosh is not connected');
        }

        $client = MyWhooshClient::fromAccount($account);

        try {
            return $callback($client);
        } catch (MyWhooshAuthException $e) {
            if (! $account->canAutoRelogin()) {
                throw $e;
            }

            $this->login($account->email, $account->password ?? '');

            return $callback(MyWhooshClient::fromAccount($account->refresh()));
        }
    }

    /**
     * Log a user in and persist the session (optionally the password too).
     */
    public function login(string $email, string $password, bool $rememberPassword = false): void
    {
        ['accessToken' => $accessToken, 'whooshId' => $whooshId] = MyWhooshClient::login($email, $password);

        $this->user->myWhoosh()->updateOrCreate(
            ['user_id' => $this->user->id],
            [
                'email' => $email,
                'password' => $rememberPassword ? $password : ($this->user->myWhoosh?->password),
                'access_token' => $accessToken,
                'whoosh_id' => $whooshId,
                'connected_at' => now(),
            ],
        );
    }

    public function disconnect(): void
    {
        $this->user->myWhoosh()?->delete();
    }
}
