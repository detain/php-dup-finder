<?php
declare(strict_types=1);

namespace Acme\Http\Controllers;

final class UsersController
{
    public function __construct(private readonly UserService $users)
    {
    }

    public function show(string $id): void
    {
        $user = $this->users->findById($id);
        if ($user === null) {
            $status = 404;
            $payload = ['error' => 'user_not_found', 'id' => $id];
        } else {
            $status = 200;
            $payload = ['id' => $user->id(), 'email' => $user->email(), 'name' => $user->name()];
        }

        // ---- BEGIN copy-pasted response builder ----
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            http_response_code(500);
            echo '{"error":"encoding_failed"}';
            return;
        }
        header('Content-Length: ' . strlen($json));
        echo $json;
        // ---- END copy-pasted response builder ----
    }
}
