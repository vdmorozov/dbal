<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Schema\Sequence;
use const PREG_OFFSET_CAPTURE;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function substr_count;

/**
 * Platform to ensure compatibility of Doctrine with Microsoft SQL Server 2012 version.
 *
 * Differences to SQL Server 2008 and before are that sequences are introduced,
 * and support for the new OFFSET... FETCH syntax for result pagination has been added.
 */
class SQLServer2012Platform extends SQLServerPlatform
{
    /**
     * {@inheritdoc}
     */
    public function getAlterSequenceSQL(Sequence $sequence) : string
    {
        return 'ALTER SEQUENCE ' . $sequence->getQuotedName($this) .
               ' INCREMENT BY ' . $sequence->getAllocationSize();
    }

    /**
     * {@inheritdoc}
     */
    public function getCreateSequenceSQL(Sequence $sequence) : string
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this) .
               ' START WITH ' . $sequence->getInitialValue() .
               ' INCREMENT BY ' . $sequence->getAllocationSize() .
               ' MINVALUE ' . $sequence->getInitialValue();
    }

    /**
     * {@inheritdoc}
     */
    public function getDropSequenceSQL($sequence) : string
    {
        if ($sequence instanceof Sequence) {
            $sequence = $sequence->getQuotedName($this);
        }

        return 'DROP SEQUENCE ' . $sequence;
    }

    /**
     * {@inheritdoc}
     */
    public function getListSequencesSQL(string $database) : string
    {
        return 'SELECT seq.name,
                       CAST(
                           seq.increment AS VARCHAR(MAX)
                       ) AS increment, -- CAST avoids driver error for sql_variant type
                       CAST(
                           seq.start_value AS VARCHAR(MAX)
                       ) AS start_value -- CAST avoids driver error for sql_variant type
                FROM   sys.sequences AS seq';
    }

    /**
     * {@inheritdoc}
     */
    public function getSequenceNextValSQL(string $sequenceName) : string
    {
        return 'SELECT NEXT VALUE FOR ' . $sequenceName;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsSequences() : bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     *
     * Returns Microsoft SQL Server 2012 specific keywords class
     */
    protected function getReservedKeywordsClass() : string
    {
        return Keywords\SQLServer2012Keywords::class;
    }

    /**
     * {@inheritdoc}
     */
    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset) : string
    {
        if ($limit === null && $offset <= 0) {
            return $query;
        }

        // Queries using OFFSET... FETCH MUST have an ORDER BY clause
        // Find the position of the last instance of ORDER BY and ensure it is not within a parenthetical statement
        // but can be in a newline
        $matches      = [];
        $matchesCount = preg_match_all('/[\\s]+order\\s+by\\s/im', $query, $matches, PREG_OFFSET_CAPTURE);
        $orderByPos   = false;
        if ($matchesCount > 0) {
            $orderByPos = $matches[0][($matchesCount - 1)][1];
        }

        if ($orderByPos === false
            || substr_count($query, '(', $orderByPos) - substr_count($query, ')', $orderByPos)
        ) {
            if (preg_match('/^SELECT\s+DISTINCT/im', $query)) {
                // SQL Server won't let us order by a non-selected column in a DISTINCT query,
                // so we have to do this madness. This says, order by the first column in the
                // result. SQL Server's docs say that a nonordered query's result order is non-
                // deterministic anyway, so this won't do anything that a bunch of update and
                // deletes to the table wouldn't do anyway.
                $query .= ' ORDER BY 1';
            } else {
                // In another DBMS, we could do ORDER BY 0, but SQL Server gets angry if you
                // use constant expressions in the order by list.
                $query .= ' ORDER BY (SELECT 0)';
            }
        }

        // This looks somewhat like MYSQL, but limit/offset are in inverse positions
        // Supposedly SQL:2008 core standard.
        // Per TSQL spec, FETCH NEXT n ROWS ONLY is not valid without OFFSET n ROWS.
        $query .= sprintf(' OFFSET %d ROWS', $offset);

        if ($limit !== null) {
            $query .= sprintf(' FETCH NEXT %d ROWS ONLY', $limit);
        }

        return $query;
    }
}
