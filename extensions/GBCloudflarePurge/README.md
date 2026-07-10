# GBCloudflarePurge

Purges the Cloudflare edge cache when wiki content changes (save, delete, move,
undelete, `?action=purge`, file upload). Purge calls run post-send via
DeferredUpdates so they never block the editor's request. The extension is a
no-op when the Cloudflare credentials are unset, so it's safe to load in dev.

## Configuration

Set via env in `config/LocalSettings.php`:

| Variable | Purpose |
|---|---|
| `CLOUDFLARE_ZONE_ID` | Zone id for giantbomb.com (dashboard → Overview) |
| `CLOUDFLARE_API_TOKEN` | API token with `Zone → Cache Purge → Purge` permission only |
| `CDN_MAX_AGE` | `$wgCdnMaxAge` in seconds (default 3600) |

## Required Cloudflare zone setup

MediaWiki only emits `Cache-Control: s-maxage=...` for anonymous page views
(that's what `$wgUseCdn = true` does), but Cloudflare does not cache HTML by
default and **ignores `Vary: Cookie`** for non-image content. Both rules below
are required — the second one is what keeps logged-in users from being served
anonymous cached pages.

1. **Cache Rule — make wiki HTML cacheable**
   - When: URI Path starts with `/wiki/`
   - Then: Eligible for cache, "Respect origin" edge TTL (honors `s-maxage`)

2. **Cache Rule — bypass for sessions (MUST be ordered AFTER rule 1: Cloudflare
   cache rules are last-match-wins, so the bypass only sticks if it comes later
   in the list than the eligible-for-cache rule)**
   - When: URI Path starts with `/wiki/` AND the Cookie header contains any of:
     - `mwSession` (core session, `$wgSessionName`; as a substring this also
       matches GbSessionProvider's `mwSessionCookieName` session cookie)
     - `gb_wiki` (GBN JWT auth cookie read by GbSessionProvider)
     - `UserID` or `Token` (core remember-me cookies, unprefixed since
       `$wgCookiePrefix = ""`)
   - Then: Bypass cache
   - Expression form:
     `starts_with(http.request.uri.path, "/wiki/") and (http.cookie contains "mwSession" or http.cookie contains "gb_wiki" or http.cookie contains "UserID" or http.cookie contains "Token")`

   Missing any of these means a logged-in user can be served the anonymous
   cached copy of a page (Cloudflare ignores `Vary: Cookie`).

Do not enable "Cache Everything" zone-wide; `s-maxage=0` responses (edit forms,
special pages, logged-in views) must stay uncached.

## File cache interaction

Prod also runs `$wgUseFileCache`. File-cache hits are served before core sets
the CDN TTL, so without help they'd emit `Cache-Control: private` and the edge
would never cache real page views. This extension's `HTMLFileCache::useFileCache`
hook sets the TTL on that path, so both layers emit `s-maxage` consistently.

## What is NOT purged

Only the changed page (+ its `action=history` URL, and both titles on a move)
is purged. Pages that *transclude* an edited template, or that render SMW
`#ask` results affected by an edit, are not purged at the edge — they stay
stale until `$wgCdnMaxAge` expires. That's why the TTL is a modest 1 hour
rather than matching the 7-day parser cache.

## Verifying

```sh
# anon view should show s-maxage and (after two hits) an edge HIT
curl -sI https://giantbomb.com/wiki/Main_Page | grep -i 'cache-control\|cf-cache-status'

# after editing a page, its cf-cache-status should return to MISS/EXPIRED
```
