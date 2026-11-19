import app from 'flarum/forum/app';
import Model from 'flarum/common/Model';
import type { CommunityRow } from '../api';

/**
 * The community capsule as the store knows it (`time-capsule-communities`), only for starting,
 * changing and deleting it. The page itself reads capsules and letters from /api/time-capsule.
 */
export default class Community extends Model {
  title = Model.attribute<string>('title');
  description = Model.attribute<string | null>('description');
  opensAt = Model.attribute('opensAt', Model.transformDate);
  openedAt = Model.attribute('openedAt', Model.transformDate);
  post = Model.attribute<boolean>('post');
  notifyEveryone = Model.attribute<boolean>('notifyEveryone');

  /** The capsule the page already has, as a store record. */
  static from(row: CommunityRow): Community {
    return app.store.pushPayload<Community>({
      data: {
        type: 'time-capsule-communities',
        id: String(row.id),
        attributes: {
          title: row.title,
          description: row.description,
          opensAt: row.opensAt,
          openedAt: row.openedAt,
          post: row.post,
          notifyEveryone: row.notifyEveryone,
        },
      },
    });
  }
}
