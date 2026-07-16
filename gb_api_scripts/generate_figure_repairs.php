<?php
// generate_figure_repairs.php — regenerate bodies for every legacy entry with
// <image> embeds (lost float/size/caption) or <table> (forced 100% width).
// Selection comes straight from the legacy db; guid -> wiki title via the
// wiki's own Has_guid data. Same JSONL contract as the truncation repair:
// apply with apply_body_repairs.php --summary "Restore image alignment, sizes
// and captions from legacy source". Pages with human (non-bot) revisions land
// in <out>.review, never auto-applied.
//
// Runs in the dev container (wiki db via "db", legacy via
// host.docker.internal:3307 + LEGACY_PW env).
//   php generate_figure_repairs.php --out figure-repairs.jsonl \
//       [--limit=N] [--titles=<file> --refetch-api=<api.php>]
// NB: getopt optional values need "=" -- "--limit 25" silently means no limit
// prod pass: regenerate with --refetch-api https://giantbomb.com/wiki/api.php
// so hashes and editor checks come from the LIVE wiki (never reuse local jsonl).

require_once __DIR__ . "/libs/converter.php";

$opts = getopt("", ["out:", "limit::", "titles::", "refetch-api::"]);
$outFile = $opts["out"] ?? null;
if (!$outFile) {
    fwrite(STDERR, "--out required\n");
    exit(1);
}
$limit = (int) ($opts["limit"] ?? 0);
$refetchApi = $opts["refetch-api"] ?? null;
$onlyTitles = null;
if (isset($opts["titles"])) {
    $onlyTitles = array_flip(array_filter(array_map("trim", file($opts["titles"]))));
}

$TABLES = [3000 => "wiki_accessory", 3005 => "wiki_character", 3010 => "wiki_company", 3015 => "wiki_concept", 3020 => "wiki_game_dlc", 3025 => "wiki_franchise", 3030 => "wiki_game", 3032 => "wiki_game_theme", 3035 => "wiki_location", 3040 => "wiki_person", 3045 => "wiki_platform", 3055 => "wiki_thing", 3060 => "wiki_game_genre"];
$BOT_ACTORS = ["Giantbomb", "Maintenance script"];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$wiki = mysqli_connect("db", getenv("MARIADB_USER"), getenv("MARIADB_PASSWORD"), getenv("MARIADB_DATABASE") ?: "wiki_db143");
$wiki->set_charset("utf8mb4");
$legacy = mysqli_connect("host.docker.internal", "root", getenv("LEGACY_PW"), "giantbomb", 3307);
$legacy->set_charset("utf8mb4");

// fresh converter per entry — instances carry DOM state between convert() calls
$rcConv = new ReflectionClass(HtmlToMediaWikiConverter::class);
libxml_use_internal_errors(true);

// preload guid -> ns0 title once: per-row COALESCE(o_blob,o_hash) lookups can't
// use an index and full-scan smw_di_blob (~11s each). guids are short -> o_hash.
fwrite(STDERR, "preloading Has_guid map...\n");
$guidMap = [];
$pid = $wiki->query("SELECT smw_id FROM smw_object_ids WHERE smw_title = 'Has_guid' AND smw_namespace = 102")->fetch_row()[0];
$gres = $wiki->query(
    "SELECT COALESCE(b.o_blob, b.o_hash), s.smw_title FROM smw_di_blob b
     JOIN smw_object_ids s ON b.s_id = s.smw_id
     WHERE b.p_id = " . (int) $pid . " AND s.smw_namespace = 0",
    MYSQLI_USE_RESULT
);
while ($g = $gres->fetch_row()) {
    $guidMap[$g[0]] = $g[1];
}
$gres->close();
fwrite(STDERR, count($guidMap) . " guids loaded\n");
$lookupGuid = function ($guid) use (&$guidMap) {
    return $guidMap[$guid] ?? null;
};
$resolver = function ($guid) use ($lookupGuid) {
    return $lookupGuid($guid) ?? false; // no wiki page -> plain display text
};
// media.giantbomb.com embeds carry stale paths; the image table has the real ones.
// own connection: the main loop holds an unbuffered result on $legacy, which
// blocks any other statement on that connection
$legacyImg = mysqli_connect("host.docker.internal", "root", getenv("LEGACY_PW"), "giantbomb", 3307);
$legacyImg->set_charset("utf8mb4");
$imgStmt = $legacyImg->prepare("SELECT name, path, deleted FROM image WHERE id = ?");
$imageLookup = function ($id) use ($imgStmt) {
    $imgStmt->bind_param("i", $id);
    $imgStmt->execute();
    return $imgStmt->get_result()->fetch_assoc();
};

$makeConverter = function () use ($rcConv, $resolver, $imageLookup) {
    $c = $rcConv->newInstanceWithoutConstructor();
    $p = $rcConv->getProperty("dom");
    $p->setAccessible(true);
    $p->setValue($c, new DOMDocument("1.0", "UTF-8"));
    $c->setWikiPageTitleResolver($resolver);
    $c->setImageLookup($imageLookup);
    return $c;
};

