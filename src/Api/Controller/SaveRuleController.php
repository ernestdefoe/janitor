<?php

namespace ErnestDefoe\Janitor\Api\Controller;

use Flarum\Http\RequestUtil;
use ErnestDefoe\Janitor\Rule;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Create (POST) or update (PATCH /{id}) a rule. */
class SaveRuleController implements RequestHandlerInterface
{
    public const ACTIONS = ['hide', 'delete', 'lock', 'unlock', 'add_tag', 'remove_tag', 'move'];
    public const FREQUENCIES = ['every_run', 'hourly', 'daily', 'weekly'];

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');
        $body = (array) $request->getParsedBody();
        $data = (array) ($body['data'] ?? $body);

        $rule = $id ? Rule::findOrFail((int) $id) : new Rule();

        $rule->name = trim((string) ($data['name'] ?? '')) ?: 'Untitled rule';
        $rule->enabled = (bool) ($data['enabled'] ?? true);
        $rule->scope_tag_ids = $this->ids($data['scope_tag_ids'] ?? []);
        $rule->conditions = $this->cleanConditions((array) ($data['conditions'] ?? []));
        $rule->action = in_array($data['action'] ?? '', self::ACTIONS, true) ? $data['action'] : 'hide';
        $rule->action_tag_ids = $this->ids($data['action_tag_ids'] ?? []);
        $rule->frequency = in_array($data['frequency'] ?? '', self::FREQUENCIES, true) ? $data['frequency'] : 'daily';

        $rule->save();

        return new JsonResponse(['data' => $rule], $id ? 200 : 201);
    }

    private function ids($value): array
    {
        return array_values(array_filter(array_map('intval', (array) $value)));
    }

    private function cleanConditions(array $c): array
    {
        $out = [];
        if (isset($c['ageDays']) && $c['ageDays'] !== '' && $c['ageDays'] !== null) {
            $out['ageDays'] = max(0, (int) $c['ageDays']);
        }
        $out['ageBasis'] = ($c['ageBasis'] ?? 'last_post') === 'created' ? 'created' : 'last_post';
        $out['hasTagIds'] = $this->ids($c['hasTagIds'] ?? []);
        $out['lacksTagIds'] = $this->ids($c['lacksTagIds'] ?? []);
        if (isset($c['minReplies']) && $c['minReplies'] !== '' && $c['minReplies'] !== null) {
            $out['minReplies'] = max(0, (int) $c['minReplies']);
        }
        if (isset($c['maxReplies']) && $c['maxReplies'] !== '' && $c['maxReplies'] !== null) {
            $out['maxReplies'] = max(0, (int) $c['maxReplies']);
        }
        // Per-rule opt-outs from the sticky/locked safety guard (default: protected).
        $out['includeSticky'] = ! empty($c['includeSticky']);
        $out['includeLocked'] = ! empty($c['includeLocked']);

        return $out;
    }
}
