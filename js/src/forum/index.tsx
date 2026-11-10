import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link and the two notifications. The page and its forms are a
 * chunk fetched when someone opens /time-capsule.
 */

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-time-capsule.forum.${key}`, params);

const date = (iso?: string) =>
  iso
    ? new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(iso))
    : '';

class CapsuleOpenedNotification extends Notification {
  icon() {
    return 'fas fa-envelope-open-text';
  }

  href() {
    return app.route('time-capsule.letter', { id: (this.attrs.notification as any).content()?.capsule });
  }

  content(): Mithril.Children {
    return t('notification.opened', { date: date((this.attrs.notification as any).content()?.sealed) });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

class CommunityOpenedNotification extends Notification {
  icon() {
    return 'fas fa-hourglass-end';
  }

  href() {
    return app.route('time-capsule.community', { id: (this.attrs.notification as any).content()?.community });
  }

  content(): Mithril.Children {
    return t('notification.community', { title: (this.attrs.notification as any).content()?.title });
  }

  excerpt(): Mithril.Children {
    return null;
  }
}

app.initializers.add('ernestdefoe/time-capsule', () => {
  const page = () => import('./components/TimeCapsulePage').then(async (module) => (await module.loadCore(), module));

  app.routes['time-capsule'] = { path: '/time-capsule', component: page };
  app.routes['time-capsule.letter'] = { path: '/time-capsule/:id', component: page };
  app.routes['time-capsule.community'] = { path: '/time-capsule/community/:id', component: page };

  app.notificationComponents.timeCapsuleOpened = CapsuleOpenedNotification;
  app.notificationComponents.timeCapsuleCommunityOpened = CommunityOpenedNotification;

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
