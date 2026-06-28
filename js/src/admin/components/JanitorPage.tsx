import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import RuleEditModal from './RuleEditModal';
import { listRules, listLog, deleteRule, runRule, type Rule, type JanitorLog } from '../../common/api';

declare const m: any;
const t = (k: string, p?: any): any => app.translator.trans('ernestdefoe-janitor.admin.' + k, p);

/** The rule manager + action log, rendered on the Janitor settings page. */
export default class JanitorPage extends Component {
  rules: Rule[] = [];
  log: JanitorLog[] = [];
  tags: Record<number, any> = {};
  loading = true;
  busy: Record<number, boolean> = {};

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    this.loading = true;
    Promise.all([listRules(), listLog(), app.store.find('tags').catch(() => [])]).then(([rules, log, tags]: any) => {
      this.rules = rules.data;
      this.log = log.data;
      this.tags = {};
      (tags || []).forEach((tag: any) => (this.tags[Number(tag.id())] = tag));
      this.loading = false;
      m.redraw();
    });
  }

  tagName(id: number): string {
    return this.tags[id] ? this.tags[id].name() : '#' + id;
  }

  view() {
    if (this.loading) return m('.JanitorPage', m(LoadingIndicator));

    return m('.JanitorPage', [
      m('.Form-group', [
        m('label', t('rules.title')),
        m('p.helpText', t('rules.help')),
        m(
          '.JanitorRules',
          this.rules.length ? this.rules.map((r) => this.ruleRow(r)) : m('.JanitorRules-empty', t('rules.none'))
        ),
        Button.component({ className: 'Button', icon: 'fas fa-plus', onclick: () => this.edit() }, t('rules.add')),
      ]),
      this.logSection(),
    ]);
  }

  ruleRow(r: Rule) {
    return m('.JanitorRule', { key: r.id }, [
      m('.JanitorRule-main', [
        m('.JanitorRule-name', [
          m('span.JanitorRule-dot', { className: r.enabled ? 'is-on' : '' }),
          m('span', r.name),
        ]),
        m('.JanitorRule-summary', this.summarize(r)),
      ]),
      m('.JanitorRule-actions', [
        Button.component(
          { className: 'Button Button--text', icon: 'fas fa-flask', loading: this.busy[r.id!], onclick: () => this.run(r, true) },
          t('rules.preview')
        ),
        Button.component(
          { className: 'Button Button--text', icon: 'fas fa-play', loading: this.busy[r.id!], onclick: () => this.run(r, false) },
          t('rules.run')
        ),
        Button.component({ className: 'Button Button--icon Button--flat', icon: 'fas fa-pencil', onclick: () => this.edit(r) }),
        Button.component({ className: 'Button Button--icon Button--flat', icon: 'fas fa-trash', onclick: () => this.remove(r) }),
      ]),
    ]);
  }

  summarize(r: Rule): string {
    const c = r.conditions || {};
    const parts: string[] = [];
    const scope = (r.scope_tag_ids || []).map((id) => this.tagName(id));
    parts.push(scope.length ? t('rules.in_tags', { tags: scope.join(', ') }) : t('rules.in_all'));
    if (c.ageDays) parts.push(t('rules.older_than', { days: c.ageDays, basis: t('cond.basis_' + (c.ageBasis || 'last_post')) }));
    if ((c.hasTagIds || []).length) parts.push(t('rules.with_tags', { tags: c.hasTagIds!.map((id) => this.tagName(id)).join(', ') }));
    if ((c.lacksTagIds || []).length) parts.push(t('rules.without_tags', { tags: c.lacksTagIds!.map((id) => this.tagName(id)).join(', ') }));
    if (c.minReplies !== undefined && c.minReplies !== '') parts.push('≥ ' + c.minReplies + ' ' + t('cond.replies'));
    if (c.maxReplies !== undefined && c.maxReplies !== '') parts.push('≤ ' + c.maxReplies + ' ' + t('cond.replies'));

    const actionTags = (r.action_tag_ids || []).map((id) => this.tagName(id)).join(', ');
    const action = t('action.' + r.action) + (actionTags ? ' → ' + actionTags : '');
    return `${parts.join(' · ')}  ⇒  ${action}  ·  ${t('freq.' + r.frequency)}`;
  }

  run(r: Rule, dry: boolean) {
    this.busy[r.id!] = true;
    runRule(r.id!, dry)
      .then(({ data }) => {
        this.busy[r.id!] = false;
        const msg = data.dry ? t('rules.ran_dry', { n: data.matched }) : t('rules.ran', { n: data.applied });
        app.alerts.show({ type: 'success' }, msg + (data.capped ? ' ' + t('rules.capped') : ''));
        listLog().then(({ data: log }) => {
          this.log = log;
          m.redraw();
        });
      })
      .catch((e) => {
        this.busy[r.id!] = false;
        m.redraw();
        throw e;
      });
  }

  edit(r?: Rule) {
    app.modal.show(RuleEditModal, { rule: r, tags: Object.values(this.tags), onsave: () => this.load() });
  }

  remove(r: Rule) {
    if (!confirm(t('rules.confirm_delete', { name: r.name }))) return;
    deleteRule(r.id!).then(() => this.load());
  }

  logSection() {
    return m('.Form-group.JanitorLog', [
      m('label', t('log.title')),
      this.log.length
        ? m(
            '.JanitorLog-list',
            this.log.map((e) =>
              m('.JanitorLog-row', { key: e.id }, [
                m('span.JanitorLog-time', new Date(e.created_at).toLocaleString()),
                e.dry_run ? m('span.JanitorLog-dry', t('log.dry')) : null,
                m('span.JanitorLog-action', t('action.' + e.action)),
                m('span.JanitorLog-disc', e.discussion_title),
                m('span.JanitorLog-rule', e.rule_name),
              ])
            )
          )
        : m('.JanitorLog-empty', t('log.none')),
    ]);
  }
}
