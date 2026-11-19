import app from 'flarum/forum/app';
import Extend from 'flarum/common/extenders';
import Notification from 'flarum/forum/components/Notification';
import type Mithril from 'mithril';

/*
 * The declarative half of the forum: the routes (the page is a chunk fetched when someone opens
 * /time-capsule) and the two notification components.
 */

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-time-capsule.forum.${key}`, params);

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

const page = () => import('./components/TimeCapsulePage').then(async (module) => (await module.loadCore(), module));

export default [
  new Extend.Routes()
    .add('time-capsule', '/time-capsule', page)
    .add('time-capsule.letter', '/time-capsule/:id', page)
    .add('time-capsule.community', '/time-capsule/community/:id', page),
  new Extend.Notification().add('timeCapsuleOpened', CapsuleOpenedNotification).add('timeCapsuleCommunityOpened', CommunityOpenedNotification),
];
