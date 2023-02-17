<?php
define('DB_FILE', 'spider.db');
define('MAX_CONNECTIONS', 300);
define('BITE_SIZE', 500);
$startTime = microtime(true);

// Show errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// https://phpdelusions.net/pdo
$database = new \PDO('sqlite:' . DB_FILE);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$statement = $database->prepare("SELECT * FROM jobs WHERE uuid = ?");
$statement->execute([$_GET['jobid']]);
$job = json_decode($statement->fetch(PDO::FETCH_OBJ)->data);

// Get the targets we will process this page load
$sql = <<<SQL
SELECT targets.atom
  FROM jobs, json_each(jobs.data, "$.targets") targets
  LEFT JOIN spideredPages ON spideredPages.url = targets.atom
 WHERE jobs.uuid = ?
   AND spideredPages.url IS NULL
 ORDER BY targets.rowid
 LIMIT ?
SQL;

$statement = $database->prepare($sql);
$statement->execute([$_GET['jobid'], BITE_SIZE]);
$targets = $statement->fetchAll(PDO::FETCH_COLUMN);
$showResultsAfterFetching = empty($targets);

/**
 * Parallel fetch
 * 
 * @param array    $urls       Every url to operate on
 * @param callable $callback   Callback to call for every url (url, body, info)
 * @param int      $numWorkers Maximum number of connections to use
 * @param array    $curlOpts   Options to pass to curl
 */
function curl_fetch_multi_3($urls, $callback, $numWorkers = 10, $curlOpts = [])
{
    // Init multi handle and workers
    $multiHandle = curl_multi_init();
    $numWorkers = min($numWorkers, count($urls));
    $numEmployedWorkers = 0;
    $unemployedWorkers = [];
    for ($i = 0; $i < $numWorkers; ++ $i) {
        $unemployedWorker = curl_init();
        if ($unemployedWorker === false) {
            throw new \RuntimeException('Failed to init unemployed worker #' . $i);
        }
        if (!empty($curlOpts)) {
            curl_setopt_array($unemployedWorker, $curlOpts);
        }
        $unemployedWorkers[] = $unemployedWorker;
    }
    unset($i, $unemployedWorker);

    // Process some workers, results in some workers being moved to $unemployedWorkers
    $work = function () use (&$numEmployedWorkers, &$unemployedWorkers, &$multiHandle, $callback): void {
        assert($numEmployedWorkers > 0, 'work() called when no employed workers!!');
        for (;;) {
            $stillRunning = 0;
            do {
                $result = curl_multi_exec($multiHandle, $stillRunning);
            } while ($result === CURLM_CALL_MULTI_PERFORM);
            if ($result !== CURLM_OK) {
                throw new \RuntimeException('curl_multi_exec error: ' . curl_multi_strerror($result));
            }
            if ($stillRunning < $numEmployedWorkers) {                
                // PHP documentation for still_running is wrong, see https://curl.se/libcurl/c/curl_multi_perform.html
                // Some worker(s) finished downloading, process them
                break;
            }
            // No workers finished yet, select-wait for worker(s) to finish downloading.
            curl_multi_select($multiHandle, 1);
        }
        while (false !== ($info = curl_multi_info_read($multiHandle))) {
            if ($info['msg'] !== CURLMSG_DONE) {
                // Per https://curl.se/libcurl/c/curl_multi_info_read.html, no other message types are now possible
                continue;
            }
            if (curl_multi_errno($multiHandle) !== CURLM_OK) {
                // PHP docs say to use CURLE_OK here, ignoring docs and using CURLM_OK instead
                throw new \RuntimeException('curl_multi worker error: ' . curl_multi_strerror($info['result']));
            }
            $curlHandle = $info['handle'];
            $body = curl_multi_getcontent($curlHandle);
            $curlInfo = curl_getinfo($curlHandle);
            $url = curl_getinfo($curlHandle, CURLINFO_PRIVATE);
            $callback($url, $body, $curlInfo);
            $numEmployedWorkers -= 1;
            curl_multi_remove_handle($multiHandle, $curlHandle);
            $unemployedWorkers[] = $curlHandle;
        }
    };
    
    // Main loop
    $opts = [
//        CURLOPT_RETURNTRANSFER => true,
//        CURLOPT_ENCODING => '',
    ];
    foreach ($urls as $url) {
        if (empty($unemployedWorkers)) {
            $work(); // Postcondition: $unemployedWorkers is not empty
        }
        $newWorker = array_pop($unemployedWorkers);
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_PRIVATE] = $url;
        $result = curl_setopt_array($newWorker, $opts);
        if ($result === false) {
            throw new \RuntimeException('curl_setopt_array error: ' . curl_error($newWorker));
        }
        $numEmployedWorkers += 1;
        $result = curl_multi_add_handle($multiHandle, $newWorker);
        if ($result === false) {
            throw new \RuntimeException('curl_multi_add_handle error: ' . curl_error($newWorker));
        }
    }
    while ($numEmployedWorkers > 0) {
        $work();
    }
    foreach ($unemployedWorkers as $unemployedWorker) {
        curl_close($unemployedWorker);
    }
    curl_multi_close($multiHandle);
}

