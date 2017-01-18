<?php
// https://phpdelusions.net/pdo
$database = new \PDO('sqlite:spider.db');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$statement = $database->prepare("SELECT * FROM jobs WHERE uuid = ?");
$statement->execute([$_GET['jobid']]);
$job = json_decode($statement->fetch(PDO::FETCH_OBJ)->data);

// Build the multi-curl handle, adding both
$multiHandle = curl_multi_init();

// Add each link to queue
$remainingTargets = $job->targets;
//shuffle($remainingTargets); // use a deterministic ordering
usort($remainingTargets, function ($a, $b) { return strcmp(md5($a), md5($b)); }); // a determinist shuffling
$targets = [];
while (count($targets) < 30 && count($remainingTargets) > 0) {
  $target = array_shift($remainingTargets);
  $statement = $database->prepare("SELECT * FROM spideredPages WHERE url = ?");
  $statement->execute([$target]);
  $page = $statement->fetch();
  if ($page !== false) {
    continue;
  }
	$curl = curl_init($target);
	curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/49.0.2623.87 Safari/537.36');
	curl_setopt($curl, CURLOPT_FAILONERROR, true);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
	curl_setopt($curl, CURLOPT_TIMEOUT, 5); //timeout in seconds
  curl_multi_add_handle($multiHandle, $curl);
  $targets[$target] = $curl;
}

// Execute
$running = null;
do {
  curl_multi_exec($multiHandle, $running);
} while ($running);

//var_dump("memory", memory_get_usage());

// Harvest results
foreach ($targets as $target => $curl) {
  $html = curl_multi_getcontent($curl);
  curl_multi_remove_handle($multiHandle, $curl);
	preg_match_all('/https?\:\/\/[^\"\' ]+/i', $html, $matches);
	$all_urls = $matches[0];
  $statement = $database->prepare("REPLACE INTO spideredPages (url, date, data) VALUES (?, date('now'), ?)");
  $statement->execute([$target, json_encode($all_urls)]);
}
curl_multi_close($multiHandle);

if (count($remainingTargets) > 0) {
  	header("refresh:2;?jobid={$_GET['jobid']}");
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-alpha.6/css/bootstrap.min.css" integrity="sha384-rwoIResjU2yc3z8GV/NPeZWAv56rSmLldC3R/AZzGRnGxQQKnKkoFVhFQhNUwEyJ" crossorigin="anonymous">
    <title>Linkbuilding Spider</title>
  </head>

  <body>
    <!-- Main jumbotron for a primary marketing message or call to action -->
    <div class="jumbotron">
      <div class="container">
        <h1 class="display-3">Linkbuilding Spider &#x1F577;</h1>
        <p>Running report, <?= count($job->targets) - count($remainingTargets) ?> of <?= count($job->targets) ?> pages retrieved...</p>
        
        <div class="progress">
          <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: <?= 100 * (count($job->targets) - count($remainingTargets)) / count($job->targets) ?>%"></div>
        </div>
      </div>
    </div>
    
    <div class="container">
    
<?php
if (count($remainingTargets) > 0)	{
	exit;
}

foreach ($job->targets as $target) {
  $statement = $database->prepare("SELECT * FROM spideredPages WHERE url = ?");
  $statement->execute([$target]);
  $foundLinks = json_decode($statement->fetch(PDO::FETCH_OBJ)->data);
	$matchedLinks = array();
	foreach ($foundLinks as $foundLink) {
		foreach ($job->searchTerms as $searchTerm => $category) {
//			if (preg_match('/(^|\\.|\\/)'.preg_quote($searchTerm).'($|\\.|\\/)/', $foundLink)) {
			if (false !== stripos($foundLink, $searchTerm)) {
				$matchedLinks[$foundLink] = $category;
			}
		}
	}
	if (count($matchedLinks) > 0) {
		echo "<p class=\"lead\">Checking ".htmlspecialchars($target)."</p>\n";
		echo "<ul>\n";
		foreach ($matchedLinks as $link => $category) {
			if ($category == 'mine') {
				echo "<li class=\"text-success\">LINK TO YOUR PAGE: ".htmlspecialchars($link)."</li>\n";				
			} else {
				echo "<li class=\"text-danger\">LINK TO COMPETITOR: ".htmlspecialchars($link)."</li>\n";				
			}
		}
		echo "</ul>\n";
	}
	flush();	
}
?>

    </div>
  </body>
</html>
