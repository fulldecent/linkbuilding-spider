<?php
ini_set('display_errors', 1);
// https://phpdelusions.net/pdo
$database = new \PDO('sqlite:spider.db');
$database->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$data = ['targets' => [], 'searchTerms' => []];
	
foreach (explode("\n", $_POST['targets']) as $target) {
  $target = trim($target);
  if (!filter_var($target, FILTER_VALIDATE_URL) === false) {
    $data['targets'][] = $target;
  }
}

foreach (explode("\n", $_POST['yoursites']) as $target) {
  $target = trim($target);
  if (strlen($target)) {
    $data['searchTerms'][$target] = 'mine';
  }
}

foreach (explode("\n", $_POST['competitorsites']) as $target) {
  $target = trim($target);
  if (strlen($target)) {
    $data['searchTerms'][$target] = 'competitor';
  }
}

$hash = sha1(json_encode($data));
$database->exec("CREATE TABLE IF NOT EXISTS jobs (uuid PRIMARY KEY, date, data)");
$database->exec("CREATE TABLE IF NOT EXISTS spideredPages (url PRIMARY KEY, date, data)");
$database->exec("DELETE FROM jobs WHERE date < date('now','-2 days')");
$database->exec("DELETE FROM spideredPages WHERE date < date('now','-2 days')");
$statement = $database->prepare("REPLACE INTO jobs (uuid, date, data) VALUES (?, date('now'), ?)");
$statement->execute([$hash, json_encode($data)]);
header("Location: run-report.php?jobid=$hash");