function apiFetchPage(string $api, string $title): ?array
{
    $url = $api . "?" . http_build_query([
        "action" => "query", "format" => "json", "titles" => $title,
        "prop" => "revisions|contributors", "rvslots" => "main",
        "rvprop" => "content", "pclimit" => "max",
    ]);
    $headers = "User-Agent: gb-wiki-repair/1.0\r\n";
    if (getenv("MW_SK")) {
        // cloudflare blocks api.php; MW_SK doubles as the waf bypass token
        $headers .= "X-Repair-Token: " . getenv("MW_SK") . "\r\n";
    }
    $ctx = stream_context_create(["http" => ["timeout" => 30, "header" => $headers]]);
    $raw = @file_get_contents($url, false, $ctx);
    $data = $raw ? json_decode($raw, true) : null;
    $pages = $data["query"]["pages"] ?? [];
    $page = is_array($pages) && $pages ? reset($pages) : null;
    $text = $page["revisions"][0]["slots"]["main"]["*"] ?? ($page["revisions"][0]["slots"]["main"]["content"] ?? null);
    if ($text === null) {
        return null;
    }
    $editors = array_column($page["contributors"] ?? [], "name");
    if (($page["anoncontributors"] ?? 0) > 0) {
        $editors[] = "(anonymous)";
    }
    return ["text" => $text, "editors" => $editors];
}

$out = fopen($outFile, "w");
$review = fopen($outFile . ".review", "w");
$n = ["written" => 0, "review" => 0, "unresolved_guid" => 0, "missing_page" => 0, "errors" => 0, "figures" => 0, "table_only" => 0];
$done = 0;

foreach ($TABLES as $typeId => $table) {
    // unbuffered: keep 200MB of blobs out of memory (13k rows across tables)
    $res = $legacy->query(
        "SELECT id, description FROM `$table`
         WHERE description LIKE '%<image %' OR description LIKE '%<table%'",
        MYSQLI_USE_RESULT
    );
    while ($row = $res->fetch_assoc()) {
        $guid = "$typeId-{$row["id"]}";
        $title = $lookupGuid($guid);
        if ($title === null) {
            $n["unresolved_guid"]++;
            continue;
        }
        if ($onlyTitles !== null && !isset($onlyTitles[$title])) {
            continue;
        }

        $currentText = null;
        $pageId = null;
        $humans = null; // null = derive from local db below
        if ($refetchApi !== null) {
            $fetched = apiFetchPage($refetchApi, $title);
            if ($fetched !== null) {
                $currentText = $fetched["text"];
                $humans = array_values(array_diff($fetched["editors"], $BOT_ACTORS));
            }
        } else {
            $pr = $wiki->query(sprintf(
                "SELECT p.page_id, t.old_text FROM page p
                 JOIN slots s ON s.slot_revision_id = p.page_latest
                 JOIN content c ON c.content_id = s.slot_content_id
                 JOIN text t ON t.old_id = CAST(SUBSTRING(c.content_address, 4) AS UNSIGNED)
                 WHERE p.page_namespace = 0 AND p.page_title = '%s'",
                $wiki->real_escape_string($title)
            ))->fetch_assoc();
            $currentText = $pr["old_text"] ?? null;
            $pageId = $pr["page_id"] ?? null;
        }
        if ($currentText === null) {
            $n["missing_page"]++;
            continue;
        }

        $newBody = $makeConverter()->convert($row["description"], $typeId, (int) $row["id"]);
        if ($newBody === false || trim((string) $newBody) === "") {
            $n["errors"]++;
            fwrite(STDERR, "convert failed: $guid $title\n");
            continue;
        }

        if ($humans === null) {
            $humans = [];
            $ar = $wiki->query("SELECT DISTINCT a.actor_name FROM revision r JOIN actor a ON r.rev_actor = a.actor_id WHERE r.rev_page = " . (int) $pageId);
            while ($a = $ar->fetch_row()) {
                if (!in_array($a[0], $BOT_ACTORS, true)) {
                    $humans[] = $a[0];
                }
            }
        }

        $verdict = strpos($row["description"], "<image ") !== false ? "figures" : "table-only";
        $n[$verdict === "figures" ? "figures" : "table_only"]++;

        $rec = json_encode([
            "guid" => $guid,
            "title" => $title,
            "verdict" => $verdict,
            "expected_sha1" => sha1($currentText),
            "new_body" => trim((string) $newBody),
            "human_editors" => $humans,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        if ($humans) {
            fwrite($review, $rec);
            $n["review"]++;
        } else {
            fwrite($out, $rec);
            $n["written"]++;
        }

        $done++;
        if ($done % 500 === 0) {
            fwrite(STDERR, "$done done (" . json_encode($n) . ")\n");
        }
        if ($limit > 0 && $n["written"] + $n["review"] >= $limit) {
            $res->close();
            break 2;
        }
    }
    $res->close();
    fwrite(STDERR, "$table done\n");
}

fclose($out);
fclose($review);
fwrite(STDERR, json_encode($n) . "\nwrote $outFile (+.review)\n");
