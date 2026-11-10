import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { call, duration, errorText, longDate, t, type CommunityRow, type Letter, type PageData } from '../api';
import Countdown from './Countdown';
import { OpenedCard, SealedCard } from './Cards';
import LetterView from './LetterView';

/**
 * /time-capsule                  your letters, recently opened ones, the community countdown
 * /time-capsule/:id              one letter
 * /time-capsule/community/:id    a community capsule (its letters once open)
 *
 * Every view is one request. Sealed letters arrive as cards only: the API
 * never sends a sealed letter's words, so there is nothing here to hide.
 */

declare const flarum: any;
declare const m: Mithril.Static;

export let PageStructure: any;
export let IndexSidebar: any;

/** Core page parts, loaded through the registry before the page draws. */
export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

type Mode = 'index' | 'letter' | 'community';

export default class TimeCapsulePage extends Page {
  mode: Mode = 'index';
  id = 0;
  loading = true;
  failed: string | null = null;
  data: PageData | null = null;
  letter: Letter | null = null;
  community: { community: CommunityRow; letters: Letter[]; more: boolean } | null = null;
  loadingMore = false;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const route = this.attrs.routeName as string;
    this.mode = route === 'time-capsule.letter' ? 'letter' : route === 'time-capsule.community' ? 'community' : 'index';
    this.id = Number(m.route.param('id')) || 0;
    this.bodyClass = 'App--timeCapsule';
    app.setTitle(extractText(t('page.title')));
    app.setTitleCount(0);
    if (app.forum.attribute('timeCapsuleCanView')) this.load();
  }

  load(): Promise<void> {
    this.loading = true;
    const request =
      this.mode === 'letter'
        ? call<{ capsule: Letter }>('GET', '/' + this.id, undefined, true).then((r) => {
            this.letter = r.capsule;
            if (r.capsule.envelope) app.setTitle(r.capsule.envelope);
          })
        : this.mode === 'community'
          ? call<any>('GET', '/community/' + this.id, undefined, true).then((r) => {
              this.community = r;
              app.setTitle(r.community.title);
            })
          : call<PageData>('GET', '', undefined, true).then((r) => {
              this.data = r;
            });

    return request
      .catch((error) => {
        this.failed = errorText(error);
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="TimeCapsulePage" sidebar={() => <IndexSidebar />}>
        {!app.forum.attribute('timeCapsuleCanView') ? (
          this.denied()
        ) : this.loading ? (
          <LoadingIndicator />
        ) : this.failed ? (
          this.failure()
        ) : (
          this.content()
        )}
      </PageStructure>
    );
  }

  content(): Mithril.Children {
    if (this.mode === 'letter' && this.letter) return this.letterView(this.letter);
    if (this.mode === 'community' && this.community) return this.communityView();
    return this.indexView();
  }

  denied() {
    return (
      <div className="TimeCapsuleHero">
        <h2 className="TimeCapsuleHero-title">{t('page.title')}</h2>
        <p className="TimeCapsuleHero-intro">{t('page.intro')}</p>
        <p className="TimeCapsulePage-message">{app.session.user ? t('page.no_permission') : t('page.guest')}</p>
      </div>
    );
  }

  failure() {
    return (
      <div className="TimeCapsulePage-message">
        <p>{this.failed}</p>
        <Link className="Button" href={app.route('time-capsule')}>
          {t('page.back')}
        </Link>
      </div>
    );
  }

  back(): Mithril.Children {
    return (
      <Link className="TimeCapsulePage-back" href={app.route('time-capsule')}>
        <Icon name="fas fa-arrow-left" /> {t('page.back')}
      </Link>
    );
  }

  // ---- index ----

  indexView(): Mithril.Children {
    const data = this.data!;
    const sealed = data.mine.filter((l) => l.sealed).sort((a, b) => a.opensAt.localeCompare(b.opensAt));
    const opened = data.mine.filter((l) => !l.sealed);

    return (
      <div className="TimeCapsulePage-index">
        {this.hero(data)}
        {data.community ? this.communityHero(data.community, data) : data.can.community && this.startCommunity()}

        {app.session.user && (
          <section className="TimeCapsuleSection">
            <h3 className="TimeCapsuleSection-title">
              <Icon name="fas fa-envelope" /> {t('page.sealed_title')}
              {sealed.length > 0 && <span className="TimeCapsuleSection-count">{sealed.length}</span>}
            </h3>
            {sealed.length ? (
              <div className="TimeCapsuleGrid">
                {sealed.map((letter) =>
                  SealedCard({
                    letter,
                    onread: () => m.route.set(app.route('time-capsule.letter', { id: letter.id })),
                    ondelete: () => this.remove(letter),
                  })
                )}
              </div>
            ) : (
              <div className="TimeCapsuleEmpty">
                <Icon name="far fa-envelope" />
                <p>{t('page.sealed_empty')}</p>
                {data.can.create && (
                  <Button className="Button" icon="fas fa-feather" onclick={() => this.compose()}>
                    {t('page.write_first')}
                  </Button>
                )}
              </div>
            )}
          </section>
        )}

        {opened.length > 0 && (
          <section className="TimeCapsuleSection">
            <h3 className="TimeCapsuleSection-title">
              <Icon name="fas fa-envelope-open-text" /> {t('page.opened_mine_title')}
            </h3>
            <div className="TimeCapsuleGrid">{opened.map((letter) => OpenedCard({ letter }))}</div>
          </section>
        )}

        <section className="TimeCapsuleSection">
          <h3 className="TimeCapsuleSection-title">
            <Icon name="fas fa-door-open" /> {t('page.recent_title')}
          </h3>
          {data.recent.length ? (
            <div className="TimeCapsuleGrid">{data.recent.map((letter) => OpenedCard({ letter }))}</div>
          ) : (
            <p className="TimeCapsuleSection-empty">{t('page.recent_empty')}</p>
          )}
        </section>

        {data.opened.length > 0 && (
          <section className="TimeCapsuleSection">
            <h3 className="TimeCapsuleSection-title">
              <Icon name="fas fa-box-open" /> {t('page.community_past_title')}
            </h3>
            <ul className="TimeCapsulePast">
              {data.opened.map((c) => (
                <li>
                  <Link href={app.route('time-capsule.community', { id: c.id })}>
                    <strong>{c.title}</strong>
                    <span>{t('community.opened_summary', { date: longDate(c.openedAt!), letters: c.letters, members: c.members })}</span>
                  </Link>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    );
  }

  hero(data: PageData): Mithril.Children {
    const stats = [
      t('stats.sealed', { count: data.stats.sealed }),
      t('stats.opened', { count: data.stats.opened }),
      data.stats.nextOpen && new Date(data.stats.nextOpen) > new Date()
        ? t('stats.next', { time: duration(new Date(), new Date(data.stats.nextOpen)) })
        : null,
    ].filter(Boolean);

    return (
      <header className="TimeCapsuleHero">
        <div className="TimeCapsuleHero-text">
          <h2 className="TimeCapsuleHero-title">{t('page.title')}</h2>
          <p className="TimeCapsuleHero-intro">{t('page.intro')}</p>
          <ul className="TimeCapsuleStats">
            {stats.map((s) => (
              <li>{s}</li>
            ))}
          </ul>
        </div>
        <div className="TimeCapsuleHero-action">
          {data.can.create ? (
            <Button className="Button Button--primary TimeCapsuleHero-write" icon="fas fa-feather" onclick={() => this.compose()}>
              {t('page.write')}
            </Button>
          ) : (
            !app.session.user && (
              <Button
                className="Button Button--primary"
                onclick={() => app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'))}
              >
                {t('page.log_in_to_write')}
              </Button>
            )
          )}
        </div>
      </header>
    );
  }

  communityHero(c: CommunityRow, data?: PageData): Mithril.Children {
    return (
      <section className="TimeCapsuleCommunity" aria-labelledby="TimeCapsuleCommunity-title">
        <div className="TimeCapsuleCommunity-label">
          <Icon name="fas fa-box-archive" /> {t('community.label')}
        </div>
        <h3 className="TimeCapsuleCommunity-title" id="TimeCapsuleCommunity-title">
          {this.mode === 'index' ? <Link href={app.route('time-capsule.community', { id: c.id })}>{c.title}</Link> : c.title}
        </h3>
        {c.description && <p className="TimeCapsuleCommunity-description">{c.description}</p>}
        <Countdown to={c.opensAt} ondone={() => this.load()} />
        <p className="TimeCapsuleCommunity-when">
          {t('community.opens_on', { date: longDate(c.opensAt) })} · {t('community.counts', { letters: c.letters, members: c.members })}
          {c.mine > 0 && <> · {t('community.yours', { count: c.mine })}</>}
        </p>
        <div className="TimeCapsuleCommunity-actions">
          {(data ? data.can.create : !!app.session.user) && (
            <Button className="Button Button--primary" icon="fas fa-feather" onclick={() => this.compose(c)}>
              {c.mine ? t('community.add_another') : t('community.add')}
            </Button>
          )}
          {c.canManage && (
            <>
              <Button className="Button" icon="fas fa-pen" onclick={() => this.manage(c)}>
                {t('community.edit')}
              </Button>
              <Button className="Button Button--link TimeCapsuleCommunity-delete" icon="far fa-trash-can" onclick={() => this.removeCommunity(c)}>
                {t('community.delete')}
              </Button>
            </>
          )}
        </div>
      </section>
    );
  }

  startCommunity(): Mithril.Children {
    return (
      <section className="TimeCapsuleCommunity TimeCapsuleCommunity--empty">
        <div className="TimeCapsuleCommunity-label">
          <Icon name="fas fa-box-archive" /> {t('community.label')}
        </div>
        <p>{t('community.none')}</p>
        <Button className="Button" icon="fas fa-plus" onclick={() => this.manage(null)}>
          {t('community.start')}
        </Button>
      </section>
    );
  }

  // ---- one letter / one community capsule ----

  letterView(letter: Letter): Mithril.Children {
    return (
      <div className="TimeCapsulePage-letter">
        {this.back()}
        <LetterView letter={letter} ondelete={() => this.remove(letter)} />
      </div>
    );
  }

  communityView(): Mithril.Children {
    const { community: c, letters, more } = this.community!;

    return (
      <div className="TimeCapsulePage-communityView">
        {this.back()}
        {c.openedAt ? (
          <header className="TimeCapsuleCommunity TimeCapsuleCommunity--opened">
            <div className="TimeCapsuleCommunity-label">
              <Icon name="fas fa-box-open" /> {t('community.label')}
            </div>
            <h2 className="TimeCapsuleCommunity-title">{c.title}</h2>
            {c.description && <p className="TimeCapsuleCommunity-description">{c.description}</p>}
            <p className="TimeCapsuleCommunity-when">
              {t('community.opened_summary', { date: longDate(c.openedAt), letters: c.letters, members: c.members })} ·{' '}
              {t('community.waited', { time: duration(new Date(c.createdAt), new Date(c.openedAt)) })}
            </p>
            {c.discussionId && (
              <Link className="Button" href={app.route('discussion', { id: c.discussionId })}>
                <Icon name="far fa-comments" /> {t('letter.discussion')}
              </Link>
            )}
          </header>
        ) : (
          this.communityHero(c)
        )}

        {c.openedAt ? (
          <div className="TimeCapsuleLetters">
            {letters.length ? (
              letters.map((letter) => <LetterView letter={letter} compact ondelete={() => this.remove(letter)} />)
            ) : (
              <p className="TimeCapsuleSection-empty">{t('community.empty')}</p>
            )}
            {more && (
              <Button className="Button TimeCapsuleLetters-more" loading={this.loadingMore} onclick={() => this.more()}>
                {t('community.more')}
              </Button>
            )}
          </div>
        ) : (
          letters.length > 0 && (
            <section className="TimeCapsuleSection">
              <h3 className="TimeCapsuleSection-title">
                <Icon name="fas fa-envelope" /> {t('community.your_letters')}
              </h3>
              <div className="TimeCapsuleGrid">
                {letters.map((letter) =>
                  SealedCard({
                    letter,
                    onread: () => m.route.set(app.route('time-capsule.letter', { id: letter.id })),
                    ondelete: () => this.remove(letter),
                  })
                )}
              </div>
            </section>
          )
        )}
      </div>
    );
  }

  more() {
    if (!this.community) return;
    this.loadingMore = true;
    call<any>('GET', `/community/${this.id}?offset=${this.community.letters.length}`)
      .then((r) => {
        this.community!.letters.push(...r.letters);
        this.community!.more = r.more;
      })
      .finally(() => {
        this.loadingMore = false;
        m.redraw();
      });
  }

  // ---- actions ----

  compose(community: CommunityRow | null = null) {
    const data = this.data;
    app.modal.show(() => import('./SealModal'), {
      community: community || data?.community || null,
      toCommunity: !!community,
      limits: data?.limits,
      canPost: data?.can.post ?? false,
      onsealed: (letter: Letter) => {
        if (this.data) {
          this.data.mine.unshift(letter);
          this.data.stats.sealed++;
          if (letter.community && this.data.community) {
            this.data.community.letters++;
            if (!this.data.community.mine) this.data.community.members++;
            this.data.community.mine++;
          }
        } else if (this.community && letter.community) {
          this.community.letters.push(letter);
          this.community.community.letters++;
          this.community.community.mine++;
        }
        m.redraw();
      },
    });
  }

  manage(community: CommunityRow | null) {
    app.modal.show(() => import('./CommunityModal'), {
      community,
      maxYears: this.data?.limits.maxYears ?? 25,
      onsave: () => this.load(),
    });
  }

  remove(letter: Letter) {
    const key = letter.sealed ? 'card.delete_sealed_confirm' : 'card.delete_confirm';
    if (!confirm(extractText(t(key)))) return;

    call('DELETE', '/' + letter.id).then(() => {
      app.alerts.show({ type: 'success' }, t('card.deleted'));
      if (this.mode === 'letter') {
        m.route.set(app.route('time-capsule'));
        return;
      }
      if (this.data) {
        this.data.mine = this.data.mine.filter((l) => l.id !== letter.id);
        this.data.recent = this.data.recent.filter((l) => l.id !== letter.id);
      }
      if (this.community) this.community.letters = this.community.letters.filter((l) => l.id !== letter.id);
      m.redraw();
    });
  }

  removeCommunity(c: CommunityRow) {
    if (!confirm(extractText(t('community.delete_confirm', { count: c.letters })))) return;
    call('DELETE', '/community/' + c.id).then(() => {
      if (this.mode === 'community') m.route.set(app.route('time-capsule'));
      else this.load();
    });
  }
}
