<?php

namespace MediaWiki\Extension\GBCloudflarePurge;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

// edge purge on content change -> cloudflare purge api (post-send, never blocks the request)
class Hooks
{
    // cloudflare caps purge-by-url at 30 files per call
    private const BATCH_SIZE = 30;

    public static function onPageSaveComplete(
        $wikiPage,
        $user,
        $summary,
        $flags,
        $revisionRecord,
        $editResult
    ) {
        self::purgeTitle($wikiPage->getTitle());
    }

    public static function onPageDeleteComplete(
        $page,
        $deleter,
        $reason,
        $pageID,
        $deletedRev,
        $logEntry,
        $archivedRevisionCount
    ) {
        self::purgeTitle(Title::castFromPageIdentity($page));
    }

    public static function onPageMoveComplete(
        $old,
        $new,
        $user,
        $pageid,
        $redirid,
        $reason,
        $revision
    ) {
        self::purgeTitle(Title::newFromLinkTarget($old));
        self::purgeTitle(Title::newFromLinkTarget($new));
    }

    public static function onPageUndeleteComplete(
        $page,
        $restorer,
        $reason,
        $restoredRev,
        $logEntry,
        $restoredRevisionCount,
        $created,
        $restoredPageIds
    ) {
        self::purgeTitle(Title::castFromPageIdentity($page));
    }

    // ?action=purge should clear the edge too, not just the parser cache
    public static function onArticlePurge($wikiPage)
    {
        self::purgeTitle($wikiPage->getTitle());
    }

    // fulltext search is 1-10s on the small cloud sql instance -> let the
    // edge absorb anon search traffic. logged-in requests stay uncached via
    // the session check in sendCacheControl() + the cookie bypass cache rule.
    public static function onSpecialPageBeforeExecute($special, $subPage)
    {
        if ($special->getName() !== "Search") {
            return;
        }
        $special->getOutput()->setCdnMaxage(1800);
    }

    // file-cache hits return before ActionEntryPoint calls setCdnMaxage(),
    // leaving mCdnMaxage at 0 -> sendCacheControl() emits `private` and the
    // edge never caches the pages that matter. set the ttl here instead.
    public static function onHTMLFileCacheUseFileCache($context)
    {
        $context
            ->getOutput()
            ->setCdnMaxage(
                $context->getConfig()->get(MainConfigNames::CdnMaxAge)
            );
        return true;
    }

    public static function onFileUpload($file, $reupload, $hasDescription)
    {
        self::purgeTitle($file->getTitle());
    }

    private static function purgeTitle(?Title $title): void
    {
        if (!$title) {
            return;
        }

        // page url + action=history variant, same set mw purges internally
        $urls = MediaWikiServices::getInstance()
            ->getHtmlCacheUpdater()
            ->getUrls($title);
        self::purgeUrls($urls);
    }

    /**
     * Queue a post-send cloudflare purge for the given urls.
     * No-op when GBCloudflareZoneId / GBCloudflareApiToken are unset (dev).
     * @param string[] $urls
     */
    public static function purgeUrls(array $urls): void
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $zoneId = $config->get("GBCloudflareZoneId");
        $apiToken = $config->get("GBCloudflareApiToken");
        if (!$zoneId || !$apiToken || !$urls) {
            return;
        }

        $urls = array_values(array_unique($urls));

        DeferredUpdates::addCallableUpdate(function () use (
            $zoneId,
            $apiToken,
            $urls
        ) {
            $factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
            foreach (array_chunk($urls, self::BATCH_SIZE) as $batch) {
                $request = $factory->create(
                    "https://api.cloudflare.com/client/v4/zones/{$zoneId}/purge_cache",
                    [
                        "method" => "POST",
                        "postData" => json_encode(["files" => $batch]),
                        "timeout" => 10,
                        "connectTimeout" => 5,
                    ],
                    __METHOD__
                );
                $request->setHeader("Authorization", "Bearer {$apiToken}");
                $request->setHeader("Content-Type", "application/json");

                $status = $request->execute();
                if (!$status->isOK()) {
                    wfDebugLog(
                        "GBCloudflarePurge",
                        "purge failed (" .
                            $request->getStatus() .
                            "): " .
                            implode(", ", $batch)
                    );
                }
            }
        },
        DeferredUpdates::POSTSEND);
    }
}
