import Model from 'flarum/common/Model';

/**
 * The community capsule as the store knows it (`time-capsule-communities`), only for starting,
 * changing and deleting it. The page itself reads capsules and letters from /api/time-capsule.
 */
export default class Community extends Model {}
