<?php

/**
 * The main Tags class.
 *
 * This Source Code Form is subject to the terms of the Mozilla Public License,
 * v. 2.0. If a copy of the MPL was not distributed with this file, You can
 * obtain one at https://mozilla.org/MPL/2.0/.
 *
 * @package   phpMyFAQ
 * @author    Thorsten Rinne <thorsten@phpmyfaq.de>
 * @author    Matteo Scaramuccia <matteo@scaramuccia.com>
 * @author    Georgi Korchev <korchev@yahoo.com>
 * @copyright 2006-2023 phpMyFAQ Team
 * @license   https://www.mozilla.org/MPL/2.0/ Mozilla Public License Version 2.0
 * @link      https://www.phpmyfaq.de
 * @since     2006-08-10
 */

namespace phpMyFAQ;

use phpMyFAQ\Entity\TagEntity as EntityTags;

/**
 * Class Tags
 *
 * @package phpMyFAQ
 */
class Tags
{
    /**
     * @var array<int, string>
     */
    private array $recordsByTagName = [];

    /**
     * Constructor.
     */
    public function __construct(private Configuration $config)
    {
    }

    /**
     * Returns all tags for a FAQ record.
     *
     * @param int $recordId Record ID
     */
    public function getAllLinkTagsById(int $recordId): string
    {
        $tagListing = '';

        foreach ($this->getAllTagsById($recordId) as $taggingId => $taggingName) {
            $title = Strings::htmlspecialchars($taggingName, ENT_QUOTES, 'utf-8');
            $url = sprintf('%sindex.php?action=search&amp;tagging_id=%d', $this->config->getDefaultUrl(), $taggingId);
            $oLink = new Link($url, $this->config);
            $oLink->itemTitle = $taggingName;
            $oLink->text = $taggingName;
            $oLink->tooltip = $title;
            $tagListing .= $oLink->toHtmlAnchor() . ', ';
        }

        return '' == $tagListing ? '-' : Strings::substr($tagListing, 0, -2);
    }

    /**
     * Returns all tags for a FAQ record.
     *
     * @param int $recordId Record ID
     * @return array<int, string>
     */
    public function getAllTagsById(int $recordId): array
    {
        $tags = [];

        $query = sprintf(
            '
            SELECT
                dt.tagging_id AS tagging_id, 
                t.tagging_name AS tagging_name
            FROM
                %sfaqdata_tags dt, %sfaqtags t
            WHERE
                dt.record_id = %d
            AND
                dt.tagging_id = t.tagging_id
            ORDER BY
                t.tagging_name',
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            $recordId
        );

        $result = $this->config->getDb()->query($query);
        if ($result) {
            while ($row = $this->config->getDb()->fetchObject($result)) {
                $tags[$row->tagging_id] = $row->tagging_name;
            }
        }

        return $tags;
    }

    /**
     * Saves all tags from a FAQ record.
     *
     * @param int $recordId Record ID
     * @param array<int, string> $tags Array of tags
     */
    public function saveTags(int $recordId, array $tags): bool
    {
        $currentTags = $this->getAllTags();

        // Delete all tag references for the faq record
        if (count($tags) > 0) {
            $this->deleteTagsFromRecordId($recordId);
        }

        // Store tags and references for the faq record
        foreach ($tags as $tagName) {
            $tagName = trim($tagName);
            if (Strings::strlen($tagName) > 0) {
                if (
                    !in_array(
                        Strings::strtolower($tagName),
                        array_map(['phpMyFAQ\Strings', 'strtolower'], $currentTags)
                    )
                ) {
                    // Create the new tag
                    $newTagId = $this->config->getDb()->nextId(Database::getTablePrefix() . 'faqtags', 'tagging_id');
                    $query = sprintf(
                        "INSERT INTO %sfaqtags (tagging_id, tagging_name) VALUES (%d, '%s')",
                        Database::getTablePrefix(),
                        $newTagId,
                        $tagName
                    );
                    $this->config->getDb()->query($query);

                    // Add the tag reference for the faq record
                    $query = sprintf(
                        'INSERT INTO %sfaqdata_tags (record_id, tagging_id) VALUES (%d, %d)',
                        Database::getTablePrefix(),
                        $recordId,
                        $newTagId
                    );
                    $this->config->getDb()->query($query);
                } else {
                    // Add the tag reference for the faq record
                    $query = sprintf(
                        'INSERT INTO %sfaqdata_tags (record_id, tagging_id) VALUES (%d, %d)',
                        Database::getTablePrefix(),
                        $recordId,
                        array_search(
                            Strings::strtolower($tagName),
                            array_map(['phpMyFAQ\Strings', 'strtolower'], $currentTags)
                        )
                    );
                    $this->config->getDb()->query($query);
                }
            }
        }

        return true;
    }

