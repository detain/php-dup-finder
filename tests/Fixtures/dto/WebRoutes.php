<?php
declare(strict_types=1);

namespace Phpdup\Fixtures\Dto;

class WebRoutes
{
    public function routes(): array
    {
        return [
            'GET /home' => function ($req) {
                $body = $this->renderer->render($req->path);
                $this->logger->info('web', ['code' => 200]);
                return $body;
            },
            'GET /about' => function ($req) {
                $body = $this->renderer->render($req->path);
                $this->logger->info('web', ['code' => 200]);
                return $body;
            },
            'POST /contact' => function ($req) {
                $body = $this->mailer->notify($req->body);
                $this->logger->info('web', ['code' => 201]);
                return $body;
            },
            'GET /health' => function ($req) {
                $body = $this->db->ping();
                $this->logger->info('web', ['code' => 200]);
                return $body;
            },
        ];
    }
}
