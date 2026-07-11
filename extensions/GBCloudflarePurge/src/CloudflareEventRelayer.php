<?php

namespace MediaWiki\Extension\GBCloudflarePurge;

use MediaWiki\MediaWikiServices;
use Wikimedia\EventRelayer\EventRelayer;

// forwards core's cdn-url-purges channel (edits, template fan-out jobs,
// file/thumb urls on reupload) to the cloudflare purge api. no-op without creds
class CloudflareEventRelayer extends EventRelayer
{
    // cloudflare caps purge-by-url at 30 files per call
    private const BATCH_SIZE = 30;

    protected function doNotify($channel, array $events)
    {
        $config = MediaWikiServices::getInstance()->getMainConfig();
        $zoneId = $config->get("GBCloudflareZoneId");
        $apiToken = $config->get("GBCloudflareApiToken");

        $urls = array_values(
            array_unique(array_filter(array_column($events, "url")))
        );
        if (!$zoneId || !$apiToken || !$urls) {
            return true;
        }

        $factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
        $ok = true;
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
                $ok = false;
                wfDebugLog(
                    "GBCloudflarePurge",
                    "purge failed (" .
                        $request->getStatus() .
                        "): " .
                        implode(", ", $batch)
                );
            }
        }
        return $ok;
    }
}
