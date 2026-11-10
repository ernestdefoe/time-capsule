import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { duration, longDate, t, type Letter, type PhotoRow } from '../api';
import { Avatar, personLink } from './Cards';

declare const m: Mithril.Static;

interface Attrs extends ComponentAttrs {
  letter: Letter;
  ondelete?: () => void;
  compact?: boolean;
}

/**
 * One letter, in full, on paper. The writer's first read plays the
 * unsealing: the flap lifts, the seal breaks, the letter slides out (skipped
 * for reduced motion). Text is rendered as text: a letter never becomes HTML.
 */
export default class LetterView extends Component<Attrs> {
  private unsealing = false;
  private photo: PhotoRow | null = null;
  private keyHandler = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && this.photo) {
      this.photo = null;
      m.redraw();
    }
  };

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.unsealing = !!this.attrs.letter.firstRead && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    document.addEventListener('keydown', this.keyHandler);
    if (this.unsealing) {
      window.setTimeout(() => {
        this.unsealing = false;
        m.redraw();
      }, 1900);
    }
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    document.removeEventListener('keydown', this.keyHandler);
  }

  view() {
    const letter = this.attrs.letter;

    if (letter.sealed && letter.body === undefined) {
      return (
        <div className="TimeCapsuleLetter TimeCapsuleLetter--sealed">
          <Icon name="fas fa-lock" />
          <p>{t('letter.still_sealed', { date: longDate(letter.opensAt) })}</p>
        </div>
      );
    }

    return (
      <div className={'TimeCapsuleLetterWrap' + (this.unsealing ? ' is-unsealing' : '')}>
        {this.unsealing && (
          <div className="TimeCapsuleUnseal" aria-hidden="true">
            <span className="TimeCapsuleUnseal-back" />
            <span className="TimeCapsuleUnseal-flap" />
            <span className="TimeCapsuleSeal TimeCapsuleUnseal-seal">
              <Icon name="fas fa-hourglass-half" />
            </span>
          </div>
        )}
        <article className={'TimeCapsuleLetter' + (this.attrs.compact ? ' TimeCapsuleLetter--compact' : '')}>
          <header className="TimeCapsuleLetter-head">
            <Avatar person={letter.author} />
            <div className="TimeCapsuleLetter-meta">
              <div className="TimeCapsuleLetter-from">
                {letter.mine ? t('letter.from_you') : t('letter.from', { name: personLink(letter.author) })}
              </div>
              <div className="TimeCapsuleLetter-dates">
                {letter.sealed
                  ? t('letter.peek_dates', { sealed: longDate(letter.sealedAt), opens: longDate(letter.opensAt) })
                  : t('letter.dates', {
                      sealed: longDate(letter.sealedAt),
                      opened: longDate(letter.openedAt || letter.opensAt),
                      time: duration(new Date(letter.sealedAt), new Date(letter.openedAt || letter.opensAt)),
                    })}
              </div>
            </div>
          </header>
          {letter.sealed && (
            <p className="TimeCapsuleLetter-peek">
              <Icon name="fas fa-key" /> {t('letter.peek_note')}
            </p>
          )}
          {letter.envelope && <h2 className="TimeCapsuleLetter-title">{letter.envelope}</h2>}
          <div className="TimeCapsuleLetter-body">
            {letter.readable === false ? (
              <p className="TimeCapsuleLetter-unreadable">{t('letter.unreadable')}</p>
            ) : (
              this.paragraphs(letter.body || '')
            )}
          </div>
          {letter.photos && letter.photos.length > 0 && (
            <div className={'TimeCapsuleLetter-photos TimeCapsuleLetter-photos--' + Math.min(letter.photos.length, 4)}>
              {letter.photos.map((photo, i) => (
                <button
                  type="button"
                  className="TimeCapsuleLetter-photo"
                  aria-label={extractText(t('letter.photo', { number: i + 1 }))}
                  onclick={() => (this.photo = photo)}
                >
                  <img src={photo.url} alt="" loading="lazy" width={photo.width} height={photo.height} />
                </button>
              ))}
            </div>
          )}
          <footer className="TimeCapsuleLetter-foot">
            {letter.discussion && (
              <Link
                className="Button"
                href={app.route('discussion', { id: letter.discussion.id + (letter.discussion.slug ? '-' + letter.discussion.slug : '') })}
              >
                <Icon name="far fa-comments" /> {t('letter.discussion')}
              </Link>
            )}
            {letter.canDelete && this.attrs.ondelete && (
              <Button className="Button Button--link TimeCapsuleLetter-delete" icon="far fa-trash-can" onclick={this.attrs.ondelete}>
                {t('letter.delete')}
              </Button>
            )}
          </footer>
        </article>
        {this.photo && (
          <div
            className="TimeCapsuleLightbox"
            role="dialog"
            aria-modal="true"
            aria-label={extractText(t('letter.photo_view'))}
            onclick={() => (this.photo = null)}
          >
            <img src={this.photo.url} alt="" />
            <Button className="Button Button--icon TimeCapsuleLightbox-close" icon="fas fa-xmark" aria-label={extractText(t('letter.close'))} />
          </div>
        )}
      </div>
    );
  }

  /** Blank lines make paragraphs; single line breaks stay (white-space: pre-line). */
  private paragraphs(body: string): Mithril.Children {
    return body
      .split(/\n{2,}/)
      .filter((p) => p.trim() !== '')
      .map((p) => <p>{p}</p>);
  }
}
