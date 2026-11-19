import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import { t } from './util';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes and notifications
 * (./extend), the sidebar link and the notification settings. The page and its forms are a
 * chunk fetched when someone opens /time-capsule.
 */

app.initializers.add('ernestdefoe/time-capsule', () => {
  // String form: IndexSidebar lives in a lazy chunk, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('timeCapsuleCanView') || !app.forum.attribute('timeCapsuleNav')) return;
    items.add('time-capsule', LinkButton.component({ href: app.route('time-capsule'), icon: 'fas fa-hourglass-half' }, t('nav')), 78);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    items.add('timeCapsuleOpened', { name: 'timeCapsuleOpened', icon: 'fas fa-envelope-open-text', label: t('settings.notify_opened') });
    items.add('timeCapsuleCommunityOpened', {
      name: 'timeCapsuleCommunityOpened',
      icon: 'fas fa-hourglass-end',
      label: t('settings.notify_community'),
    });
  });
});
