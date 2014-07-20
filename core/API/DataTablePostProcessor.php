<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\API;

use Piwik\Common;
use Piwik\DataTable\BaseFilter;
use Piwik\API\DataTableManipulator\Flattener;
use Piwik\API\DataTableManipulator\LabelFilter;
use Piwik\API\DataTableManipulator\ReportTotalsCalculator;
use Piwik\DataTable\Filter\AddColumnsProcessedMetricsGoal;
use Piwik\DataTable;
use Piwik\DataTable\Map;
use Exception;

/**
 * TODO
 * TODO: all those who call requests within Piwik should disable post processing and apply it themselves (ie for all callers that use format=original,
 *       there should be no post processing. should be done only when returning data via the API.)
 */
class DataTablePostProcessor extends BaseFilter
{
    /**
     * Returns an array containing the information of the generic Filter
     * to be applied automatically to the data resulting from the API calls.
     *
     * Order to apply the filters:
     * 1 - Filter that remove filtered rows
     * 2 - Filter that sort the remaining rows
     * 3 - Filter that keep only a subset of the results
     * 4 - Presentation filters
     *
     * @return array  See the code for spec
     *
     * TODO: remove need for this method?
     */
    public static function getGenericFiltersInformation()
    {
        return array(
            array('Pattern',
                  array(
                      'filter_column'  => array('string', 'label'),
                      'filter_pattern' => array('string')
                  )),
            array('PatternRecursive',
                  array(
                      'filter_column_recursive'  => array('string', 'label'),
                      'filter_pattern_recursive' => array('string'),
                  )),
            array('ExcludeLowPopulation',
                  array(
                      'filter_excludelowpop'       => array('string'),
                      'filter_excludelowpop_value' => array('float', '0'),
                  )),
            array('AddColumnsProcessedMetrics',
                  array(
                      'filter_add_columns_when_show_all_columns' => array('integer')
                  )),
            array('AddColumnsProcessedMetricsGoal',
                  array(
                      'filter_update_columns_when_show_all_goals' => array('integer'),
                      'idGoal'                                    => array('string', AddColumnsProcessedMetricsGoal::GOALS_OVERVIEW),
                  )),
            array('Sort',
                  array(
                      'filter_sort_column' => array('string'),
                      'filter_sort_order'  => array('string', 'desc'),
                  )),
            array('Truncate',
                  array(
                      'filter_truncate' => array('integer'),
                  )),
            array('Limit',
                  array(
                      'filter_offset'    => array('integer', '0'),
                      'filter_limit'     => array('integer'),
                      'keep_summary_row' => array('integer', '0'),
                  )),
        );
    }

    /**
     * TODO
     */
    private $request;

    /**
     * TODO
     */
    private $apiModule;

    /**
     * TODO
     */
    private $apiAction;

    /**
     * TODO
     */
    public $resultDataTable;

    /**
     * TODO
     */
    private $otherPriorityFilters = array();

    /**
     * TODO
     */
    public function __construct($apiModule, $apiAction, $request = false)
    {
        if ($request === false) {
            $request = $_GET + $_POST;
        }

        $this->request = $request;
        $this->apiModule = $apiModule;
        $this->apiAction = $apiAction;
    }

    /**
     * TODO
     */
    public function filter($dataTable)
    {
        if (Common::getRequestVar('disable_post_processing', false, null, $this->request)) {
            $this->resultDataTable = $dataTable;
            return;
        }

        // TODO: deprecate 'disable_generic_filters' or provide backwards compatibility.
        $this->applyFlattener($dataTable);
        $this->applyReportTotalsCalculator($dataTable);
        $this->applyOtherPriorityFilters($dataTable);

        $this->applyExcludeLowPopulationFilters($dataTable);

        $this->decodeLabelsSafely($dataTable);

        $this->applyQueuedFilters($dataTable);

        $dataTable = $this->applyLabelFilter($dataTable);
        $this->applyPatternFilters($dataTable);

        $this->applyAddProcessedMetricsFilters($dataTable);

        $this->applySortFilter($dataTable);
        $this->applyTruncateFilter($dataTable);
        $this->applyLimitingFilter($dataTable);

        $this->applyQueuedFilters($dataTable); // redundant application in case previous filters queued more filters

        $this->applyColumnDeleteFilter($dataTable);

        $this->resultDataTable = $dataTable; // TODO: remove after changing all 'manipulators' to modify tables in-place
    }

    /**
     * Returns the value for the label query parameter which can be either a string
     * (ie, label=...) or array (ie, label[]=...).
     *
     * @param array $request
     * @return array
     */
    static public function getLabelFromRequest($request)
    {
        $label = Common::getRequestVar('label', array(), 'array', $request);
        if (empty($label)) {
            $label = Common::getRequestVar('label', '', 'string', $request);
            if (!empty($label)) {
                $label = array($label);
            }
        }

        $label = self::unsanitizeLabelParameter($label);
        return $label;
    }

    static public function unsanitizeLabelParameter($label)
    {
        // this is needed because Proxy uses Common::getRequestVar which in turn
        // uses Common::sanitizeInputValue. This causes the > that separates recursive labels
        // to become &gt; and we need to undo that here.
        $label = Common::unsanitizeInputValues($label);
        return $label;
    }

    /**
     * TODO
     */
    public function applyOtherPriorityFilters($dataTable)
    {
        foreach ($this->otherPriorityFilters as $filter) {
            $this->dataTable->filter($filter[0], $filter[1]);
        }
    }

    /**
     * TODO
     */
    public function applyFlattener($dataTable) {
        if (Common::getRequestVar('flat', '0', 'string', $this->request) == '1') {
            $flattener = new Flattener($this->apiModule, $this->apiAction, $this->request);
            if (Common::getRequestVar('include_aggregate_rows', '0', 'string', $this->request) == '1') {
                $flattener->includeAggregateRows();
            }
            $flattener->flatten($dataTable);
        }
    }

