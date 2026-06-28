<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Http\RequestUtil;
use ErnestDefoe\Janitor\Rule;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteRuleController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        Rule::findOrFail((int) Arr::get($request->getQueryParams(), 'id'))->delete();

        return new EmptyResponse(204);
    }
}
