import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { duration, longDate, progress, t, type Letter, type Person } from '../api';

declare const m: Mithril.Static;

export function Avatar({ person, size = 'medium' }: { person: Person | null | undefined; size?: string }): Mithril.Children {
  if (!person) return <span className={'Avatar Avatar--' + size + ' TimeCapsuleAvatar--gone'} />;
  return person.avatar ? (
    <img className={'Avatar Avatar--' + size} src={person.avatar} alt="" loading="lazy" />
  ) : (
    <span className={'Avatar Avatar--' + size} style={{ '--avatar-bg': 'var(--primary-color)' }}>
      {(person.name || '?').charAt(0).toUpperCase()}
    </span>
  );
}

export function personLink(person: Person | null | undefined): Mithril.Children {
  if (!person) return <span className="TimeCapsule-gone">{t('card.deleted_user')}</span>;
  return (
    <Link className="TimeCapsule-person" href={app.route('user', { username: person.slug })}>
      {person.name}
    </Link>
  );
}

/** The little facts under a card: sealed date, length, photos, who reads it. Nothing from inside the letter. */
function facts(letter: Letter): Mithril.Children {
  const items = [
    <li>
      <Icon name="fas fa-feather" /> {t('card.sealed_on', { date: longDate(letter.sealedAt) })}
    </li>,
    <li>
      <Icon name="fas fa-align-left" /> {t('card.words', { count: letter.words })}
    </li>,
  ];
  if (letter.photoCount)
    items.push(
      <li>
        <Icon name="fas fa-image" /> {t('card.photos', { count: letter.photoCount })}
      </li>
    );
  if (!letter.community)
    items.push(
      <li>
        <Icon name={letter.visibility === 'public' ? 'fas fa-users' : 'fas fa-lock'} />{' '}
        {letter.visibility === 'public' ? (letter.post ? t('card.public_post') : t('card.public')) : t('card.private')}
      </li>
    );
  return <ul className="TimeCapsuleCard-facts">{items}</ul>;
}

/** A sealed letter: an envelope, a wax seal, how long until it opens. */
export function SealedCard({ letter, onread, ondelete }: { letter: Letter; onread?: () => void; ondelete?: () => void }): Mithril.Children {
  const pct = Math.round(progress(letter) * 100);
  const now = new Date();
  const opens = new Date(letter.opensAt);
  const due = opens <= now;

  return (
    <article className="TimeCapsuleCard TimeCapsuleCard--sealed" aria-label={extractText(t('card.sealed_label'))}>
      <div className="TimeCapsuleEnvelope" aria-hidden="true">
        <span className="TimeCapsuleEnvelope-flap" />
        <span className="TimeCapsuleSeal">
          <Icon name="fas fa-hourglass-half" />
        </span>
      </div>
      <div className="TimeCapsuleCard-body">
        <h3 className="TimeCapsuleCard-title">{letter.envelope || t(letter.community ? 'card.community_letter' : 'card.untitled')}</h3>
        <p className="TimeCapsuleCard-opens">
          <strong>{due ? t('card.opening_now') : t('card.opens_in', { time: duration(now, opens) })}</strong>
          <span className="TimeCapsuleCard-date">{longDate(letter.opensAt)}</span>
        </p>
        <div
          className="TimeCapsuleProgress"
          role="progressbar"
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
          aria-label={extractText(t('card.progress', { percent: pct }))}
        >
          <span style={{ width: pct + '%' }} />
        </div>
        {facts(letter)}
        {(letter.peek || letter.canDelete) && (
          <div className="TimeCapsuleCard-actions">
            {letter.peek && onread ? (
              <Button className="Button Button--text" icon="fas fa-key" onclick={onread}>
                {t('card.reread')}
              </Button>
            ) : (
              <span className="TimeCapsuleCard-locked">
                <Icon name="fas fa-lock" /> {t('card.locked')}
              </span>
            )}
            {letter.canDelete && ondelete && (
              <Button
                className="Button Button--icon Button--link TimeCapsuleCard-delete"
                icon="far fa-trash-can"
                aria-label={extractText(t('card.delete'))}
                onclick={ondelete}
              />
            )}
          </div>
        )}
      </div>
    </article>
  );
}

/** An opened letter on the page: who wrote it, when, how long it waited, the start of it. */
export function OpenedCard({ letter }: { letter: Letter }): Mithril.Children {
  const href = app.route('time-capsule.letter', { id: letter.id });
  const waited = duration(new Date(letter.sealedAt), new Date(letter.openedAt || letter.opensAt));

  return (
    <article className={'TimeCapsuleCard TimeCapsuleCard--opened' + (letter.firstRead ? ' is-new' : '')}>
      <header className="TimeCapsuleCard-head">
        <Avatar person={letter.author} size="small" />
        <div>
          <div className="TimeCapsuleCard-who">{letter.mine ? t('card.you') : personLink(letter.author)}</div>
          <div className="TimeCapsuleCard-when">{t('card.waited', { date: longDate(letter.sealedAt), time: waited })}</div>
        </div>
        {letter.firstRead && <span className="TimeCapsuleBadge">{t('card.new')}</span>}
      </header>
      <Link href={href} className="TimeCapsuleCard-link">
        {letter.envelope && <h3 className="TimeCapsuleCard-title">{letter.envelope}</h3>}
        <p className="TimeCapsuleCard-excerpt">{letter.readable === false ? t('letter.unreadable') : letter.body}</p>
      </Link>
      {letter.photos && letter.photos.length > 0 && (
        <Link href={href} className="TimeCapsuleCard-photos" aria-label={extractText(t('card.photos', { count: letter.photos.length }))}>
          {letter.photos.slice(0, 4).map((photo) => (
            <img src={photo.url} alt="" loading="lazy" width={photo.width} height={photo.height} />
          ))}
        </Link>
      )}
      <footer className="TimeCapsuleCard-foot">
        <Link href={href} className="Button Button--text">
          <Icon name="fas fa-envelope-open-text" /> {t('card.read')}
        </Link>
        {letter.discussion && (
          <Link
            href={app.route('discussion', { id: letter.discussion.id + (letter.discussion.slug ? '-' + letter.discussion.slug : '') })}
            className="Button Button--text"
          >
            <Icon name="far fa-comments" /> {t('card.discussion')}
          </Link>
        )}
      </footer>
    </article>
  );
}
