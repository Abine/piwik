<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\DataTable\Filter;

use Piwik\DataTable\BaseFilter;
use Piwik\DataTable;
use Piwik\DataTable\Row;

/**
 * Calculates the quotient of two columns and adds the result as a new column
 * for each row of a DataTable.
 * 
 * This filter is used to calculate rate values (eg, `'bounce_rate'`), averages
 * (eg, `'avg_time_on_page'`) and other types of values.
 *
 * **Basic usage example**
 * 
 *     $dataTable->queueFilter('ColumnCallbackAddColumnQuotient', array('bounce_rate', 'bounce_count', 'nb_visits', $precision = 2));
 * 
 * @api
 */
class ColumnCallbackAddColumnQuotient extends BaseFilter
{
    protected $table;
    protected $columnValueToRead;
    protected $columnNameToAdd;
    protected $columnNameUsedAsDivisor;
    protected $totalValueUsedAsDivisor;
    protected $quotientPrecision;
    protected $shouldSkipRows;
    protected $getDivisorFromSummaryRow;

    /**
     * Constructor.
     * 
     * @param DataTable $table The DataTable that will eventually be filtered.
     * @param string $columnNameToAdd The name of the column to add the quotient value to.
     * @param string $columnValueToRead The name of the column that holds the dividend.
     * @param number|string $divisorValueOrDivisorColumnName
     *                           Either numeric value to use as the divisor for every row,
     *                           or the name of the column whose value should be used as the
     *                           divisor.
     * @param int $quotientPrecision The precision to use when rounding the quotient.
     * @param bool|number $shouldSkipRows Whether rows w/o the column to read should be skipped or not.
     * @param bool $getDivisorFromSummaryRow Whether to get the divisor from the summary row or the current
     *                                       row iteration.
     */
    public function __construct($table, $columnNameToAdd, $columnValueToRead, $divisorValueOrDivisorColumnName,
                                $quotientPrecision = 0, $shouldSkipRows = false, $getDivisorFromSummaryRow = false)
    {
        parent::__construct($table);
        $this->table = $table;
        $this->columnValueToRead = $columnValueToRead;
        $this->columnNameToAdd = $columnNameToAdd;
        if (is_numeric($divisorValueOrDivisorColumnName)) {
            $this->totalValueUsedAsDivisor = $divisorValueOrDivisorColumnName;
        } else {
            $this->columnNameUsedAsDivisor = $divisorValueOrDivisorColumnName;
        }
        $this->quotientPrecision = $quotientPrecision;
        $this->shouldSkipRows = $shouldSkipRows;
        $this->getDivisorFromSummaryRow = $getDivisorFromSummaryRow;
    }

    /**
     * See {@link ColumnCallbackAddColumnQuotient}.
     *
     * @param DataTable $table
     */
    public function filter($table)
    {
        foreach ($table->getRows() as $row) {
            $self = $this;
            $row->addColumn($this->columnNameToAdd, function (Row $row) use ($self) {
                $value = $self->getDividend($row);
                if ($value === false && $self->shouldSkipRows) {
                    continue;
                }

                // Delete existing column if it exists
                /* TODO: remove this code if no failures.
                $existingValue = $row->getColumn($self->columnNameToAdd);
                if ($existingValue !== false) {
                    continue;
                }*/

                $divisor = $self->getDivisor($row);

                $formattedValue = $self->formatValue($value, $divisor);

                return $formattedValue;
            });

            $this->filterSubTable($row);
        }
    }

    /**
     * Formats the given value
     *
     * @param number $value
     * @param number $divisor
     * @return float|int
     */
    public function formatValue($value, $divisor)
    {
        $quotient = 0;
        if ($divisor > 0 && $value > 0) {
            $quotient = round($value / $divisor, $this->quotientPrecision);
        }
        return $quotient;
    }

    /**
     * Returns the dividend to use when calculating the new column value. Can
     * be overridden by descendent classes to customize behavior.
     *
     * @param Row $row The row being modified.
     * @return int|float
     */
    public function getDividend($row)
    {
        return $row->getColumn($this->columnValueToRead);
    }

    /**
     * Returns the divisor to use when calculating the new column value. Can
     * be overridden by descendent classes to customize behavior.
     *
     * @param Row $row The row being modified.
     * @return int|float
     */
    public function getDivisor($row)
    {
        if (!is_null($this->totalValueUsedAsDivisor)) {
            return $this->totalValueUsedAsDivisor;
        } else if ($this->getDivisorFromSummaryRow) {
            $summaryRow = $this->table->getRowFromId(DataTable::ID_SUMMARY_ROW);
            return $summaryRow->getColumn($this->columnNameUsedAsDivisor);
        } else {
            return $row->getColumn($this->columnNameUsedAsDivisor);
        }
    }
}