    /**
     * Returns all tags.
     *
     * @param string|null $search Move the returned result set to be the result of a start-with search
     * @param int $limit Limit the returned result set
     * @param bool $showInactive Show inactive tags
     * @return array<int, string>
     */
    public function getAllTags(
        string $search = null,
        int $limit = PMF_TAGS_CLOUD_RESULT_SET_SIZE,
        bool $showInactive = false
    ): array {
        $allTags = [];

        $like = match (Database::getType()) {
            'pgsql' => 'ILIKE',
            default => 'LIKE',
        };

        $query = sprintf(
            '
            SELECT
                MIN(t.tagging_id) AS tagging_id, t.tagging_name AS tagging_name
            FROM
                %sfaqtags t
            LEFT JOIN
                %sfaqdata_tags dt
            ON
                dt.tagging_id = t.tagging_id
            LEFT JOIN
                %sfaqdata d
            ON
                d.id = dt.record_id
            WHERE
                1=1
                %s
                %s
            GROUP BY
                tagging_name
            ORDER BY
                tagging_name ASC',
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            ($showInactive ? '' : "AND d.active = 'yes'"),
            (isset($search) && ($search != '') ? 'AND tagging_name ' . $like . " '" . $search . "%'" : '')
        );

        $result = $this->config->getDb()->query($query);

        if ($result) {
            $i = 0;
            while ($row = $this->config->getDb()->fetchObject($result)) {
                if ($i < $limit) {
                    $allTags[$row->tagging_id] = $row->tagging_name;
                } else {
                    break;
                }
                ++$i;
            }
        }

        return array_unique($allTags);
    }

    /**
     * Deletes all tags from a given record id.
     *
     * @param int $recordId Record ID
     */
    public function deleteTagsFromRecordId(int $recordId): bool
    {
        $query = sprintf(
            'DELETE FROM %sfaqdata_tags WHERE record_id = %d',
            Database::getTablePrefix(),
            $recordId
        );

        $this->config->getDb()->query($query);

        return true;
    }

    /**
     * Updates a tag.
     */
    public function updateTag(EntityTags $entity): bool
    {
        $query = sprintf(
            "UPDATE %sfaqtags SET tagging_name = '%s' WHERE tagging_id = %d",
            Database::getTablePrefix(),
            $entity->getName(),
            $entity->getId()
        );

        return $this->config->getDb()->query($query);
    }

    /**
     * Deletes a given tag.
     */
    public function deleteTag(int $tagId): bool
    {
        $query = sprintf(
            'DELETE FROM %sfaqtags WHERE tagging_id = %d',
            Database::getTablePrefix(),
            $tagId
        );

        $this->config->getDb()->query($query);

        $query = sprintf(
            'DELETE FROM %sfaqdata_tags WHERE tagging_id = %d',
            Database::getTablePrefix(),
            $tagId
        );

        $this->config->getDb()->query($query);

        return true;
    }

    /**
     * Returns the FAQ record IDs where all tags are included.
     *
     * @param array<int, int> $arrayOfTags Array of Tags
     * @return array<int, int>
     */
    public function getFaqsByIntersectionTags(array $arrayOfTags): array
    {
        $query = sprintf(
            "
            SELECT
                td.record_id AS record_id
            FROM
                %sfaqdata_tags td
            JOIN
                %sfaqtags t ON (td.tagging_id = t.tagging_id)
            JOIN
                %sfaqdata d ON (td.record_id = d.id)
            WHERE
                (t.tagging_name IN ('%s'))
            AND
                (d.lang = '%s')
            GROUP BY
                td.record_id
            HAVING
                COUNT(td.record_id) = %d",
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            implode("', '", $arrayOfTags),
            $this->config->getLanguage()->getLanguage(),
            count($arrayOfTags)
        );

        $records = [];
        $result = $this->config->getDb()->query($query);
        while ($row = $this->config->getDb()->fetchObject($result)) {
            $records[] = $row->record_id;
        }

        return $records;
    }

    /**
     * Returns the HTML for the Tags Cloud.
     */
    public function renderTagCloud(): string
    {
        $tags = [];

        // Limit the result set (see: PMF_TAGS_CLOUD_RESULT_SET_SIZE)
        // for avoiding an 'heavy' load during the evaluation
        // of the number of records for each tag
        $tagList = $this->getAllTags('', PMF_TAGS_CLOUD_RESULT_SET_SIZE);

        foreach ($tagList as $tagId => $tagName) {
            $totFaqByTag = count($this->getFaqsByTagName($tagName));
            if ($totFaqByTag > 0) {
                $tags[$tagName]['id'] = $tagId;
                $tags[$tagName]['name'] = Strings::htmlentities($tagName);
                $tags[$tagName]['count'] = $totFaqByTag;
            }
        }

        $html = '';
        $i = 0;
        foreach ($tags as $tag) {
            ++$i;
            $title = Strings::htmlspecialchars($tag['name'] . ' (' . $tag['count'] . ')', ENT_QUOTES);
            $url = sprintf(
                '%sindex.php?action=search&amp;tagging_id=%d',
                Strings::htmlentities($this->config->getDefaultUrl()),
                $tag['id']
            );
            $oLink = new Link($url, $this->config);
            $oLink->itemTitle = $tag['name'];
            $oLink->text = $tag['name'];
            $oLink->tooltip = $title;
            $oLink->class = 'btn btn-primary mr-1 my-1';
            $html .= $oLink->toHtmlAnchor();
            $html .= (count($tags) == $i ? '' : ' ');
        }

        return $html;
    }

