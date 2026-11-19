import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, dateInput, duration, localMidnight, presets, t, type CommunityRow, type Letter, type PageData, type Preset } from '../api';

interface Attrs extends IFormModalAttrs {
  community: CommunityRow | null;
  toCommunity: boolean;
  limits: PageData['limits'];
  canPost: boolean;
  onsealed: (letter: Letter) => void;
}

interface Picked {
  file: File;
  url: string;
}

/** Photos are shrunk in the browser first: phone pictures fit the upload limit, and the re-draw drops their metadata (GPS) before it leaves the device. */
async function shrink(file: File): Promise<Blob> {
  const MAX = 2000;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' } as any);
    const scale = Math.min(1, MAX / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
    return blob || file;
  } catch {
    return file;
  }
}

/**
 * Write a letter: the words, up to four photos, the day it opens (one-tap
 * presets or any date), who reads it then, and whether to keep a key. Or a
 * letter for the community capsule. Sealing plays the envelope closing.
 */
export default class SealModal extends FormModal<Attrs> {
  body = '';
  envelope = '';
  photos: Picked[] = [];
  presetList: Preset[] = [];
  preset = 'years_1';
  custom = '';
  visibility: 'private' | 'public' = 'private';
  post = false;
  peek = false;
  toCommunity = false;
  sealing = false;
  sealed = false;
  dragging = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const limits = this.limits();
    this.presetList = presets(limits.founded, limits.maxYears, limits.minHours);
    this.toCommunity = this.attrs.toCommunity && !!this.attrs.community;
    const tomorrow = new Date(Date.now() + 2 * 864e5);
    this.custom = dateInput(new Date(tomorrow.getFullYear() + 1, tomorrow.getMonth(), tomorrow.getDate()));
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    this.photos.forEach((p) => URL.revokeObjectURL(p.url));
  }

  limits(): PageData['limits'] {
    return this.attrs.limits;
  }

  className() {
    // 🚨 Constant: ModalManager adds `in` to this element by hand, and a changed class list makes
    // Mithril rewrite the attribute, dropping `in`. The dialog then fades early and its close
    // waits for a transitionend that never comes, leaving the backdrop over the page.
    return 'TimeCapsuleSealModal Modal--large';
  }

  title() {
    return this.toCommunity ? t('seal.title_community', { title: this.attrs.community!.title }) : t('seal.title');
  }

  /** The chosen opening day as a Date (local midnight), or null. */
  opensAt(): Date | null {
    if (this.toCommunity && this.attrs.community) return new Date(this.attrs.community.opensAt);
    if (this.preset === 'custom') {
      const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(this.custom);
      return match ? new Date(+match[1], +match[2] - 1, +match[3]) : null;
    }
    return this.presetList.find((p) => p.key === this.preset)?.date || null;
  }

  content() {
    if (this.sealing || this.sealed) return this.sealingView();

    const limits = this.limits();
    const opens = this.opensAt();

    return (
      <div className="Modal-body">
        {this.whereField()}
        {this.bodyField(limits.maxLength)}
        {this.photoField(limits.maxPhotos)}
        {this.envelopeField()}
        {!this.toCommunity && this.whenField(limits)}
        {opens && this.summary(opens)}
        {!this.toCommunity && this.afterField()}
        {this.toCommunity && (
          <p className="helpText TimeCapsuleSeal-communityNote">{t('seal.community_note', { date: opens ? opens.toLocaleDateString() : '' })}</p>
        )}

        <div className="Form-group">
          <Switch state={this.peek} onchange={(value: boolean) => (this.peek = value)}>
            {t('seal.peek')}
          </Switch>
          <p className="helpText">{this.peek ? t('seal.peek_on_help') : t('seal.peek_off_help')}</p>
        </div>

        <div className="Form-group TimeCapsuleSeal-submit">
          <Button
            type="submit"
            className="Button Button--primary Button--block"
            icon="fas fa-stamp"
            loading={this.loading}
            disabled={!this.body.trim() || !opens}
          >
            {t('seal.submit')}
          </Button>
          <p className="helpText">{t('seal.final')}</p>
        </div>
      </div>
    );
  }

  /** One choice in a row of chips (role="radio" inside a radiogroup). */
  chip(active: boolean, choose: () => void, children: Mithril.Children): Mithril.Children {
    return (
      <button type="button" role="radio" aria-checked={active} className={'TimeCapsuleChip' + (active ? ' is-active' : '')} onclick={choose}>
        {children}
      </button>
    );
  }

  /** My own letter, or one for the community capsule while it's sealed. */
  whereField(): Mithril.Children {
    const community = this.attrs.community;
    if (!community || community.openedAt) return null;

    return (
      <div className="TimeCapsuleSeal-where" role="radiogroup" aria-label={extractText(t('seal.where'))}>
        {this.chip(!this.toCommunity, () => (this.toCommunity = false), [<Icon name="fas fa-user" />, ' ', t('seal.where_mine')])}
        {this.chip(this.toCommunity, () => (this.toCommunity = true), [
          <Icon name="fas fa-box-archive" />,
          ' ',
          t('seal.where_community', { title: community.title }),
        ])}
      </div>
    );
  }

  bodyField(maxLength: number): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="TimeCapsule-body">{t('seal.body_label')}</label>
        <textarea
          id="TimeCapsule-body"
          className="FormControl TimeCapsuleSeal-body"
          rows={9}
          maxlength={maxLength}
          placeholder={extractText(t(this.toCommunity ? 'seal.body_placeholder_community' : 'seal.body_placeholder'))}
          value={this.body}
          oninput={(e: InputEvent) => (this.body = (e.target as HTMLTextAreaElement).value)}
        />
        {this.body.length > maxLength * 0.8 && <p className="helpText">{t('seal.length', { count: maxLength - this.body.length })}</p>}
      </div>
    );
  }

  envelopeField(): Mithril.Children {
    return (
      <div className="Form-group">
        <label for="TimeCapsule-envelope">{t('seal.envelope_label')}</label>
        <input
          id="TimeCapsule-envelope"
          className="FormControl"
          maxlength={80}
          placeholder={extractText(t('seal.envelope_placeholder'))}
          value={this.envelope}
          oninput={(e: InputEvent) => (this.envelope = (e.target as HTMLInputElement).value)}
        />
        <p className="helpText">{t('seal.envelope_help')}</p>
      </div>
    );
  }

  /** The open-date presets, or a date of the writer's own between the soonest and furthest allowed. */
  whenField(limits: { minHours: number; maxYears: number }): Mithril.Children {
    const min = new Date(Date.now() + 864e5 + limits.minHours * 36e5);
    const max = new Date();
    max.setFullYear(max.getFullYear() + limits.maxYears);

    return (
      <fieldset className="Form-group TimeCapsuleSeal-when">
        <legend>{t('seal.when_label')}</legend>
        <div className="TimeCapsuleChips" role="radiogroup">
          {this.presetList.map((p) => this.chip(this.preset === p.key, () => (this.preset = p.key), p.label))}
          {this.chip(this.preset === 'custom', () => (this.preset = 'custom'), [<Icon name="far fa-calendar" />, ' ', t('seal.preset.custom')])}
        </div>
        {this.preset === 'custom' && (
          <input
            type="date"
            className="FormControl TimeCapsuleSeal-date"
            aria-label={extractText(t('seal.preset.custom'))}
            min={dateInput(min)}
            max={dateInput(max)}
            value={this.custom}
            oninput={(e: InputEvent) => (this.custom = (e.target as HTMLInputElement).value)}
          />
        )}
      </fieldset>
    );
  }

  summary(opens: Date): Mithril.Children {
    const date = new Intl.DateTimeFormat(app.translator.getLocale() || undefined, {
      weekday: 'long',
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    }).format(opens);

    return (
      <p className="TimeCapsuleSeal-summary">
        <Icon name="fas fa-hourglass-half" /> {t('seal.opens_summary', { date, time: duration(new Date(), opens) })}
      </p>
    );
  }

  /** Who reads it when it opens, and whether it starts a discussion. */
  afterField(): Mithril.Children {
    return (
      <fieldset className="Form-group">
        <legend>{t('seal.after_label')}</legend>
        <div className="TimeCapsuleChips" role="radiogroup">
          {this.chip(this.visibility === 'private', () => (this.visibility = 'private'), [<Icon name="fas fa-lock" />, ' ', t('seal.private')])}
          {this.chip(this.visibility === 'public', () => (this.visibility = 'public'), [<Icon name="fas fa-users" />, ' ', t('seal.public')])}
        </div>
        <p className="helpText">{this.visibility === 'public' ? t('seal.public_help') : t('seal.private_help')}</p>
        {this.visibility === 'public' && this.attrs.canPost && (
          <Switch state={this.post} onchange={(value: boolean) => (this.post = value)}>
            {t('seal.post')}
          </Switch>
        )}
      </fieldset>
    );
  }

  /** Adds picked or dropped images, up to the limit. */
  addPhotos(files: FileList | null, max: number) {
    if (!files) return;
    for (const file of Array.from(files)) {
      if (this.photos.length >= max) break;
      if (!file.type.startsWith('image/')) continue;
      this.photos.push({ file, url: URL.createObjectURL(file) });
    }
    m.redraw();
  }

  photoThumb(photo: { file: File; url: string }, i: number): Mithril.Children {
    return (
      <div className="TimeCapsulePhotos-item">
        <img src={photo.url} alt="" />
        <Button
          className="Button Button--icon TimeCapsulePhotos-remove"
          icon="fas fa-xmark"
          aria-label={extractText(t('seal.photo_remove'))}
          onclick={() => {
            URL.revokeObjectURL(photo.url);
            this.photos.splice(i, 1);
          }}
        />
      </div>
    );
  }

  photoField(max: number): Mithril.Children {
    return (
      <div className="Form-group">
        <label>{t('seal.photos_label', { count: max })}</label>
        <div
          className={'TimeCapsulePhotos' + (this.dragging ? ' is-dragging' : '')}
          ondragover={(e: DragEvent) => {
            e.preventDefault();
            this.dragging = true;
          }}
          ondragleave={() => {
            this.dragging = false;
          }}
          ondrop={(e: DragEvent) => {
            e.preventDefault();
            this.dragging = false;
            this.addPhotos(e.dataTransfer?.files || null, max);
          }}
        >
          {this.photos.map((photo, i) => this.photoThumb(photo, i))}
          {this.photos.length < max && (
            <label className="TimeCapsulePhotos-add">
              <Icon name="fas fa-camera" />
              <span>{t('seal.photo_add')}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif"
                multiple
                onchange={(e: Event) => {
                  const input = e.target as HTMLInputElement;
                  this.addPhotos(input.files, max);
                  input.value = '';
                }}
              />
            </label>
          )}
        </div>
      </div>
    );
  }

  sealingView(): Mithril.Children {
    return (
      <div className="Modal-body TimeCapsuleSealing" aria-live="polite">
        <div className={'TimeCapsuleSealing-envelope' + (this.sealed ? ' is-sealed' : '')} aria-hidden="true">
          <span className="TimeCapsuleSealing-letter" />
          <span className="TimeCapsuleSealing-back" />
          <span className="TimeCapsuleSealing-flap" />
          <span className="TimeCapsuleSeal TimeCapsuleSealing-seal">
            <Icon name="fas fa-hourglass-half" />
          </span>
        </div>
        <p className="TimeCapsuleSealing-text">{this.sealed ? t('seal.done') : t('seal.sealing')}</p>
      </div>
    );
  }

  async onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const opens = this.opensAt();
    if (!this.body.trim() || !opens || this.loading) return;

    this.loading = true;
    m.redraw();

    const form = new FormData();
    form.append('body', this.body);
    form.append('envelope', this.envelope);
    form.append('peek', this.peek ? '1' : '0');
    if (this.toCommunity && this.attrs.community) {
      form.append('community', String(this.attrs.community.id));
    } else {
      form.append('opensAt', localMidnight(opens.getFullYear(), opens.getMonth(), opens.getDate()));
      form.append('visibility', this.visibility);
      form.append('post', this.visibility === 'public' && this.post ? '1' : '0');
    }
    for (const photo of this.photos) form.append('photos[]', await shrink(photo.file), 'photo.jpg');

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    this.sealing = true;
    m.redraw();

    call<{ capsule: Letter }>('POST', '', form).then(
      (r) => {
        this.sealed = true;
        m.redraw();
        window.setTimeout(
          () => {
            this.attrs.onsealed(r.capsule);
            this.hide();
          },
          reduced ? 600 : 1500
        );
      },
      () => {
        this.sealing = false;
        this.loading = false;
        m.redraw();
      }
    );
  }
}
