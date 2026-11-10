import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

const t = (key: string) => app.translator.trans(`ernestdefoe-time-capsule.admin.${key}`, {}, true);

/** Tags an opened letter's discussion can go in, when flarum/tags is on. */
function tagOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.tag_none')) };
  for (const tag of app.store.all<any>('tags')) options[tag.id()] = tag.name();
  return options;
}

export default [
  new Extend.Admin()
    .setting(() => ({ setting: 'time-capsule.nav', type: 'boolean', label: t('settings.nav') }), 100)
    .setting(
      () =>
        'flarum-tags' in ((globalThis as any).flarum?.extensions || {})
          ? {
              setting: 'time-capsule.tag',
              type: 'select',
              options: tagOptions(),
              default: '',
              label: t('settings.tag'),
              help: t('settings.tag_help'),
            }
          : null,
      90
    )
    .setting(
      () => ({
        setting: 'time-capsule.max_years',
        type: 'number',
        min: 1,
        max: 100,
        label: t('settings.max_years'),
        help: t('settings.max_years_help'),
      }),
      80
    )
    .setting(() => ({ setting: 'time-capsule.founded', type: 'date', label: t('settings.founded'), help: t('settings.founded_help') }), 70)
    .customSetting(
      () => (
        <div className="Form-group TimeCapsuleAdmin-backup">
          <label>{t('settings.backup')}</label>
          <p className="helpText">{t('settings.backup_help')}</p>
        </div>
      ),
      60
    )
    .permission(
      () => ({ permission: 'time-capsule.view', icon: 'fas fa-hourglass-half', label: t('permissions.view'), allowGuest: true }),
      'view',
      55
    )
    .permission(() => ({ permission: 'time-capsule.create', icon: 'fas fa-envelope', label: t('permissions.create') }), 'start', 55)
    .permission(() => ({ permission: 'time-capsule.community', icon: 'fas fa-box-archive', label: t('permissions.community') }), 'moderate', 55)
    .permission(() => ({ permission: 'time-capsule.moderate', icon: 'fas fa-trash', label: t('permissions.moderate') }), 'moderate', 54),
];
