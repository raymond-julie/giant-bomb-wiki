<?php

namespace MediaWiki\Extension\GBCloudflarePurge;

use MediaWiki\MainConfigNames;

// cdn ttl hooks -- purging lives in CloudflareEventRelayer
class Hooks
{
    // fulltext search is 1-10s on mysql -> let the edge absorb anon searches
    public static function onSpecialPageBeforeExecute($special, $subPage)
    {
        if ($special->getName() !== "Search") {
            return;
        }
        $special->getOutput()->setCdnMaxage(1800);
    }

    // file-cache hits skip ActionEntryPoint's setCdnMaxage() -> `private`
    // header, so the edge never caches them. set the ttl here instead
    public static function onHTMLFileCacheUseFileCache($context)
    {
        $context
            ->getOutput()
            ->setCdnMaxage(
                $context->getConfig()->get(MainConfigNames::CdnMaxAge)
            );
        return true;
    }
}