    /**
     * TODO
     */
    public function applyReportTotalsCalculator($dataTable) {
        if (1 == Common::getRequestVar('totals', '1', 'integer', $this->request)) {
            $genericFilter = new ReportTotalsCalculator($this->apiModule, $this->apiAction, $this->request);
            $genericFilter->calculate($dataTable);
        }
    }

    /**
     * TODO
     */
    public function decodeLabelsSafely($dataTable)
    {
        // we automatically safe decode all dataTable labels (against xss)
        $dataTable->filter('SafeDecodeLabel');
    }

    /**
     * TODO
     */
    public function applyQueuedFilters($dataTable)
    {
        if (Common::getRequestVar('disable_queued_filters', 0, 'int', $this->request) == 0) {
            $dataTable->applyQueuedFilters();
        }
    }

    /**
     * TODO
     */
    public function applyColumnDeleteFilter($dataTable) {
        $hideColumns = Common::getRequestVar('hideColumns', '', 'string', $this->request);
        $showColumns = Common::getRequestVar('showColumns', '', 'string', $this->request);

        $dataTable->filter('ColumnDelete', array($hideColumns, $showColumns, $deleteIfZeroOnly = false,
            $checkColumnDeleteTableMetadata = true)); // TODO
    }

    /**
     * TODO
     */
    public function applyLabelFilter($dataTable) {
        // TODO: make LabelFilter in-place

        // apply label filter: only return rows matching the label parameter (more than one if more than one label)
        $label = $this->getLabelFromRequest($this->request);
        if (!empty($label)) {
            $addLabelIndex = Common::getRequestVar('labelFilterAddLabelIndex', 0, 'int', $this->request) == 1;

            $filter = new LabelFilter($this->apiModule, $this->apiAction, $this->request);
            $dataTable = $filter->filter($label, $dataTable, $addLabelIndex);
        }

        return $dataTable;
    }

    /**
     * TODO
     */
    public function applyPatternFilters($dataTable) {
        $filterColumn = Common::getRequestVar('filter_column', 'label', 'string', $this->request);
        $filterPattern = Common::getRequestVar('filter_pattern', false, 'string', $this->request);

        if (!empty($filterPattern)) {
            $dataTable->filter('Pattern', array($filterColumn, $filterPattern));
        }

        $filterColumnRecursive = Common::getRequestVar('filter_column_recursive', 'label', 'string', $this->request);
        $filterPatternRecursive = Common::getRequestVar('filter_pattern_recursive', false, 'string', $this->request);

        if (!empty($filterPatternRecursive)) {
            $dataTable->filter('PatternRecursive', array($filterColumnRecursive, $filterPatternRecursive));
        }
    }

    /**
     * TODO
     */
    public function applyExcludeLowPopulationFilters($dataTable) {
        $excludeLowPopulationColumn = Common::getRequestVar('filter_excludelowpop', false, 'string', $this->request);
        $excludeLowPopulationValue = Common::getRequestVar('filter_excludelowpop_value', 0, 'float', $this->request);

        if (!empty($excludeLowPopulationColumn)) {
            $dataTable->filter('ExcludeLowPopulation', array($excludeLowPopulationColumn, $excludeLowPopulationValue));
        }
    }

    /**
     * TODO
     */
    public function applyAddProcessedMetricsFilters($dataTable) {
        // TODO: this query param has two functions: it enables the filter and tells whether to delete rows w/ no visit. it needs to be renamed.
        $shouldAddNormalProcessedMetrics = Common::getRequestVar('filter_add_columns_when_show_all_columns', false, $type = null, $this->request);
        if ($shouldAddNormalProcessedMetrics !== false) {
            $dataTable->filter('AddColumnsProcessedMetrics', array((int) $shouldAddNormalProcessedMetrics));
        }

        $shouldAddGoalProcessedMetrics = Common::getRequestVar('filter_update_columns_when_show_all_goals', false, 'integer', $this->request);
        if (!empty($shouldAddGoalProcessedMetrics)) {
            $idGoal = Common::getRequestVar('idGoal', AddColumnsProcessedMetricsGoal::GOALS_OVERVIEW, 'integer', $this->request);
            $dataTable->filter('AddColumnsProcessedMetricsGoal', array(true, $idGoal));
        }
    }

    /**
     * TODO
     */
    public function applySortFilter($dataTable) {
        $sortColumn = Common::getRequestVar('filter_sort_column', false, 'string', $this->request);
        $sortOrder = Common::getRequestVar('filter_sort_order', 'desc', 'string', $this->request);

        if (!empty($sortColumn)) {
            $dataTable->filter('Sort', array($sortColumn, $sortOrder));
        }
    }

    /**
     * TODO
     */
    public function applyTruncateFilter($dataTable) {
        $truncateAfter = Common::getRequestVar('filter_truncate', false, 'integer', $this->request);
        if (!empty($truncateAfter)) {
            $dataTable->filter('Truncate', array($truncateAfter));
        }
    }

    /**
     * TODO
     */
    public function applyLimitingFilter($dataTable) {
        $filterOffset = Common::getRequestVar('filter_offset', 0, 'integer', $this->request);
        $filterLimit = Common::getRequestVar('filter_limit', false, 'integer', $this->request);
        $keepSummaryRow = Common::getRequestVar('keep_summary_row', 0, 'integer', $this->request);

        if (!empty($filterLimit)) {
            $dataTable->filter('Limit', array($filterOffset, $filterLimit, $keepSummaryRow));
        }
    }
}