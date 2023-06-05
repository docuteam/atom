<?php

# TODO: way to populate the Elasticsearch index

require_once("test/mock/QubitPdo.php");
require_once("test/mock/QubitSearch.php");

$parentId = 450;
$limit = 10000;

print "--ONE--\n";

$sync = new QubitLftSyncer($parentId, $limit);

$sync->setClasses([
  "pdo" => \AccessToMemory\test\mock\QubitPdo::class,
  "search" => \AccessToMemory\test\mock\QubitSearch::class,
  "pluginquery" => arElasticSearchPluginQuery::class,
  "bulk" => \AccessToMemory\test\mock\Bulk::class,
  "update" => \AccessToMemory\test\mock\UpdateAction::class,
  "document" => \AccessToMemory\test\mock\Document::class,
  'term' => Elastica\Query\Term::class,
]);

#  "term" => \AccessToMemory\test\mock\Term::class,
#  "pluginquery" => \AccessToMemory\test\mock\arElasticSearchPluginQuery::class,

$sync->repairEsChildrenLftValues();

$check = $sync->getChildLftChecksumForDB();
print "C:". $check ."\n";

print "--TWO--\n";

$sync = new QubitLftSyncer($parentId, $limit);

$sync->setClasses([
  "pdo" => \AccessToMemory\test\mock\QubitPdo::class,
  "search" => \AccessToMemory\test\mock\QubitSearch::class,
  "pluginquery" => arElasticSearchPluginQuery::class,
  "bulk" => \AccessToMemory\test\mock\Bulk::class,
  "update" => \AccessToMemory\test\mock\UpdateAction::class,
  "document" => \AccessToMemory\test\mock\Document::class,
  'term' => Elastica\Query\Term::class,
]);

$data = [
  "slug" => "child",
  "parentId" => "450",
  "levelOfDescriptionId" => "242",
  "lft" => "99",
];

\AccessToMemory\test\mock\QubitSearchMockData::addDocument('QubitInformationObject', '451', $data);

$data = [
  "slug" => "child",
  "parentId" => "300",
  "levelOfDescriptionId" => "242",
  "lft" => "99",
];

\AccessToMemory\test\mock\QubitSearchMockData::addDocument('QubitInformationObject', '451', $data);

$data = \AccessToMemory\test\mock\QubitSearchMockData::getDocuments('QubitInformationObject');

print "DATE\n";
print_r($data);

#  "term" => \AccessToMemory\test\mock\Term::class,
#  "pluginquery" => \AccessToMemory\test\mock\arElasticSearchPluginQuery::class,


###$sync->repairEsChildrenLftValues();

#$sync->setClasses([
#  "pdo" => QubitPdo::class
#]);

###$sync->repairEsChildrenLftValues();

#$check = $sync->getChildLftChecksumForDB();
#print "C2:". $check ."\n";

#print "PRE\n";

$checksum = $sync->getChildLftChecksumForElasticsearch();
print "C:". $checksum ."\n";

/*
$a = new \AccessToMemory\test\mock\QubitPdo;

print get_class($a);

$sql = sprintf('SELECT id, lft
      FROM information_object
      WHERE parent_id=:parentId
      LIMIT %d', $limit);

$params = [':parentId' => $parentId];
$results = $a::fetchAll($sql, $params, ['fetchMode' => PDO::FETCH_ASSOC]);

$b = \AccessToMemory\test\mock\QubitSearch::getInstance();

print "C:". get_class($b) ."\n";
 */

print "Done\n";
