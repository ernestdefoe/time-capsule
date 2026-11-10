import Component, { type ComponentAttrs } from 'flarum/common/Component';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { span, t } from '../api';

declare const m: Mithril.Static;

interface Attrs extends ComponentAttrs {
  to: string;
  ondone?: () => void;
}

type Unit = 'years' | 'days' | 'hours' | 'minutes' | 'seconds';

/**
 * Years, days, hours, minutes, seconds to a date. It ticks by writing its own
 * digits once a second, never through a page redraw.
 */
export default class Countdown extends Component<Attrs> {
  private timer = 0;
  private done = false;

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    this.timer = window.setInterval(() => this.tick(), 1000);
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    clearInterval(this.timer);
  }

  view() {
    const parts = this.parts();
    const units: Unit[] = parts.years ? ['years', 'days', 'hours', 'minutes', 'seconds'] : ['days', 'hours', 'minutes', 'seconds'];

    return (
      <div className="TimeCapsuleCountdown" role="timer" aria-label={this.label(parts)}>
        {units.map((unit) => (
          <div className={'TimeCapsuleCountdown-part TimeCapsuleCountdown-part--' + unit}>
            <span className="TimeCapsuleCountdown-value" data-unit={unit}>
              {this.pad(unit, parts[unit])}
            </span>
            <span className="TimeCapsuleCountdown-unit">{t('countdown.' + unit)}</span>
          </div>
        ))}
      </div>
    );
  }

  private parts(): Record<Unit, number> {
    const now = new Date();
    const to = new Date(this.attrs.to);
    let ms = Math.max(0, to.getTime() - now.getTime());
    let years = 0;
    if (ms >= 365 * 864e5) {
      years = span(now, to).years;
      const after = new Date(now);
      after.setFullYear(now.getFullYear() + years);
      ms = Math.max(0, to.getTime() - after.getTime());
    }
    return {
      years,
      days: Math.floor(ms / 864e5),
      hours: Math.floor(ms / 36e5) % 24,
      minutes: Math.floor(ms / 6e4) % 60,
      seconds: Math.floor(ms / 1e3) % 60,
    };
  }

  private pad(unit: Unit, value: number): string {
    return unit === 'years' || unit === 'days' ? String(value) : String(value).padStart(2, '0');
  }

  private label(parts: Record<Unit, number>): string {
    return extractText(t('countdown.label', { years: parts.years, days: parts.days, hours: parts.hours }));
  }

  private tick() {
    if (!this.element) return;
    const parts = this.parts();
    this.element.querySelectorAll<HTMLElement>('[data-unit]').forEach((el) => {
      const unit = el.dataset.unit as Unit;
      const text = this.pad(unit, parts[unit]);
      if (el.textContent !== text) el.textContent = text;
    });
    if (!this.done && new Date(this.attrs.to).getTime() <= Date.now()) {
      this.done = true;
      this.attrs.ondone?.();
    }
  }
}
