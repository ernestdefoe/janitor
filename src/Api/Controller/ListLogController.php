<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Http\RequestUtil;
use ErnestDefoe\Janitor\LogEntry;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListLogController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['data' => LogEntry::orderByDesc('id')->limit(100)->get()]);
    }
}
