# Floxum wave, paused (resume after the weekly reset, 2026-11-26)

Ernest paused rewrites on 2026-11-19 ("Yes, pause rewrites"). This branch (`wip/floxum-wave`, local only) holds the unfinished 1.1.0 work. main is 1.0.1 (`e903f44`).

## The goal (from the Command Center)
The 9 plain letter endpoints were a pre-floxum watch item across 3+ files. Make them ONE `time-capsule-letters` resource with custom Endpoint actions (Connect's pattern) and zero PSR-15 controllers, keeping the seal:
- No other resource declares a relationship to letters. A test asks for `include=letters` (and other names) from discussions, users and `/api`, and gets a 400 with nothing included.
- The letter text is a field visible only when the letter may be read. A test checks that before opening it is never in Index or Show.
- Then: pre-floxum down to zero with no watch item spanning 3+ files, tests on all three databases, and the commit range to the Command Center.

## Where it stands (all uncommitted work, committed here as one WIP commit)
- `src/Api/LetterResource.php`:
  - `scope()` finds the viewer's own letters plus opened public or community ones; a moderator's Delete finds every letter.
  - Index (your opened letters, `page[offset]`), Show, Delete.
  - Custom actions: `page`, `seal`, `open`, `{id}/read`, `{id}/photos/{photo}`, `community/{community}`. Static paths come before `/{id}`, because FastRoute rejects a static route registered after the `/{id}` that would shadow it.
  - No constructor. Flarum lists endpoints on an instance it builds without one, so the actions and Present are made per request via `$context->api->getContainer()->make()`. That also stops the Vault's key memo from outliving a request.
- `src/Api/Action/*`: the old controllers as invokables taking the Context. `PhotoFile` is named so to avoid clashing with the `Photo` model.
- Opener posts discussions through `Flarum\Api\Client`. With `JsonApi`, the container looped: LetterResource → Open → Opener → JsonApi → LetterResource.
- Frontend:
  - `call()` points at `/time-capsule-letters`.
  - Show and Index are mapped back to the page's row shape by `letterOf()`.
  - Sealing goes to `/seal`; the page loads from `/page`.
- Tests:
  - Updated to the new paths.
  - `assertNowhere` now uses `/api` (Flarum 2's forum document). `/api/forum` was a 404, so the old sweep never checked the forum document.
  - New: `no_resource_includes_letters` and `index_and_show_carry_the_text_only_once_it_may_be_read`.
  - **39 tests pass on SQLite, MySQL and PostgreSQL.**
- Local UI (mapdev) is clean: full sweep, sealing, a due letter opening, and the letter view from JSON:API Show.
- README ("How the seal holds in the API") and CLAUDE.md are updated.

## Still to do
1. Rebase onto main (1.0.1 plus whatever lands by then), rebuild js/dist from a fresh `npm ci`, and rerun the tests on all three databases.
2. Run pre-floxum on the whole tree. A review was started and stopped before it reported. Watch especially for:
   - `container->make()` as service location;
   - the lazy WeakMaps;
   - anything that could expose a sealed letter's text: scope, field visibility, the Index query, eager loads.
3. Showcase seed: no change needed; it uses Vault and Photos directly.
4. Send the range to the Command Center, then release 1.1.0.

## Why pushes and releases start no workflows on this repo (investigated 2026-11-19)
- The workflows are all `active` (not disabled for inactivity), Actions is enabled with all actions allowed, workflow permissions match Kiosk's, there are no rulesets, and the `on:` blocks are identical to Kiosk's. No commit carries a skip-ci marker.
- GitHub does receive the pushes: the Packagist webhook got deliveries at 17:25, 18:05 and 18:34 UTC. But `actions/runs?event=push` and `?event=release` are 0 for the repo's whole life. `workflow_dispatch` works.
- Only this repo is affected. Kiosk and the six other repos created on 2026-11-10 (secret-santa, garage, hype-market, forum-pet, mural, slow-mail) all have push runs.
- Tried: every workflow disabled and re-enabled (`gh workflow disable/enable`), then this push as the test. Result is in the next note.
- Until it's fixed: run CI by hand (`gh workflow run ci.yml / backend.yml / draft-release.yml`) and the announcer with `gh workflow run publish-to-flarum.yml -f tag_name=X`.
- Result: still no runs after the re-enable (test push 5508d59 → 0 push runs). The credentials are the same as Kiosk's (HTTPS, gh OAuth token with `repo` and `workflow` scopes, osxkeychain), and the workflow files match Kiosk's byte for byte, apart from one extra Prettier step in ci.yml.
- Cause: on GitHub's side, for this repo only. Nothing in the repo, its settings or the push credentials explains it. If it still happens after Nov 26, ask GitHub Support (the repo receives push webhooks, but Actions never creates push or release runs). Don't recreate the repo: that loses the Packagist hook and history.
