<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Http\RequestUtil;
use ErnestDefoe\Janitor\Janitor;
use ErnestDefoe\Janitor\Rule;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Run a single rule on demand. `?dry=1` previews; the global dry-run also forces it. */
class RunRuleController implements RequestHandlerInterface
{
    public function __construct(protected Janitor $janitor)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $rule = Rule::findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $dry = filter_var(Arr::get($request->getQueryParams(), 'dry'), FILTER_VALIDATE_BOOLEAN) || $this->janitor->globalDryRun();

        return new JsonResponse(['data' => $this->janitor->runRule($rule, $dry)]);
    }
}
