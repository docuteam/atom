<?php

class QubitLftSyncer
{
    private $parentId;
    private $limit;

    protected $classes;

    public function __construct($parentId, $limit = 10000)
    {
        $this->parentId = $parentId;
        $this->limit = $limit;

        // Set default classes
        $this->setClasses([
          'pdo' => QubitPdo::class,
          'search' => QubitSearch::class,
          'pluginquery' => arElasticSearchPluginQuery::class,
          'term' => Elastica\Query\Term::class,
          'bulk' => Elastica\Bulk::class,
          'update' => Elastica\Bulk\Action\UpdateDocument::class,
          'document' => Elastica\Document::class
        ]);
    }

    public function setClasses(array $classes)
    {
      $this->classes = $classes;
    }

    public function sync()
    {
        // Get checksum representing lft values of DB and Elasticsearch
        $dbChecksum = $this->getChildLftChecksumForDB();
        $esChecksum = $this->getChildLftChecksumForElasticsearch();

        // If checksums don't match then repair
        if ($dbChecksum != $esChecksum) {
            $this->repairEsChildrenLftValues();

            // Check to see if repair worked
            $esChecksum = $this->getChildLftChecksumForElasticsearch();

            // Make a few checks on the syncing, allowing for Elasticsearch to be
            // asynchronously updated
            if ($esChecksum != $dbChecksum) {
                $waitCount = 0;
                $maxWaitAttempts = 3;

                do {
                    sleep(1);
                    ++$waitCount;
                    $esChecksum = $this->getChildLftChecksumForElasticsearch();
                } while ($waitCount < $maxWaitAttempts && $esChecksum != $dbChecksum);
            }

            return $esChecksum == $dbChecksum; // Return boolean reflecting repair success
        }
    }

    public function getChildLftChecksumForDB()
    {
        $sql = sprintf('SELECT lft
            FROM information_object
            WHERE parent_id=:parentId
            ORDER BY lft ASC
            LIMIT %d', $this->limit);

        $params = [':parentId' => $this->parentId];
        $lft = $this->classes["pdo"]::fetchAll($sql, $params, ['fetchMode' => PDO::FETCH_COLUMN]);

        return md5(serialize($lft));
    }

    public function getChildLftChecksumForElasticsearch()
    {
        // Initialize Elasticsearch query
        $query = new $this->classes["pluginquery"]($this->limit);

        // Add criteria and set sort
        $term = new $this->classes["term"](['parentId' => $this->parentId]);
        $query->queryBool->addMust($term);
        $query->query->addSort(['lft' => 'asc']);

        // Get results
        $result = $this->classes["search"]::getInstance()
            ->index
            ->getType('QubitInformationObject')
            ->search($query->getQuery(false, false))
        ;

        // Amalgamate lft values in array
        $lft = [];
        foreach ($result->getResults() as $hit) {
            $doc = $hit->getDocument();
            $lft[] = $doc->lft;
        }

        return md5(serialize($lft));
    }

    public function repairEsChildrenLftValues()
    {
        $sql = sprintf('SELECT id, lft
            FROM information_object
            WHERE parent_id=:parentId
            LIMIT %d', $this->limit);

        $params = [':parentId' => $this->parentId];
        $results = $this->classes["pdo"]::fetchAll($sql, $params, ['fetchMode' => PDO::FETCH_ASSOC]);

        $bulk = new $this->classes["bulk"]($this->classes["search"]::getInstance()->client);
        $bulk->setIndex($this->classes["search"]::getInstance()->index->getName());
        $bulk->setType('QubitInformationObject');

        foreach ($results as $row) {
            $bulk->addAction(
                new $this->classes["update"](
                    new $this->classes["document"]($row['id'], ['lft' => $row['lft']])
                )
            );
        }

        $bulk->send();
    }
}
