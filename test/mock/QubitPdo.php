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

class QubitPdo
{
  public static function fetchAll($query, $parameters = [], $options = [])
  {
    $lftQuery = "SELECT id, lft
            FROM information_object
            WHERE parent_id=:parentId
            LIMIT 10000";

    $lftParameters = [":parentId" => 450];

    $lftSortedQuery = "SELECT lft
            FROM information_object
            WHERE parent_id=:parentId
            ORDER BY lft ASC
            LIMIT 10000";

    $lftSortedParameters = [":parentId" => 450];

    if ($query == $lftQuery && $parameters == $lftParameters) {
      return [
        ["id" => "451", "lft" => "5"],
        ["id" => "452", "lft" => "6"],
        ["id" => "453", "lft" => "8"]
      ];
    }

    if ($query == $lftSortedQuery && $parameters == $lftSortedParameters) {
      return ["5", "6", "8"];
    }
  }
}
