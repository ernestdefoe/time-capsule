import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import Community from '../models/Community';
import { dateInput, localMidnight, t, type CommunityRow } from '../api';

interface Attrs extends IFormModalAttrs {
  community: CommunityRow | null;
  maxYears: number;
  onsave: () => void;
}

/** Start the forum-wide capsule, or change it while it's sealed. */
export default class CommunityModal extends FormModal<Attrs> {
  title_ = '';
  description = '';
  date = '';
  /** The day as the modal opened: sent again only if it was changed (it's shown in this browser's time zone). */
  initialDate = '';
  post = true;
  notifyEveryone = true;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const c = this.attrs.community;
    const nextYear = new Date(new Date().getFullYear() + 1, 0, 1);
    this.title_ = c?.title || '';
    this.description = c?.description || '';
    this.date = this.initialDate = dateInput(c ? new Date(c.opensAt) : nextYear);
    this.post = c ? c.post : true;
    this.notifyEveryone = c ? c.notifyEveryone : true;
  }

  /** Tomorrow; or, once members have written letters, the day already set (it can only move later). */
  earliest(): Date {
    const tomorrow = new Date(Date.now() + 864e5);
    const c = this.attrs.community;
    return c && c.letters > 0 && new Date(c.opensAt) > tomorrow ? new Date(c.opensAt) : tomorrow;
  }

  className() {
    return 'TimeCapsuleCommunityModal Modal--medium';
  }

  title() {
    return this.attrs.community ? t('community.edit_title') : t('community.start_title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label for="TimeCapsuleCommunity-name">{t('community.field_title')}</label>
          <input
            id="TimeCapsuleCommunity-name"
            className="FormControl"
            maxlength={100}
            placeholder={extractText(t('community.field_title_placeholder', { year: new Date().getFullYear() + 1 }))}
            value={this.title_}
            oninput={(e: InputEvent) => (this.title_ = (e.target as HTMLInputElement).value)}
          />
        </div>
        <div className="Form-group">
          <label for="TimeCapsuleCommunity-description">{t('community.field_description')}</label>
          <textarea
            id="TimeCapsuleCommunity-description"
            className="FormControl"
            rows={3}
            maxlength={2000}
            value={this.description}
            oninput={(e: InputEvent) => (this.description = (e.target as HTMLTextAreaElement).value)}
          />
        </div>
        {this.dateField()}
        <div className="Form-group">
          <Switch state={this.notifyEveryone} onchange={(v: boolean) => (this.notifyEveryone = v)}>
            {t('community.field_notify')}
          </Switch>
          <Switch state={this.post} onchange={(v: boolean) => (this.post = v)}>
            {t('community.field_post')}
          </Switch>
        </div>
        <div className="Form-group">
          <Button
            type="submit"
            className="Button Button--primary Button--block"
            loading={this.loading}
            disabled={this.title_.trim().length < 3 || !this.date}
          >
            {this.attrs.community ? t('community.save') : t('community.create')}
          </Button>
        </div>
      </div>
    );
  }

  /** The opening day: from the soonest allowed (later only, once members have written) to the forum's limit. */
  dateField(): Mithril.Children {
    const max = new Date();
    max.setFullYear(max.getFullYear() + this.attrs.maxYears);

    return (
      <div className="Form-group">
        <label for="TimeCapsuleCommunity-date">{t('community.field_date')}</label>
        <input
          id="TimeCapsuleCommunity-date"
          type="date"
          className="FormControl"
          min={dateInput(this.earliest())}
          max={dateInput(max)}
          value={this.date}
          oninput={(e: InputEvent) => (this.date = (e.target as HTMLInputElement).value)}
        />
        <p className="helpText">{t('community.field_date_help')}</p>
        {this.attrs.community?.letters ? <p className="helpText">{t('community.field_date_later')}</p> : null}
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(this.date);
    if (!match) return;
    this.loading = true;

    const c = this.attrs.community;
    const attributes: Record<string, unknown> = {
      title: this.title_,
      description: this.description,
      post: this.post,
      notifyEveryone: this.notifyEveryone,
    };
    if (!c || this.date !== this.initialDate) attributes.opensAt = localMidnight(+match[1], +match[2] - 1, +match[3]);

    (c ? Community.from(c) : app.store.createRecord('time-capsule-communities')).save(attributes).then(
      () => {
        this.hide();
        this.attrs.onsave();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
