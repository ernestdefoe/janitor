import app from 'flarum/admin/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import { saveRule, type Rule, type RuleAction, type Frequency } from '../../common/api';

declare const m: any;
const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-janitor.admin.' + k, p);

const ACTIONS: RuleAction[] = ['hide', 'move', 'add_tag', 'remove_tag', 'lock', 'unlock', 'delete'];
const FREQS: Frequency[] = ['every_run', 'hourly', 'daily', 'weekly'];

export default class RuleEditModal extends Modal {
  rule!: Partial<Rule>;
  tags: any[] = [];
  loading = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.tags = this.attrs.tags || [];
    const r = this.attrs.rule;
    this.rule = r
      ? JSON.parse(JSON.stringify(r))
      : { name: '', enabled: true, scope_tag_ids: [], conditions: { ageBasis: 'last_post' }, action: 'hide', action_tag_ids: [], frequency: 'daily' };
    if (!this.rule.conditions) this.rule.conditions = { ageBasis: 'last_post' };
  }

  className() {
    return 'Modal--medium JanitorRuleModal';
  }

  title() {
    return this.attrs.rule ? t('rules.edit') : t('rules.new');
  }

  content() {
    const r = this.rule;
    const c = r.conditions!;
    const needsTags = ['add_tag', 'remove_tag', 'move'].includes(r.action!);

    return m('.Modal-body', [
      this.group(t('rules.name'), m('input.FormControl', { value: r.name, oninput: (e: any) => (r.name = e.target.value) })),
      m('.Form-group', m(Switch, { state: r.enabled !== false, onchange: (v: boolean) => (r.enabled = v) }, t('rules.enabled'))),
      this.group(t('rules.scope'), this.tagPicker(r.scope_tag_ids!), t('rules.scope_help')),

      m('h4.JanitorRuleModal-h', t('rules.conditions')),
      m('p.helpText', t('rules.conditions_help')),
      m('.JanitorRuleModal-row', [
        this.group(
          t('cond.age_days'),
          m('input.FormControl', { type: 'number', min: 0, value: c.ageDays ?? '', oninput: (e: any) => (c.ageDays = e.target.value === '' ? '' : Number(e.target.value)) })
        ),
        this.group(
          t('cond.age_basis'),
          this.select(c.ageBasis || 'last_post', [['last_post', t('cond.basis_last_post')], ['created', t('cond.basis_created')]], (v) => (c.ageBasis = v as any))
        ),
      ]),
      this.group(t('cond.has_tags'), this.tagPicker((c.hasTagIds = c.hasTagIds || []))),
      this.group(t('cond.lacks_tags'), this.tagPicker((c.lacksTagIds = c.lacksTagIds || []))),
      m('.JanitorRuleModal-row', [
        this.group(t('cond.min_replies'), m('input.FormControl', { type: 'number', min: 0, value: c.minReplies ?? '', oninput: (e: any) => (c.minReplies = e.target.value === '' ? '' : Number(e.target.value)) })),
        this.group(t('cond.max_replies'), m('input.FormControl', { type: 'number', min: 0, value: c.maxReplies ?? '', oninput: (e: any) => (c.maxReplies = e.target.value === '' ? '' : Number(e.target.value)) })),
      ]),
      m('.Form-group', [
        m(Switch, { state: !!c.includeLocked, onchange: (v: boolean) => (c.includeLocked = v) }, t('cond.include_locked')),
        m('p.helpText', t('cond.include_locked_help')),
      ]),
      m('.Form-group', m(Switch, { state: !!c.includeSticky, onchange: (v: boolean) => (c.includeSticky = v) }, t('cond.include_sticky'))),

      m('h4.JanitorRuleModal-h', t('rules.action')),
      this.group(t('rules.action'), this.select(r.action!, ACTIONS.map((a) => [a, t('action.' + a)]), (v) => (r.action = v as any))),
      r.action === 'delete' ? m('.JanitorRuleModal-warn', [m('i.fas.fa-triangle-exclamation'), ' ', t('rules.delete_warn')]) : null,
      needsTags ? this.group(t('rules.action_tags'), this.tagPicker(r.action_tag_ids!), t('rules.action_tags_help')) : null,
      this.group(t('rules.frequency'), this.select(r.frequency!, FREQS.map((f) => [f, t('freq.' + f)]), (v) => (r.frequency = v as any))),

      m('.Form-group', Button.component({ className: 'Button Button--primary Button--block', loading: this.loading, onclick: () => this.submit() }, t('rules.save'))),
    ]);
  }

  group(label: string, control: any, help?: string) {
    return m('.Form-group', [m('label', label), control, help ? m('p.helpText', help) : null]);
  }

  select(val: string, opts: [string, string][], onchange: (v: string) => void) {
    return m('select.FormControl', { value: val, onchange: (e: any) => onchange(e.target.value) }, opts.map(([v, l]) => m('option', { value: v, selected: val === v }, l)));
  }

  tagPicker(selected: number[]) {
    if (!this.tags.length) return m('span.helpText', t('rules.no_tags'));
    return m(
      '.JanitorTagPicker',
      this.tags.map((tag) => {
        const id = Number(tag.id());
        const on = selected.includes(id);
        return m(
          'button.JanitorTag' + (on ? '.is-on' : ''),
          {
            type: 'button',
            style: tag.color() ? { '--tag-color': tag.color() } : undefined,
            onclick: () => {
              const i = selected.indexOf(id);
              i >= 0 ? selected.splice(i, 1) : selected.push(id);
            },
          },
          tag.name()
        );
      })
    );
  }

  submit() {
    this.loading = true;
    saveRule(this.rule, this.attrs.rule?.id)
      .then(() => {
        this.loading = false;
        this.hide();
        this.attrs.onsave && this.attrs.onsave();
      })
      .catch((e) => {
        this.loading = false;
        this.onerror(e);
        m.redraw();
      });
  }
}
