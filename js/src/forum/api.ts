import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export interface Person {
  id: number;
  name: string;
  slug: string;
  avatar: string | null;
}

export interface PhotoRow {
  url: string;
  width: number;
  height: number;
}

/** A letter as the API sends it. Sealed ones never carry body, photos or author. */
export interface Letter {
  id: number;
  sealed: boolean;
  mine: boolean;
  envelope: string | null;
  opensAt: string;
  sealedAt: string;
  openedAt: string | null;
  words: number;
  photoCount: number;
  visibility: 'private' | 'public';
  community: number | null;
  post: boolean;
  canDelete: boolean;
  peek?: boolean;
  readable?: boolean;
  body?: string | null;
  truncated?: boolean;
  photos?: PhotoRow[];
  author?: Person | null;
  discussion?: { id: number; slug: string; title: string } | null;
  firstRead?: boolean;
}

export interface CommunityRow {
  id: number;
  title: string;
  description: string | null;
  opensAt: string;
  openedAt: string | null;
  createdAt: string;
  letters: number;
  members: number;
  mine: number;
  post: boolean;
  notifyEveryone: boolean;
  discussionId: number | null;
  canManage: boolean;
}

export interface PageData {
  mine: Letter[];
  recent: Letter[];
  community: CommunityRow | null;
  opened: CommunityRow[];
  stats: { sealed: number; opened: number; nextOpen: string | null };
  can: { create: boolean; community: boolean; post: boolean };
  limits: { maxYears: number; maxPhotos: number; maxLength: number; minHours: number; founded: string };
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-time-capsule.forum.${key}`, params);

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-time-capsule.forum.errors.${code}`;
  if (code && app.translator.trans(key) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  if (error?.status === 404) return extractText(t('errors.not_found'));
  return extractText(t('errors.generic'));
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, body?: Record<string, any> | FormData, quiet = false): Promise<T> {
  return app.request<T>({
    method,
    url: app.forum.attribute<string>('apiUrl') + '/time-capsule' + path,
    body,
    serialize: body instanceof FormData ? (raw: any) => raw : undefined,
    errorHandler: (error: any) => {
      if (!quiet) app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

const locale = () => app.translator.getLocale() || undefined;

export function longDate(iso: string): string {
  return new Intl.DateTimeFormat(locale(), { year: 'numeric', month: 'long', day: 'numeric' }).format(new Date(iso));
}

/** Whole calendar years, months and days from a to b (b later), the way people count them. */
export function span(from: Date, to: Date): { years: number; months: number; days: number } {
  let years = to.getFullYear() - from.getFullYear();
  let months = to.getMonth() - from.getMonth();
  let days = to.getDate() - from.getDate();
  if (days < 0) {
    months--;
    days += new Date(to.getFullYear(), to.getMonth(), 0).getDate();
  }
  if (months < 0) {
    years--;
    months += 12;
  }
  return { years: Math.max(0, years), months: Math.max(0, months), days: Math.max(0, days) };
}

/** "3 years, 2 months", "5 days", "4 hours": the two largest parts. */
export function duration(from: Date, to: Date): string {
  const ms = to.getTime() - from.getTime();
  if (ms < 36e5) return extractText(t('time.minutes', { count: Math.max(1, Math.round(ms / 6e4)) }));
  if (ms < 864e5) return extractText(t('time.hours', { count: Math.round(ms / 36e5) }));
  const { years, months, days } = span(from, to);
  const parts: string[] = [];
  if (years) parts.push(extractText(t('time.years', { count: years })));
  if (months) parts.push(extractText(t('time.months', { count: months })));
  if (days && parts.length < 2) parts.push(extractText(t('time.days', { count: days })));
  return parts.slice(0, 2).join(', ') || extractText(t('time.days', { count: 1 }));
}

/** How far between sealing and opening a letter is now, 0 to 1. */
export function progress(letter: { sealedAt: string; opensAt: string }): number {
  const a = new Date(letter.sealedAt).getTime();
  const b = new Date(letter.opensAt).getTime();
  return b <= a ? 1 : Math.min(1, Math.max(0, (Date.now() - a) / (b - a)));
}

/** Local midnight of a date, as ISO 8601 with the member's offset: the server opens it at that moment. */
export function localMidnight(year: number, month: number, day: number): string {
  const d = new Date(year, month, day, 0, 0, 0);
  const offset = -d.getTimezoneOffset();
  const sign = offset >= 0 ? '+' : '-';
  const pad = (n: number) => String(Math.floor(Math.abs(n))).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T00:00:00${sign}${pad(offset / 60)}:${pad(offset % 60)}`;
}

export function dateInput(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export interface Preset {
  key: string;
  label: string;
  date: Date;
}

/**
 * The open dates offered as one tap: next New Year, 1, 5 and 10 years, and the forum's next
 * birthday. Each is at least `minHours` away, as the server requires: on New Year's Eve the
 * New Year preset is the one after.
 */
export function presets(founded: string, maxYears: number, minHours: number): Preset[] {
  const now = new Date();
  const soonest = now.getTime() + minHours * 36e5 + 6e4;
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const later = (years: number) => new Date(today.getFullYear() + years, today.getMonth(), today.getDate());
  const list: Preset[] = [];

  let newYear = new Date(today.getFullYear() + 1, 0, 1);
  if (newYear.getTime() < soonest) newYear = new Date(today.getFullYear() + 2, 0, 1);
  list.push({ key: 'new_year', label: extractText(t('seal.preset.new_year', { year: newYear.getFullYear() })), date: newYear });
  for (const years of [1, 5, 10]) {
    if (years <= maxYears) list.push({ key: `years_${years}`, label: extractText(t('seal.preset.years', { count: years })), date: later(years) });
  }

  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(founded || '');
  if (match) {
    const [y, mo, d] = [+match[1], +match[2] - 1, +match[3]];
    let birthday = new Date(today.getFullYear(), mo, d);
    if (birthday.getTime() < soonest) birthday = new Date(today.getFullYear() + 1, mo, d);
    const age = birthday.getFullYear() - y;
    if (age > 0) list.push({ key: 'anniversary', label: extractText(t('seal.preset.anniversary', { count: age })), date: birthday });
  }

  return list;
}
