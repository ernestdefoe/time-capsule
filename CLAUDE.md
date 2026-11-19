# Time Capsule: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **The seal is the product.** A sealed letter's words and photos are never in any response, to anyone, admins included; the writer only when they chose "let me reread it". `Access::canRead()` is the one gate and `Present::capsule()` the one place a letter is decrypted for output. Never add another path that reads `sealed_body` or a photo file for a response.
- **No JSON:API resource for letters.** Letters are served by our plain endpoints only, so no `include` can ever pull one in. Notification subjects are Users (a subject model without an API resource 500s every notification list); the letter id rides in the data.
- **Encryption at rest** (`Vault`): XChaCha20-Poly1305 via libsodium, random nonce per letter/photo, the writer's id as associated data (a row moved to another user no longer decrypts). The key is NEVER in the database: `config.php` `time-capsule.key` (base64, 32 bytes) or `key` on the extension's private disk `ernestdefoe-time-capsule` (storage/time-capsule, `Extend\Filesystem`; photos go on it too, never native file calls), created once under a cache lock so two processes can't write different keys (the loser gets `KeyBusy`, 503 "try again", never a wait in the request). Lose the key and every sealed letter is lost: the admin page says to back up `storage/time-capsule/`.
- **The key is only ever created while there are no letters.** Letters but no key = the key was lost: sealing stops (`KeyMissing`, 503) and logs critical; reading returns unreadable, never throws.
- **GDPR export:** letter text only when the member exports their own data (`Gdpr\ExportActor`, noted from flarum/gdpr's `Exporting` event). A moderator's export of someone else gets the cards only.
- Once a community capsule has letters, its date can only move later (moving it earlier would open everyone's letters early).
- Photos: shrunk in the browser (canvas, drops EXIF/GPS, fits `post_max_size`), capped at 16 MP (less if memory_limit is tight) from the header, every photo checked before any is stored, re-encoded to WebP on the server, encrypted into `storage/time-capsule/photos`. Never in `public/`. `PhotoController` serves them with `Cache-Control: private`. Deleting letters goes through `Photos::deleteCapsules()` so files go too; a user's letters go on `Deleting` (before the foreign keys take the rows).
- **Opening is idempotent** (`Opener`): opening is a conditional UPDATE on `opened_at IS NULL` and only the winner notifies; the writer's notification is one blueprint per letter (sync never repeats it) and `notified_at` marks it; a discussion is claimed with `posted_at IS NULL` and released (3 tries) if posting throws; a community's notifications go in chunks behind `notify_cursor`, each chunk a `sync()` of its own blueprint (`after` = the chunk's start in its data), so chunks never touch each other's notifications and other extensions' `beforeSending` hooks run; recipients are members who may view time capsules. Checks (permissions, tag, decryption) run BEFORE the post claim; every letter/community in a run is guarded so one throw can't stop the rest. The scheduler runs it every minute; page loads open due letters they show (forums without a scheduler).
- Discussions for opened letters are started through Flarum's API as the writer on the day (process() skips `startDiscussion` and the tag's permission: both are asked by hand). A writer who can't post any more just doesn't get one.
- Tags is optional: `DiscussionTags` (no tags) is replaced by `Tags\TaggedDiscussionTags` through `Extend\Conditional`, so no Tags class is touched without Tags. GDPR's data type uses `resolve()` because flarum/gdpr builds types with `new`, not the container.
- Frontend registration is declarative (`js/src/*/extend.tsx`); extend.php uses string setting keys, never our own constants, at load time.
- Letters are rendered as text (Mithril text nodes, `white-space: pre-line`), never HTML.
- One community capsule is sealed at a time.

## Testing

- `composer test:setup && composer test` (integration, SQLite or MySQL, with a prefix), `composer analyse:phpstan`.
- `tests/integration/api/SealingTest.php` is the seal's proof: every endpoint, every viewer. Add any new endpoint or include to `assertNowhere()`.
- `cd js && npm ci && npm run build && npm run format-check`.