$curlOpts = [
  CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.87 Safari/537.36',
//  CURLOPT_FAILONERROR => true,
  CURLOPT_MAXREDIRS => 4,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 4,
  CURLOPT_CONNECTTIMEOUT => 3,
];

// Start transaction
$database->beginTransaction();

curl_fetch_multi_3($targets, function ($url, $body, $info) use ($database) {
	preg_match_all('/https?\:\/\/[^\"\' <]+/i', $body, $matches);
	$allUrls = array_unique($matches[0]);
  $statement = $database->prepare("REPLACE INTO spideredPages (url, date, data) VALUES (?, date('now'), ?)");
  $statement->execute([$url, json_encode($allUrls)]);
}, MAX_CONNECTIONS, $curlOpts);

// Commit changes
$database->commit();

// Remaining count
$sql = <<<SQL
SELECT COUNT(*)
  FROM jobs, json_each(jobs.data, "$.targets") targets
  LEFT JOIN spideredPages ON spideredPages.url = targets.atom
 WHERE jobs.uuid = ?
   AND spideredPages.url IS NULL
 ORDER BY targets.rowid
SQL;
$statement = $database->prepare($sql);
$statement->execute([$_GET['jobid']]);
$remainingCount = $statement->fetchColumn();

if ($remainingCount > 0) {
  header("refresh:1;?jobid={$_GET['jobid']}");
}
$processingTime = microtime(true) - $startTime + 1; // One second for reload
$pagesPerSecond = BITE_SIZE / $processingTime;
$eta = $remainingCount / $pagesPerSecond;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Linkbuilding Spider</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-GLhlTQ8iRABdZLl6O3oVMWSktQOp6b7In1Zl3/Jr59b6EGGoI1aFkw7cmDA6j6gD" crossorigin="anonymous">
    <meta name="robots" content="noindex,nofollow,noarchive" />
  </head>

  <body>
    <header class="bg-secondary-subtle mb-4 py-4">
      <div class="container">
        <h1 class="display-3">Linkbuilding Spider &#x1F577;</h1>
        <p class="lead">Remaining to fetch: <?= number_format($remainingCount) ?></p>
        <p>Speed: <?= number_format(BITE_SIZE / $processingTime, 1) ?> pages per second</p>
        <p>ETA: <?= number_format($eta, 1) ?> seconds</p>
      </div>
    </header>

    <div class="container">
<?php
if ($remainingCount > 0) {
  echo '</div></body></html>';
  exit;
}

echo '<h2>Results</h2>';
$count = 0;

foreach ($job->targets as $target) {
  $statement = $database->prepare('SELECT data FROM spideredPages WHERE url = ?');
  $statement->execute([$target]);
  $foundLinks = json_decode($statement->fetchColumn());
//  if (empty($foundLinks)) $foundLinks = [];
	$matchedLinks = [];
	foreach ($foundLinks as $foundLink) {
		foreach ($job->searchTerms as $searchTerm => $category) {
			if (false !== stripos($foundLink, $searchTerm)) {
        if ($category !== 'mine') continue; // clean it up
				$matchedLinks[$foundLink] = $category;
			}
		}
	}
  $count += count($matchedLinks);
	if (count($matchedLinks) > 0) {
		echo '<p class="lead">Target: '.htmlspecialchars($target).'</p>' . PHP_EOL;
		echo '<ul>' . PHP_EOL;
		foreach ($matchedLinks as $link => $category) {
			if ($category == 'mine') {
				echo '<li><span class="badge bg-success">Your site</span> '.htmlspecialchars($link).'</li>' . PHP_EOL;
			} else {
				echo '<li><span class="badge bg-danger">Competitor site</span> '.htmlspecialchars($link).'</li>' . PHP_EOL;
			}
		}
		echo '</ul>' . PHP_EOL;
	}
	flush();
}
?>
      <p class="lead">Total: <?= number_format($count) ?></p>
    </div>
  </body>
</html>