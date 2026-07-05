import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import LoadingModal from 'flarum/admin/components/LoadingModal';

declare const m: any;
const t = (k: string) => app.translator.trans('ernestdefoe-janitor.admin.' + k);

/**
 * Dashboard tools-menu chores: Run migrations / Publish assets sit beside
 * core's Clear Caches + System info in the StatusWidget dropdown. Both call
 * admin-gated janitor endpoints that replicate the CLI commands in-process,
 * so shared-hosting admins never need a terminal.
 */
function runChore(url: string, doneKey: string, reload: boolean) {
  app.modal.show(LoadingModal);

  app
    .request({ method: 'POST', url: app.forum.attribute('apiUrl') + url })
    .then((response: any) => {
      app.modal.close();
      app.alerts.clear();
      app.alerts.show({ type: 'success' }, t(doneKey));
      const log = (response && response.log) || [];
      if (log.length) console.info('[janitor] ' + url + '\n' + log.join('\n'));
      if (reload) window.location.reload();
    })
    .catch(() => {
      app.modal.close();
      app.alerts.clear();
      app.alerts.show({ type: 'error' }, t('maintenance.failed'));
    });
}

app.initializers.add('ernestdefoe-janitor-maintenance', () => {
  extend('flarum/admin/components/StatusWidget', 'toolsItems', function (items: any) {
    items.add(
      'janitorRunMigrations',
      m(Button, { onclick: () => runChore('/janitor/maintenance/migrate', 'maintenance.migrated', true) }, t('maintenance.migrate_button')),
      8
    );
    items.add(
      'janitorPublishAssets',
      m(Button, { onclick: () => runChore('/janitor/maintenance/assets', 'maintenance.published', false) }, t('maintenance.assets_button')),
      6
    );
  });
});

export { default as extend } from './extend';
