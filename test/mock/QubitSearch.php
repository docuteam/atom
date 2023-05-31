<?php

/*
 * This file is part of the Access to Memory (AtoM) software.
 *
 * Access to Memory (AtoM) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Access to Memory (AtoM) is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Access to Memory (AtoM).  If not, see <http://www.gnu.org/licenses/>.
 */

namespace AccessToMemory\test\mock;

class arElasticSearchPlugin
{
  public $index;

  public function __construct()
  {
    $this->index = new Index(null, "atom");
  }
}

class QubitSearchMockData
{
  protected static $documents = [];

  public static function addDocument($typeName, $id, $data) {
    if (!isset(self::$documents[$typeName])) {
      self::$documents[$typeName] = [];
    }

    self::$documents[$typeName][] = [
      "id" => $id,
      "data" => $data
    ];
  }

  public static function getDocuments($typeName, $options = []) {
    // Create copy of documents to preserve initial sort order
    $sortDocuments = self::$documents[$typeName];

    if (!empty($options["sort_by"])) {
      $sortBy = $options["sort_by"];

      // Sort copy of documents
      usort($sortDocuments, function($a, $b) {
        if ($a['data'][$sortBy] > $b['data'][$sortBy]) {
          return 1;
        } elseif ($a['data'][$sortBy] < $b['data'][$sortBy]) {
          return -1;
        }

        return 0;
      });

      // Reverse sort if need be
      if (isset($options["sort_dir"]) && $options["sort_dir"] != 'asc') {
        $sortDocuments = array_reverse($sortDocuments);
      }
    }

    // Search sorted documents
    if (empty($options["must"])) {
        return $sortDocuments;
    } else {
        $results = [];

        // Determine which documents match search criteria
        foreach ($sortDocuments as $document) {
          $match = true;

          // Evaluate must clauses
          foreach ($options["must"] as $mustClause) {
            // Parse out field and value
            $field = array_keys($mustClause)[0];
            $value = array_values($mustClause)[0];

            if ($document["data"][$field] != $value) {
              $match = false;
            }
          }

          // Add document to results if there's a match
	  if ($match) {
            $results[] = $document;
          }
        }

        return $results;
    }
  }
}

class QubitSearch
{
    protected static $instance;

    public static function getInstance(array $options = [])
    {
        if (!isset(self::$instance)) {
            // Using arElasticSearchPlugin but other classes could be
            // implemented, for example: arSphinxSearchPlugin
            self::$instance = new arElasticSearchPlugin($options);
        }

        return self::$instance;
    }
}

class Index
{
  public function __construct($client, $name)
  {
    if (!is_scalar($name)) {
      throw new InvalidException('Index name should be a scalar type');
    }
    $this->_name = (string) $name;
  }

  public function getName()
  {
    return $this->_name;
  }

  public function getType($name)
  {
    return new Type($this, $name);
  }
}

class Type
{
  public function __construct(Index $index, $name)
  {
    $this->_index = $index;
    $this->_name = $name;
  }

  public function search($query = '', $options = null)
  {
    $search = [];
    $search["must"] = [$query->toArray()["query"]["bool"]["must"][0]["term"]];

    $rawResults = QubitSearchMockData::getDocuments($this->_name, $search);
print_r($rawResults);

    $results = [];
    foreach ($rawResults as $rawResult) {
      $results[] = new Result($rawResult);
    }

    return new ResultSet(null, null, $results);
  }
}

class ResultSet
{
  public function __construct($response, $query, $results)
  {
    $this->_results = $results;
  }

  public function getResults()
  {
    return $this->_results;
  }
}

class Result
{
  public function __construct(array $hit)
  {
    $this->_hit = $hit;
  }

  public function getDocument()
  {
    $doc = new \stdClass();

    foreach ($this->_hit["data"] as $field => $value) {
      $doc->$field = $value;
    }

    return $doc;
  }
}

class Bulk
{
  private $actions = [];

  function setIndex($name) {
  }

  function setType($name) {

  }

  function addAction($action) {
    $this->$actions[] = $action;
  }

  function send() {
  }
}

class UpdateAction
{
  public function __construct($document) {

  }
}

class Document
{
  public function __construct($id, $data) {

  }
}
