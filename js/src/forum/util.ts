import app from 'flarum/forum/app';

/*
 * The two helpers forum.js itself needs (the notifications use them); everything else lives in
 * ./api, which only the page's chunk loads.
 */

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-time-capsule.forum.${key}`, params);

export function longDate(iso: string): string {
  return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(iso));
}
