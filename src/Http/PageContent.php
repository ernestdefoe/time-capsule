<?php

namespace ErnestDefoe\TimeCapsule\Http;

use Flarum\Frontend\Document;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The /time-capsule pages as the server sends them: a title and a canonical
 * URL. No letters: those need JavaScript and the API's checks. A single
 * letter's page is noindex: it was written to a person, not to search engines.
 */
class PageContent
{
    public function __construct(private readonly UrlGenerator $url, private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $document->title = (string) $this->translator->trans('ernestdefoe-time-capsule.forum.page.title');
        $document->canonicalUrl = $this->url->to('forum')->route('time-capsule');

        if ($request->getQueryParams()['id'] ?? null) {
            $document->meta['robots'] = 'noindex, nofollow';
        }

        return $document;
    }
}
