<?php
declare(strict_types=1);

namespace Phpdup\Fixtures\Dto;

class ApiRoutes
{
    public function routes(): array
    {
        return [
            'GET /v1/items' => function ($req) {
                $body = $this->renderer->render($req->path);
                $this->logger->info('api', ['code' => 200]);
                return $body;
            },
            'GET /v1/item' => function ($req) {
                $body = $this->renderer->render($req->path);
                $this->logger->info('api', ['code' => 200]);
                return $body;
            },
            'POST /v1/item' => function ($req) {
                $body = $this->mailer->notify($req->body);
                $this->logger->info('api', ['code' => 201]);
                return $body;
            },
            'DELETE /v1/item' => function ($req) {
                $body = $this->db->ping();
                $this->logger->info('api', ['code' => 200]);
                return $body;
            },
        ];
    }
}