    /**
     * Returns all FAQ record IDs where all tags are included.
     *
     * @param string $tagName The name of the tag
     * @return array<int, string>
     */
    public function getFaqsByTagName(string $tagName): array
    {
        if (count($this->recordsByTagName)) {
            return $this->recordsByTagName;
        }

        $query = sprintf(
            "
            SELECT
                dt.record_id AS record_id
            FROM
                %sfaqtags t, %sfaqdata_tags dt
            LEFT JOIN
                %sfaqdata d
            ON
                d.id = dt.record_id
            WHERE
                t.tagging_id = dt.tagging_id
            AND 
                t.tagging_name = '%s'",
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            $this->config->getDb()->escape($tagName)
        );

        $this->recordsByTagName = [];
        $result = $this->config->getDb()->query($query);
        while ($row = $this->config->getDb()->fetchObject($result)) {
            $this->recordsByTagName[] = $row->record_id;
        }

        return $this->recordsByTagName;
    }

    /**
     * Returns all FAQ record IDs where all tags are included.
     *
     * @param int $tagId Tagging ID
     * @return array<int>
     */
    public function getFaqsByTagId(int $tagId): array
    {
        $query = sprintf(
            '
            SELECT
                d.record_id AS record_id
            FROM
                %sfaqdata_tags d, %sfaqtags t
            WHERE
                t.tagging_id = d.tagging_id
            AND
                t.tagging_id = %d
            GROUP BY
                record_id',
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            $tagId
        );

        $records = [];
        $result = $this->config->getDb()->query($query);
        while ($row = $this->config->getDb()->fetchObject($result)) {
            $records[] = $row->record_id;
        }

        return $records;
    }

    public function renderPopularTags(int $limit = 0): string
    {
        $html = '';
        foreach ($this->getPopularTags($limit) as $tagId => $tagFreq) {
            $tagName = $this->getTagNameById($tagId);
            $html .= sprintf(
                '<a class="btn btn-primary m-1" href="?action=search&tagging_id=%d">%s ' .
                '<span class="badge bg-info">%d</span></a>',
                $tagId,
                Strings::htmlentities($tagName),
                $tagFreq
            );
        }

        return $html;
    }

    /**
     * @param int $limit Specify the maximum amount of records to return
     * @return array<int, int>
     */
    public function getPopularTags($limit = 0): array
    {
        $tags = [];

        $query = sprintf(
            "
            SELECT
                COUNT(record_id) as freq, tagging_id
            FROM
                %sfaqdata_tags
            JOIN
                %sfaqdata ON id = record_id
            WHERE
              lang = '%s'
            GROUP BY tagging_id
            ORDER BY freq DESC",
            Database::getTablePrefix(),
            Database::getTablePrefix(),
            $this->config->getLanguage()->getLanguage()
        );

        $result = $this->config->getDb()->query($query);

        if ($result) {
            while ($row = $this->config->getDb()->fetchObject($result)) {
                $tags[$row->tagging_id] = $row->freq;
                if (--$limit === 0) {
                    break;
                }
            }
        }

        return $tags;
    }

    /**
     * Returns the tagged item.
     *
     * @param int $tagId Tagging ID
     */
    public function getTagNameById(int $tagId): string
    {
        $query = sprintf(
            'SELECT tagging_name FROM %sfaqtags WHERE tagging_id = %d',
            Database::getTablePrefix(),
            $tagId
        );

        $result = $this->config->getDb()->query($query);
        if ($row = $this->config->getDb()->fetchObject($result)) {
            return $row->tagging_name;
        }

        return '';
    }

    /**
     * Returns the popular Tags as an array
     *
     * @return array<int, array<string, int|string>>
     */
    public function getPopularTagsAsArray(int $limit = 0): array
    {
        $data = [];
        foreach ($this->getPopularTags($limit) as $tagId => $tagFreq) {
            $tagName = $this->getTagNameById($tagId);
            $data[] = [
                'tagId' => $tagId,
                'tagName' => $tagName,
                'tagFrequency' => $tagFreq
            ];
        }

        return $data;
    }
}
