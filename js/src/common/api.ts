import app from 'flarum/common/app';

export type RuleAction = 'hide' | 'delete' | 'lock' | 'unlock' | 'add_tag' | 'remove_tag' | 'move';
export type Frequency = 'every_run' | 'hourly' | 'daily' | 'weekly';

export interface RuleConditions {
  ageDays?: number | '';
  ageBasis?: 'last_post' | 'created';
  hasTagIds?: number[];
  lacksTagIds?: number[];
  minReplies?: number | '';
  maxReplies?: number | '';
  includeLocked?: boolean;
  includeSticky?: boolean;
}

export interface Rule {
  id?: number;
  name: string;
  enabled: boolean;
  scope_tag_ids: number[];
  conditions: RuleConditions;
  action: RuleAction;
  action_tag_ids: number[];
  frequency: Frequency;
  last_run_at?: string | null;
}

export interface JanitorLog {
  id: number;
  rule_name: string;
  action: string;
  discussion_id?: number | null;
  discussion_title: string;
  dry_run: boolean;
  created_at: string;
}

export interface RunResult {
  rule: string;
  matched: number;
  applied: number;
  dry: boolean;
  capped: boolean;
}

const base = (): string => app.forum.attribute('apiUrl');

export function listRules(): Promise<{ data: Rule[] }> {
  return app.request({ method: 'GET', url: `${base()}/janitor/rules` });
}

export function saveRule(data: Partial<Rule>, id?: number): Promise<{ data: Rule }> {
  return app.request({
    method: id ? 'PATCH' : 'POST',
    url: `${base()}/janitor/rules${id ? '/' + id : ''}`,
    body: { data },
  });
}

export function deleteRule(id: number): Promise<void> {
  return app.request({ method: 'DELETE', url: `${base()}/janitor/rules/${id}` });
}

export function runRule(id: number, dry: boolean): Promise<{ data: RunResult }> {
  return app.request({ method: 'POST', url: `${base()}/janitor/rules/${id}/run?dry=${dry ? 1 : 0}` });
}

export function listLog(): Promise<{ data: JanitorLog[] }> {
  return app.request({ method: 'GET', url: `${base()}/janitor/log` });
}
