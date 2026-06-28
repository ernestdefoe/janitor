import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import JanitorPage from './components/JanitorPage';

declare const m: any;
const t = (k: string) => app.translator.trans('ernestdefoe-janitor.admin.' + k);

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'ernestdefoe-janitor.dry_run',
      type: 'boolean',
      label: t('settings.dry_run'),
      help: t('settings.dry_run_help'),
      default: false,
    }))
    .setting(() => ({
      setting: 'ernestdefoe-janitor.cap',
      type: 'number',
      label: t('settings.cap'),
      help: t('settings.cap_help'),
      default: 100,
    }))
    // The rule manager + action log, on the same settings page.
    .customSetting(() => m(JanitorPage), -10),
];
