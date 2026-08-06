<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\CommonLogic\DataSheets\DataAggregation;
use exface\Core\CommonLogic\Model\RelationPath;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Widgets\WidgetFunctionUnknownError;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryDataConfiguratorTrait;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Widgets\DataTable;
use exface\Core\Widgets\DataTableConfigurator;
use exface\Core\Widgets\Dialog;
use exface\Core\Interfaces\Widgets\iCanEditData;
use exface\Core\DataTypes\ComparatorDataType;

/**
 * 
 * @method \exface\Core\Widgets\DataConfigurator getWidget()
 * 
 * @author Andrej Kabachnik
 *
 */
class UI5DataConfigurator extends UI5Tabs
{
    use JqueryDataConfiguratorTrait {
        buildJsDataGetter as buildJsDataGetterViaTrait;
        buildJsResetter as buildJsResetterViaTrait;
        buildJsFilterGetter as buildJsFilterGetterViaTrait;
    }
    
    const EVENT_BUTTON_OK = 'ok';
    const EVENT_BUTTON_CANCEL = 'cancel';
    const EVENT_BUTTON_RESET = 'reset';
    
    const MODEL_NAME_FOR_CONFIG = 'configurator';
    
    private $include_filter_tab = true;
    
    private $include_columns_tab = false;
    
    private $modelNameForConfig = null;
       
    /**
     * Can't use JqueryDataConfiguratorTrait::init() here because it would call registerFiltersWithApplyOnChange() too
     * early: before the controller was initialized! Instead, the method will be called in buildJsConstructor()
     * 
     * @see JqueryDataConfiguratorTrait::init()
     */
    protected function init()
    {
        parent::init();
    }
    
    /**
     * 
     * @param boolean $true_or_false
     * @return \exface\UI5Facade\Facades\Elements\UI5DataConfigurator
     */
    public function setIncludeFilterTab($true_or_false)
    {
        $this->include_filter_tab = BooleanDataType::cast($true_or_false);
        return $this;
    }
    
    /**
     * 
     * @return bool
     */
    public function getIncludeFilterTab() : bool
    {
        return $this->include_filter_tab;
    }
    
    protected function hasTabFilters() : bool
    {
        return $this->getIncludeFilterTab();
    }
    
    protected function hasTabAdvancedSearch() : bool
    {
        if ($this->getWidget()->isDisabled() === true) {
            return false;
        }
        return true;
    }
    
    protected function hasTabSorters() : bool
    {
        if ($this->getWidget()->isDisabled() === true) {
            return false;
        }
        return true;
    }

    /**
     * Returns the ui5 Id of the setups table (if it exists)
     * @return bool
     */
    public function getSetupsTableId() : ?string
    {
        if ($this->hasTabSetups()) {
            $setupsTable = $this->getWidget()->getSetupsTab()->getWidgetFirst();
            return $this->getFacade()->getElement($setupsTable)->getId();
        }
        else {
            return null;
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5Tabs::buildJsConstructor()
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {        
        $controller = $this->getController();
        
        $dataElement = $this->getDataElement();
        if ($dataElement instanceof UI5DataTable) {
            // Need to add a controller variable here because the configurator constructor is
            // rendered BEFORE the constrcutor of the table.
            if ($controller->hasDependent(UI5DataTable::CONTROLLER_VAR_OPTIONAL_COLS, $dataElement) === false) {
                $controller->addDependentObject(UI5DataTable::CONTROLLER_VAR_OPTIONAL_COLS, $dataElement, 'null');
            } 
            $refreshP13n = $dataElement->buildJsRefreshPersonalization();
        }
        
        $this->registerFiltersWithApplyOnChange();
        
        $okScript = <<<JS
                
                    oEvent.getSource().close();
                    {$refreshP13n}
                    {$dataElement->buildJsRefresh()};

JS;
        $controller->addOnEventScript($this, self::EVENT_BUTTON_OK, $okScript);
        $controller->addOnEventScript($this, self::EVENT_BUTTON_CANCEL, 'oEvent.getSource().close();');
        $controller->addOnEventScript($this, self::EVENT_BUTTON_RESET, $this->buildJsResetter() . '; oEvent.getSource().setShowResetEnabled(true).close()');
        
        $this->registerRefreshListeners($oControllerJs);
        
        $refreshSetupsJs = '';
        if ($this->hasTabSetups()) {
            $setupsTable = $this->getWidget()->getSetupsTab()->getWidgetFirst();
            $setupsTable->setAutoloadData(false);
            $refreshSetupsJs = $this->getFacade()->getElement($setupsTable)->buildJsRefresh();
                
            // check if the indexedDb contains stored setup for this DataTable
            // if so, automatically apply it
            $this->getController()->addOnShowViewScript( <<<JS
                
                (function (){ 
                    // if a setup exists for this table in the indexedDB, apply it 
                    exfSetupManager.dexie.getCurrentSetup('{$dataElement->getWidget()->findUiContainer()->getSlug()}', '{$dataElement->getWidget()->getIdWithinUiContainer()}', '{$dataElement->getWidget()->getMetaObject()->getId()}')
                    .then(entry => {
                        if (entry) {
                            {$dataElement->buildJsCallFunction('apply_setup', ['localStorage'])}
                        }
                    });
                })();             
JS
            ); 

            // track changes made to the config
            $this->getController()->addOnShowViewScript( <<<JS
                {$dataElement->buildJsCallFunction('track_setup_changes')}
JS
           , false); 
        }


        
        return <<<JS

        new sap.m.P13nDialog("{$this->getId()}", {
            ok: {$controller->buildJsEventHandler($this, self::EVENT_BUTTON_OK, true)},
            cancel: {$controller->buildJsEventHandler($this, self::EVENT_BUTTON_CANCEL, true)},
            showReset: true,
            showResetEnabled: true,
            reset: {$controller->buildJsEventHandler($this, self::EVENT_BUTTON_RESET, true)},
            afterOpen: function(oEvent) {
                $refreshSetupsJs
            },
            panels: [
                {$this->buildJsPanelsConstructors()}
            ]
        })
        .setModel({$this->buildJsCreateModel()}, "{$this->getModelNameForConfig()}")
        .setModel({$this->buildJsCreateModel()}, "{$this->getModelNameForConfig()}_initial")
        
JS;
    }
    
    public function registerRefreshListeners(string $oControllerJs) : void
    {
        $onActionEffectJs = $this->getFacade()->getElement($this->getWidget()->getWidgetConfigured())->buildJsRefresh(true, $oControllerJs);
        // If the configured widget is an editable data widget, only react to action effects if
        // no unsaved changes exist or the widget is explicitly required to refresh (by button config)!
        $dataElement = $this->getDataElement();
        $dataWidget = $dataElement->getWidget();
        if ($dataWidget instanceof iCanEditData && $dataWidget->isEditable() && method_exists($dataElement, 'buildJsEditableChangesChecker')) {
            $onActionEffectJs = <<<JS

                    if (
                        ! {$dataElement->buildJsEditableChangesChecker()}
                        || ((oParams || {}).refresh_widgets || []).indexOf('{$dataWidget->getId()}') !== -1
                    ) { 
                        {$onActionEffectJs} 
                    }
JS;
        }
        // If we are inside a dialog, make sure the dialog is still in the DOM before performing the
        // action effects!
        if ($dialog = $this->getWidget()->getParentByClass(Dialog::class)) {
            $dialogElem = $this->getFacade()->getElement($dialog);
            $onActionEffectJs = <<<JS

                if (
                    {$dialogElem->getController()->getView()->buildJsViewGetter($dialogElem)} !== undefined 
                    && {$dialogElem->buildJsCheckDialogClosed()} !== true
                ) { 
                    {$onActionEffectJs} 
                }
JS;
        }
        
        $this->getController()->addOnInitScript($this->buildJsRegisterOnActionPerformed($onActionEffectJs, false));
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsPanelsConstructors() : string
    {
        return <<<JS

                {$this->buildJsTabSetups()}
                {$this->buildJsTabFilters()}
                {$this->buildJsTabSorters()}
                {$this->buildJsTabSearch()}
                {$this->buildJsTabColumns()}
JS;
    }
    
    /**
     * Returns the JS to initialize the inner JSONModel (returning that model)
     * 
     * @return string
     */
    protected function buildJsCreateModel() : string
    {
        return <<<JS
function(){
            var oModel = new sap.ui.model.json.JSONModel();
            var columns = {$this->buildJsonModelForColumns()};
            var sortables = {$this->buildJsonModelForSortables()};
            var searchables = {$this->buildJsonModelForSearchables()}
            var data = {
                "columns": columns,
                "sortables": sortables,
                "searchables": searchables,
                "sorters": [{$this->buildJsonModelForInitialSorters()}],
                "header_filters": []
            }
            oModel.setData(data);
            return oModel;        
        }()
JS;
    }
    
    /**
     * Returns JavaScript code to reset all visible, non-optional filters to empty values.
     * This is used before applying a new set of filter values, ensuring all filters are cleared first.
     *
     * @return string JavaScript function to clear visible, non-optional filters
     */
    public function buildJsResetVisibleFilters() : string
    {
        $resetJs = '';
        foreach ($this->getWidget()->getFilters() as $filter) {
            $filterElement = $this->getFacade()->getElement($filter);
            
            // only reset visible, non-optional filters
            if (! $filterElement->isVisible() || $filter->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL) {
                continue;
            }

            // Range filters expand into two inner from/to filter elements; regular filters produce one
            $elementsToProcess = $filterElement instanceof UI5RangeFilter
                ? $filterElement->getInnerFilterElements()
                : [$filterElement];

            foreach ($elementsToProcess as $el) {
                $resetter = $el->buildJsResetter();
                $resetJs .= <<<JS
try {
    $resetter;
} catch (error) {
    console.warn("Could not reset filter value: ", error);
}
JS;
            }
        }

        return <<<JS
function() {
    // reset all visible filters 
    {$resetJs}
}
JS;
    }

    /**
     * Returns a JS function expression that, when called with an array of condition objects and values, sets the provided filter values.
     *
     * Each item in the array must be a condition object. Two formats are supported: Regular conditions (filters with a simple attribute alias)
     * and nested condition groups (filters with a `condition_group` UXON). For normal filters, the `expression` and `comparator` properties 
     * are compared to determine which filter to set. For nested groups, the whole group is compared.
     *
     * Only visible, non-optional filters are considered. After applying all values, the configured widget
     * is refreshed to load data with the new filter state.
     * 
     * Example usage:
     * 
     * var aVals = [
     *   {expression: "STATUS", comparator: "=", value: "1"},
     *   {group: {nested: true, group: [{expression: "A"}, {expression: "B"}]}, value: "foo"}
     * ];
     * var fnSet = {$this->buildJsVisibleFilterValueSetter()};
     * fnSet(aVals);
     *
     * @return string JS function expression
     */
    public function buildJsVisibleFilterValueSetter() : string
    {
        $conditions = '';
        $busyControlIds = [];
        $refreshWidget = $this->getFacade()->getElement($this->getWidget()->getWidgetConfigured())->buildJsRefresh();
        
        foreach ($this->getWidget()->getFilters() as $filter) {
            
            /** @var \exface\Core\Widgets\Filter $filter */
            $filterElement = $this->getFacade()->getElement($filter);
            $alias = $filter->getAttributeAlias();
                
            // only consider visible filters
            if (! $filterElement->isVisible() || $filter->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL) {
                continue;
            }

            // Range filters expand into two inner from/to filter elements; regular filters produce one
            $elementsToProcess = $filterElement instanceof UI5RangeFilter
                ? $filterElement->getInnerFilterElements()
                : [$filterElement];

            // build JS if/else conditions to set the data of each visible filter element using its ValueSetter
            foreach ($elementsToProcess as $el) {
                // get the inner input element ID for the busy check (those are the ones that actaully are set busy)
                $inputEl = $el instanceof UI5Filter ? $this->getFacade()->getElement($el->getWidget()->getInputWidget()) : $el;
                $busyControlIds[] = $inputEl->getId();
                $setter = $el->buildJsValueSetter('mVal');
                $comparatorGetter = $el->buildJsComparatorGetter();
                $prefix = $conditions === '' ? 'if' : ' else if';
                
                if ($filter->hasCustomConditionGroup()) {
                    // For filters with custom (nested) condition groups, compare by group structure instead of alias
                    $expectedGroupUxon = $filter->getCustomConditionGroup()->exportUxonObject()->toJson();
                    $matchCondition = "oCondition.nested && exfTools.data.compareJSONObjects(oCondition.group, {$expectedGroupUxon}, ['value'])";
                    $errorMessage = 'Error setting filter value for nested condition group from widget setup: ';
                } else {
                    // otherwise chekc for alias and comparator
                    $matchCondition = "oCondition.expression === {$this->escapeString($alias)} && oCondition.comparator === {$comparatorGetter}";
                    $errorMessage = "Error setting filter value for filter with alias {$alias} from widget setup: ";
                }

                $conditions .= <<<JS
{$prefix} ({$matchCondition}) {
    try {
        {$setter};
    } catch (error) {
        console.error("{$errorMessage}", error);
    }
}
JS;
            }
        }
        $busyControlIdsJs = json_encode(array_values(array_unique($busyControlIds)));

        return <<<JS
function(aValues) {

    // set new values
    aValues.forEach(function(oCondition) {
        var mVal = oCondition.value;
        {$conditions}
    });

    // wait until all updated filter controls are not busy anymore
    var aWaitControlIds = {$busyControlIdsJs} || [];
    var iWaitStepMs = 50;

    var fnHasBusyControls = function() {
        return aWaitControlIds.some(function(sId) {
            var oControl = sap.ui.getCore().byId(sId);
            return oControl && typeof oControl.getBusy === 'function' && oControl.getBusy() === true;
        });
    };

    var fnRefreshWhenReady = function() {
        if (!fnHasBusyControls()) {
            // refresh the widget after values have been set to request data with new filters
            {$refreshWidget}
            return;
        }
        setTimeout(fnRefreshWhenReady, iWaitStepMs);
    };

    setTimeout(fnRefreshWhenReady, 0);
}
JS;
    }

               
    /**
     * 
     * @return string
     */
    protected function buildJsonModelForInitialSorters() : string
    {
        $js = '';
        $operations = [SortingDirectionsDataType::ASC => 'Ascending', SortingDirectionsDataType::DESC => 'Descending'];
        foreach ($this->getWidget()->getDataWidget()->getSorters() as $sorter) {
            $js .= <<<JS

                    {attribute_alias: "{$sorter->getProperty('attribute_alias')}", direction: "{$operations[strtoupper($sorter->getProperty('direction'))]}"},
JS;
        }
        return $js;
    }

    /**
     *
     * @return string
     */
    protected function buildJsTabSetups() : string
    {
        if (! $this->hasTabSetups()) {
            return '';
        }
        $tab = $this->getWidget()->getSetupsTab();
        // TODO prevent autoloading all setups when the configured table is rendered. Only load the setups
        // when they are really needed - e.g. when the configurator is opened or the setups selection menu
        // is opened.
        // We could use $table->setAutoloadData(false) and add a $table->buildJsRefreshScript() to some callback
        // for opening the controls above.
        $tabEl = $this->getFacade()->getElement($tab);
        return <<<JS

                new exface.openui5.P13nLayoutPanel({
                    title: {$this->escapeString($tab->getCaption())},
                    content: [
                       {$tabEl->buildJsChildrenConstructors()}
                    ]
                }),
JS;

    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsTabFilters() : string
    {
        if (! $this->getIncludeFilterTab()) {
            return '';
        }
        
        $visible = $this->getWidget()->getFilterTab()->countWidgetsVisible() === 0 ? 'visible: false,' : '';
        
        return <<<JS

                new exface.openui5.P13nLayoutPanel({
                    title: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.FILTERS')}",
                    {$visible}
                    content: [
                        new sap.ui.layout.Grid({
                            defaultSpan: "L6 S12",
                            content: [
                                {$this->buildJsFilters()}
        					]
                        })
                    ]
                }),
JS;
        
    }
           
    /**
     * 
     * @return string
     */
    protected function buildJsTabSorters() : string
    {
        return <<<JS

                new sap.m.P13nSortPanel("{$this->getIdOfSortPanel()}", {
                    title: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.SORTING')}",
                    visible: true,
                    type: "sort",
                    layoutMode: "Desktop",
                    items: {
                        path: '{$this->getModelNameForConfig()}>/sortables',
                        template: new sap.m.P13nItem({
                            columnKey: "{{$this->getModelNameForConfig()}>attribute_alias}",
                            text: "{{$this->getModelNameForConfig()}>caption}"
                        })
                    },
                    sortItems: {
                        path: '{$this->getModelNameForConfig()}>/sorters',
                        template: new sap.m.P13nSortItem({
                            columnKey: "{{$this->getModelNameForConfig()}>attribute_alias}",
                            operation: "{{$this->getModelNameForConfig()}>direction}"
                        })
                    },
                    updateSortItem: function(oEvent) {
                        // otherwise change listener for property is not fired
                        var oModel = this.getModel("{$this->getModelNameForConfig()}");
                        oModel.refresh(true); 
                    },
                    addSortItem: function(oEvent) {
            			var oParameters = oEvent.getParameters();
                        var oModel = this.getModel("{$this->getModelNameForConfig()}");
            			var aSortItems = oModel.getProperty("/sorters");
            			oParameters.index > -1 ? aSortItems.splice(oParameters.index, 0, {
            				attribute_alias: oParameters.sortItemData.getColumnKey(),
            				direction: oParameters.sortItemData.getOperation()
            			}) : aSortItems.push({
            				attribute_alias: oParameters.sortItemData.getColumnKey(),
            				direction: oParameters.sortItemData.getOperation()
            			});
            			oModel.setProperty("/sorters", aSortItems);
                        oModel.refresh(true); 
            		},
                    removeSortItem: function(oEvent) {
            			var oParameters = oEvent.getParameters();
            			var oModel = this.getModel("{$this->getModelNameForConfig()}");
            			if (oParameters.index > -1) {
            				var aSortItems = this.getModel("{$this->getModelNameForConfig()}").getProperty("/sorters");
            				aSortItems.splice(oParameters.index, 1);
            				oModel.setProperty("/sorters", aSortItems);
                            oModel.refresh(true);
            			}
            		}
                }),
JS;
    }
        
    /**
     * 
     * @return string
     */
    protected function buildJsTabColumns() : string
    {
        if ($this->hasTabColumns() === false) {
            return '';
        }
        
        return <<<JS

                new sap.m.P13nColumnsPanel('{$this->getId()}_ColumnsPanel', {
                    title: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.COLUMNS')}",
                    visible: true,
                    changeColumnsItems: function(oEvent){
                        var aItems = oEvent.getParameters().items;
                        var oModel = oEvent.getSource().getModel('{$this->getModelNameForConfig()}');
                        var aNewColModel = [];
                        aItems.forEach(oItem => {
                            oModel.getData()['columns'].forEach(oColConf => {
                                if (oColConf.column_id === oItem.columnKey) {
                                    oColConf.visible = oItem.visible;
                                    aNewColModel.push(oColConf);
                                    return;
                                }
                            });
                        });
                        oModel.setProperty('/columns', aNewColModel);
                        oModel.refresh(true);
                    },
                    type: "columns",
                    items: {
                        path: '{$this->getModelNameForConfig()}>/columns',
                        template: new sap.m.P13nItem({
                            columnKey: "{{$this->getModelNameForConfig()}>column_id}",
                            text: "{{$this->getModelNameForConfig()}>caption}",
                            visible: "{{$this->getModelNameForConfig()}>visible}"
                        })
                    },
                    beforeNavigationTo: function(oEvent) {
                        var fnUpdateColumns = this.data('_exfTabColumnsUpdate');
                        if (typeof fnUpdateColumns === 'function') {
                            fnUpdateColumns.call(this, false);
                        }
                    }
                })
                .data('_exfTabColumnsUpdate', {$this->buildJsTabColumnsUpdateFunction()})
                ,
JS;
    }
    
    protected function buildJsTabColumnsUpdateFunction() : string
    {
        return <<<JS
function(bResetSelection) {
    /* This script sorts the columns in the panel's list to be sorted exactly the way, they
     * are positioned in the table - regardless of their visibility. By default in UI5, unchecked
     * columns are placed at the end of the the list. This forces the user to move them
     * after enabling. This fix makes sure, the position of the column is kept when enabling/disabling
     * and allows table designers to position optional columns meaningfully.
     */
    var oPanel = this;

    if (bResetSelection === true) {
        bResetSelection = function(oItem, oColConfig, iItemIdx) {
            oItem.persistentSelected = oColConfig.visibleInitially;
            oColConfig.visible = oColConfig.visibleInitially;
            oItem.persistentIndex = iItemIdx;
        };
    } else {
        bResetSelection = function() {};
    }

    // settimeout needed here bc. otherwise the data is not there yet,
    // and changes are then only applied when panel is openend for the second time
    setTimeout(function(){
        try {
                let oTable = null;
                if (oPanel.getAggregation('content')[1] !== undefined){
                    oTable = oPanel.getAggregation('content')[1].getAggregation('content')[0];
                }
                else{
                    // UI5-Upgrade - structure changed, need to get table content differently
                    oTable = oPanel.getAggregation('content')[0];
                }
                var oTableModel = oTable.getModel();
                var oConfigModel = oPanel.getModel('{$this->getModelNameForConfig()}');
                if (oTableModel === undefined || oConfigModel === undefined) return;
                
                    try {
                        var aColsConfig = oConfigModel.getProperty('/columns');
                        
                        // Set toggleability for hidden_if columns in configurator:
                        // a column with a hidden_if should only appear in the configurator (be toggleable)
                        // if its condition resolves to false at runtime (the column is not hidden; so its either optional or visible and MUST be toggleable).
                        aColsConfig.forEach(function(oColConfig) {
                            if (oColConfig.has_hidden_if) {
                                var oColElement = sap.ui.getCore().byId(oColConfig.column_id);
                                var fnEvalHiddenIf = oColElement && typeof oColElement.data === 'function' ? oColElement.data('_exfHiddenIfEval') : null;
                                var bHidden = false;

                                // get the hidden_if evaluator function from the column element and call it
                                if (typeof fnEvalHiddenIf === 'function') {
                                    try {
                                        bHidden = fnEvalHiddenIf() === true;
                                    } catch (e) {
                                        console.warn('Could not evaluate hidden_if for column ' + oColConfig.column_id + ': ', e);
                                        bHidden = false;
                                    }
                                }
                                // show column in configurator, only if hidden_if is false (column is not hidden)
                                oColConfig.toggleable = ! bHidden;
                            }
                        });
                        
                        // only use items that are toggleable
                        var oVisibleFilter = new sap.ui.model.Filter("toggleable", sap.ui.model.FilterOperator.EQ, true);
                        oPanel.getBinding("items").filter(oVisibleFilter);
                        
                        var aItems = oTableModel.getProperty('/items');
                        var aItemsNew = [];
                        
                        aColsConfig.forEach(function(oColConfig){
                            aItems.forEach(function(oItem, iItemIdx){
                                if (oItem.columnKey === oColConfig.column_id) {
                                    oItem.persistentSelected = oColConfig.visible;
                                    bResetSelection(oItem, oColConfig, iItemIdx);
                                    aItemsNew.push(oItem);
                                    return;
                                }
                            })
                        });
                        oTableModel.setProperty('/items', aItemsNew);
                        // update counts of selected items, else the counter is wrong after a reset
                        oPanel._updateCounts(aItemsNew);
                    } catch (e) {
                        console.warn('Cannot properly sort columns for personalization - using default sorting: ', e);
                    }
            } 
        catch (e) {
            console.warn('Cannot properly sort columns for personalization - using default sorting: ', e);
        }
    }, 0); 
}
JS;
    }
        
    protected function buildJsTabSearch()
    {
        return <<<JS
                function() {
                    var oPanel = new sap.m.P13nFilterPanel("{$this->getIdOfSearchPanel()}", {
                        title: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.ADVANCED_SEARCH')}",
                        visible: true,
                        layoutMode: "Desktop",
                        addFilterItem: function(oEvent){
                            var oParameters = oEvent.getParameters();
                            var oFilterItem = new sap.m.P13nFilterItem(oParameters.filterItemData.mProperties);
                            oEvent.getSource().insertFilterItem(oFilterItem, oParameters.index);
                        },
                        updateFilterItem: function(oEvent){
                            var oParameters = oEvent.getParameters();
                            var oPanel = oEvent.getSource();
                            var idx = oParameters.index;
                            var oFilterItem = new sap.m.P13nFilterItem(oParameters.filterItemData.mProperties);
                            oPanel.removeFilterItem(idx);
                            oPanel.insertFilterItem(oFilterItem, idx);
                        },
                        removeFilterItem: function(oEvent){
                            var oParameters = oEvent.getParameters();
                            oEvent.getSource().removeFilterItem(oParameters.index);
                        },
                        items: {
                            path: '{$this->getModelNameForConfig()}>/searchables',
                            template: new sap.m.P13nItem({
                                columnKey: "{{$this->getModelNameForConfig()}>attribute_alias}",
                                text: "{{$this->getModelNameForConfig()}>caption}"
                            })
                        },
                        filterItems: [
    
                        ]
                    });

                    oPanel.setIncludeOperations(["Contains", "EQ", "LT", "LE", "GT", "GE"]);
                    oPanel.setExcludeOperations(["Contains", "EQ", "LT", "LE", "GT", "GE"]);
                    return oPanel;
                }(),
JS;
    }
              
    /**
     * 
     * @return string
     */
    protected function buildJsonModelForColumns() : string
    {
        $data = [];
        $widget = $this->getWidget();

        if ($this->hasTabColumns() === true) {
            $cols = $widget->getDataWidget()->getColumns();
            // Add all optional columns from the configurator here
            if ($widget instanceof DataTableConfigurator && $widget->hasOptionalColumns()) {
                $cols = array_merge($cols, $widget->getOptionalColumns());
            }
            foreach ($cols as $col) {
                $data[] = [
                    "attribute_alias" => $col->getAttributeAlias(),
                    "column_id" => $this->getFacade()->getElement($col)->getId(),
                    "column_name" => $col->getDataColumnName(),
                    "caption" => $col->getCaption(),
                    "visible" => $col->isHidden() || $col->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL ? false : true,
                    "visibleInitially" => $col->isHidden() || $col->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL ? false : true,
                    "toggleable" => $col->isHidden() ? false : true,
                    "has_hidden_if" => $col->getHiddenIf() !== null
                ];
            }
        }
        return json_encode($data);
    }

    protected function buildJsonModelForSearchables() : string
    {
        $data = [];
        $widget = $this->getWidget();
        $filterableAliases = [];

        // Allow filtering over all columns - directly visible or optional column selectable on-demand
        if ($this->hasTabColumns() === true) {
            $cols = $widget->getDataWidget()->getColumns();
            // Add all optional columns from the configurator here
            if ($widget instanceof DataTableConfigurator && $widget->hasOptionalColumns()) {
                $cols = array_merge($cols, $widget->getOptionalColumns());
            }
            foreach ($cols as $col) {
                // columns that aren't filterable or are hidden and not the UID attribute should not appear in the filter tab
                if (! $col->isFilterable() || ($col->isHidden() && ! ($col->isBoundToAttribute() && $col->getAttribute()->isUidForObject()))) {
                    continue;
                }
                $filterableAliases[] = $col->getAttributeAlias();
                // Use captions as keys avoid duplicates
                $data[$col->getCaption()] = [
                    "attribute_alias" => $col->getAttributeAlias(),
                    "caption" => $col->getCaption()
                ];
            }
        }

        // Also add all regular filters to the advanced search filters
        foreach ($widget->getFilters() as $filter) {
            // Prevent duplicates
            switch (true) {
                // If this caption is already in the list (same caption simply is useless even if the aliases are different)
                case array_key_exists($filter->getCaption(), $data):
                // If this alias is already in the list
                case in_array($filter->getAttributeAlias(), $filterableAliases):
                // Skip hidden filters in general
                case ! $this->getFacade()->getElement($filter)->isVisible():
                    continue 2;
            }
            $filterAttr = $filter->getAttribute();
            $filterAttrAlias = $filter->getAttributeAlias();
            switch (true) {              
                case $filterAttr === null:
                    // If the filter has no attribute, skip it
                    continue 2;
                // Relation filters will produce InputComboTables, so to transform them to a text-filter, we
                // need to filter over the corresponding LABEL. This will not work on aggregations though.
                case $filterAttr->isRelation() && ! DataAggregation::hasAggregation($filterAttrAlias):
                    $filterRightObj = $filterAttr->getRelation()->getRightObject();
                    if ($filterRightObj->hasLabelAttribute()) {
                        $data[$filter->getCaption()] = [
                            "attribute_alias" => RelationPath::join($filterAttr->getAliasWithRelationPath(), $filterRightObj->getLabelAttributeAlias()),
                            "caption" => $filter->getCaption()
                        ];
                    } else {
                        // If we do not have a LABEL - what should we filter over? The UID?
                        // Skip this case for now
                        continue 2;
                    }
                    break;
                // Regular filters can be added as-is
                default:
                    $data[$filter->getCaption()] = [
                        'attribute_alias' => $filter->getAttributeAlias(),
                        "caption" => $filter->getCaption()
                    ];
                    break;
            }
        }
        // Sort sortables by caption
        ksort($data);
        
        return json_encode(array_values($data), JSON_UNESCAPED_UNICODE);
    }
    
    
    /**
     * 
     * @return string
     */
    protected function buildJsonModelForSortables() : string
    {
        $widget = $this->getWidget();
        $data = [];
        $sorters = [];
        $table = $widget->getDataWidget();
        $cols = $table->getColumns();
        foreach ($table->getSorters() as $sorter) {
            $sorters[] = $sorter->getProperty('attribute_alias');
            $data[] = [
                "attribute_alias" => $sorter->getProperty('attribute_alias'),
                "caption" => $this->getSorterCaption($sorter, $cols)
            ];
        }
        // Also add all optional columns from the configurator - if they are sortable, of course.
        if ($widget instanceof DataTableConfigurator && $widget->hasOptionalColumns()) {
            $cols = array_merge($cols, $widget->getOptionalColumns());
        }
        foreach ($cols as $col) {
            if (! $col->isSortable()) {
                continue;
            }
            if (in_array($col->getAttributeAlias(), $sorters)) {
                continue;
            }
            $data[] = [
                "attribute_alias" => $col->getAttributeAlias(),
                "caption" => $col->getCaption()
            ];
        }
        return json_encode($data);
    }

    /**
     * Gets the caption for a sorter.
     * 
     * The caption is determined in the following way:
     * 1. If a column of given columns has the same attribute alias as the sorter, take the caption from that column
     * 2. If it is a related attribute, the attribute name and the related object name (if not the same) is taken: "Name (ObjectName)"
     * 3. Else take the attribute name.
     * 
     * @param $sorter
     * @param $columns
     * @return string
     */
    protected function getSorterCaption($sorter, $columns) : string 
    {
        $alias = $sorter->getProperty('attribute_alias');
        $attribute = $this->getMetaObject()->getAttribute($sorter->getProperty('attribute_alias'));

        // Take the caption from the column if it exists
        $column = current(array_filter($columns, fn($c) => $c->getAttributeAlias() === $alias));
        $caption = $column ? $column->getCaption() : null;
        if ($caption) {
            return $caption;
        }
        
        // If it is a related attribute: e.g.
        // - TYPE__NAME, tell the user, which object it belongs to - `Name (Type)`
        // - PRODUCT__PRODUCT_GROUP__NAME - `Name (Product group)`
        // - PRODUCT__PRODUCT_GROUP__LABEL - here the LABEL is already "Product group", so we skip the parentheses and
        // just yield `Product group`
        $attrName = $attribute->getName();
        
        if ($attribute->isRelated()) {
            $objName = $attribute->getRelationPath()->getRelationLast()->getName();

            return ($objName !== $attrName)
                ? $attrName . ' (' . $attribute->getObject()->getName() . ')'
                : $attrName;
        }

        return $attrName;
    }
    
    /**
     * Returns an comma separated list of control constructors for filters
     * 
     * @return string
     */
    public function buildJsFilters() : string
    {
        $filters = '';
        $filters_hidden = '';
        foreach ($this->getWidget()->getFilters() as $filter) {
            $filter_element = $this->getFacade()->getElement($filter);
            switch(true) { 
                case ! $filter_element->isVisible(): 
                    $filters_hidden .= $this->buildJsFilter($filter_element);
                    break;
                case $filter->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL:
                    $filter->setHidden(true);
                    $filters_hidden .= $this->buildJsFilter($filter_element);
                    // Optional filters will be rendered in `buildJsonModelForSearchables()` only
                    break;
                default:
                    $filters .= $this->buildJsFilter($filter_element);
                    break;
            }
        }
        return $filters . $filters_hidden;
    }

    /**
     * Returns the constructors for messages for this configurator (delimited AND ending with a comma)
     * 
     * @param string $oControllerJs
     * @return string
     */
    public function buildJsMessages(string $oControllerJs = 'oController') : string
    {
        $js = '';
        foreach ($this->getWidget()->getMessageList()->getMessages() as $msgWidget) {
            $js .= $this->getFacade()->getElement($msgWidget)->buildJsConstructor($oControllerJs) . '.removeStyleClass("sapUiResponsiveMargin").addStyleClass("sapUiSmallMargin"),' . PHP_EOL;
        }
        return $js;
    }
    
    /**
     * Returns a constructor for the give filter element followed by a comma.
     * 
     * The constructor for a filter element within a data configurator is different from a
     * filter's general constructor!
     * 
     * @param UI5Filter $element
     * @return string
     */
    protected function buildJsFilter(UI5Filter $element) : string
    {
        $primaryActionCall = $this->getFacade()->getElement($this->getWidget()->getWidgetConfigured())->buildJsRefresh();
        $inputEl = $this->getFacade()->getElement($element->getWidget()->getInputWidget());
        
        // Trigger the primary action by enter on any input, but with some exceptions
        // @see similar logic in UI5Form::registerSubmitOnEnter()
        
        // sap.m.Input fires enter events on itself when an autosuggest item is
        // selected via enter, so we need to wrap the primary action call in an
        // IF here and find out if the event was triggered in the autosuggest.
        // Fortunately the Input loses its focus-frame (CSS class `sapMFocus`)
        // when navigating to the autosuggest, so we check for its presence. If
        // the control does not have the class, we don't trigger the primary action
        // but return the focus to the Input with a little hack. Now if the user
        // presses enter again, the primary action will be triggered
        if ($inputEl instanceof UI5InputComboTable) {
            $primaryActionCall = <<<JS
            
(function(){
    var oInput = oEvent.srcControl;
    if (! oInput.$().hasClass('sapMFocus')){
        oInput.$().find('input').focus();
        return;
    }
    $primaryActionCall
})();

JS;
        }
        
        if ($element instanceof UI5RangeFilter) {
            $element->addPseudoEventHandler('onsapenter', $primaryActionCall);
        } else {
            // If the control has an explicit setting for focus management, pay attention to it
            if (! (method_exists($inputEl, 'getAdvanceFocusOnEnter') && $inputEl->getAdvanceFocusOnEnter() === true)) {
                $inputEl->addPseudoEventHandler('onsapenter', $primaryActionCall);
            }
        }
        
        return <<<JS
        
                        new sap.ui.layout.VerticalLayout({
                            width: "100%",
                            {$element->buildJsPropertyVisibile()}
                            content: [
                        	    {$element->buildJsConstructor()}
                            ]
                        }).addStyleClass('{$element->buildCssWidgetClass()}'),
                        
JS;
    }
          
    /**
     * 
     * @return string
     */
    public function getIdOfSortPanel() : string
    {
        return $this->getId() . '_SortPanel';
    }

    /**
     * 
     * @return string
     */
    public function getIdOfColumnsPanel() : string
    {
        return $this->getId() . '_ColumnsPanel'; 

    }
    
    /**
     * 
     * @return string
     */
    public function getIdOfSearchPanel() : string
    {
        return $this->getId() . '_AdvancedSearchPanel';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see JqueryDataConfiguratorTrait::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null, bool $unrendered = false)
    {
        // If the configurator is disabled completely, it should always work in unrendered mode
        if ($this->getWidget()->isDisabled()) {
            return $this->buildJsDataGetterViaTrait($action, true);
        }

        if ($unrendered === true || $this->hasTabAdvancedSearch() === false) {
            return $this->buildJsDataGetterViaTrait($action, $unrendered);
        }

        // Add filters from the advanced search tab
        $notMap = [];
        foreach (ComparatorDataType::getValuesStatic() as $comp) {
            if (ComparatorDataType::isInvertable($comp)) {
                $notMap[$comp] = ComparatorDataType::invert($comp);
            }
        }
        $notMapJs = json_encode($notMap);
        
        $parsers = [];
        foreach ($this->getWidget()->getDataWidget()->getColumns() as $col) {
            if (! $col->isFilterable() || ! $col->isBoundToAttribute()) {
                continue;
            }
            $formatter = $this->getFacade()->getDataTypeFormatter($col->getDataType());
            $parsers[] = "'{$col->getAttributeAlias()}': function(mVal){ return {$formatter->buildJsFormatParser('mVal')} }";
        }
        $parsersJs = '{' . implode(",\n", $parsers) . '}';

        // if we are exporting, send only visible columns; otherwise send all columns
        $isExportAction = $this->escapeBool($action && $action->implementsInterface('iExportData'));
        
        $configuratorFiltersJs = $this->buildJsFilterGetterViaTrait();
        if ($configuratorFiltersJs === '') {
            $configuratorFiltersJs = '{}';
        }
        return <<<JS

function(){
    var oData = {$this->buildJsDataGetterViaTrait($action)};

    if (oData.filters) {
        // save current state of filters in model (to be used in widget setups)
        try {
            let oFilters = {$configuratorFiltersJs};
            var oDialog = sap.ui.getCore().byId('{$this->getId()}');
            var oCurrentModel = oDialog.getModel('{$this->getModelNameForConfig()}');
            oCurrentModel.setProperty('/header_filters', oFilters);
        } catch (error) {
            console.error("Error saving filters to model: ", error);
        }
    }

    var aFilters = sap.ui.getCore().byId('{$this->getIdOfSearchPanel()}').getFilterItems();
    var i = 0;
    var fnNot = function(oCondition) {
        var oNotMap = $notMapJs;
        oCondition.comparator = oNotMap[oCondition.comparator] || oCondition.comparator;
        return oCondition;
    };
    var aParsers = $parsersJs;
    if (aFilters.length > 0) {
        var includeGroup = {operator: "AND", ignore_empty_values: true, conditions: []};
        var oComponent = {$this->getController()->buildJsComponentGetter()};
        aFilters.forEach(function(oFilter){
            var mVal = oFilter.getValue1();
            var fnParser = aParsers[oFilter.getColumnKey()];
            var oCondition = {
                expression: oFilter.getColumnKey(), 
                comparator: oComponent.convertConditionOperationToConditionGroupOperator(oFilter.getOperation()), 
                value: (fnParser !== undefined ? fnParser(mVal) : mVal), 
                object_alias: "{$this->getWidget()->getMetaObject()->getAliasWithNamespace()}",
                apply_to_aggregates: false
            };
            includeGroup.conditions.push(oFilter.getExclude() === false ? oCondition : fnNot(oCondition));
        });
        
        if (oData.filters === undefined) {
            oData.filters = {};
        }
        
        if (oData.filters.nested_groups === undefined) {
            oData.filters.nested_groups = [];
        }
        oData.filters.nested_groups.push(includeGroup);
    }

    // for datatables, add columns array to parameters (previosuly in UI5DataTable) 
    {$this->buildJsDataLoaderParamsColumns("sap.ui.getCore().byId('{$this->getDataElement()->getId()}').getColumns()", 'oData', $isExportAction)}
    
    return oData;
}()
JS;
    }

    
    /**
     * Returns JS code, that will add an array of columns to the AJAX request data sent to the server
     * 
     * The AJAX request will include all visible columns (including optional columns, that were made visible by the user)
     * and globally hidden columns (e.g. those added by buttons or conditions), as well as hidden_if columns. 
     * Each AJAX column will have the following data:
     * - name
     * - attribute_alias (if bound to attribute and the alias differs from the column name)
     * - expression (if not bound to an attribute, but using a calculation instead)
     * 
     * This method needs an array of column definitions. It is actually not important what JS type/class of columns
     * they are - each must only have:
     * - .data('_exfDataColumnName')
     * - .data('_exfAttributeAlias')
     * - .data('_exfCalculation')
     * - .data('_exfHiddenColumn')
     * 
     * @param string $aCurrentColumnsJs
     * @param string $oDataJs
     * @param string $bIsExportAction (when exporting, we only send visible columns)
     * @return string
     */
    protected function buildJsDataLoaderParamsColumns(string $aCurrentColumnsJs, string $oDataJs, string $bIsExportAction) : string
    {
        // only do this for DataTables 
        // DataCards are instance of UI5DataTable, but do not have the ui5 column controls, so skip them too
        if ($this->getDataElement() instanceof UI5DataCards || !$this->getDataElement() instanceof UI5DataTable) {
            return '';
        }
    
        return <<<JS

            (function(aColumns, oData, bIsExportAction){
                oData.columns = [];
                // Add currently visible columns to data.columns array
                aColumns.forEach(oColumn => {

                    // skip invisible columns unless they are explicitly hidden or hidden_if columns, which we still need to read
                    // otherwise hidden_if columns cant be used in filters etc. because their data is never used in the requests
                    if (oColumn.getVisible() === false && ! oColumn.data('_exfHiddenColumn') && ! oColumn.data('_exfHiddenIfColumn')) {
                        return;
                    }
                    var oColParam;
                    var sColName = oColumn.data('_exfDataColumnName');
                    var sAttrAlias = oColumn.data('_exfAttributeAlias');
                    if (sColName) {
                        if (sAttrAlias) {
                            oColParam = {
                                attribute_alias: sAttrAlias
                            };
                            if (sColName !== oColParam.attribute_alias) {
                                oColParam.name = sColName;
                            }
                        } else if (oColumn.data('_exfCalculation')) {
                            oColParam = {
                                name: sColName,
                                expression: oColumn.data('_exfCalculation')
                            };
                        }
                        if (oColParam !== undefined) {
                            oData.columns.push(oColParam);
                        }
                    }
                });
                return oData;
            })($aCurrentColumnsJs, $oDataJs, $bIsExportAction);
JS;
    }
    
    public function buildJsDataLoaderParams(string $oParamsJs) : string
    {
        if ($this->hasTabSorters()) {
            $addSortersJs = <<<JS

                // Add sorters from P13nDialog
                aSortItems = sap.ui.getCore().byId('{$this->getIdOfSortPanel()}').getSortItems();
                for (var i in aSortItems) {
                    $oParamsJs.sort = (params.sort ? params.sort+',' : '') + aSortItems[i].getColumnKey();
                    $oParamsJs.order = (params.order ? params.order+',' : '') + (aSortItems[i].getOperation() == 'Ascending' ? 'asc' : 'desc');
                }
JS;
        } else {
            $addSortersJs = '';
        }
        
        return <<<JS

                $oParamsJs.data = {$this->buildJsDataGetter()};
                $addSortersJs
JS;
    }
        
    public function getModelNameForConfig() : string
    {
        return self::MODEL_NAME_FOR_CONFIG;
    }
    
    public function setIncludeColumnsTab(bool $trueOrFalse) : UI5DataConfigurator
    {
        $this->include_columns_tab = $trueOrFalse;
        return $this;
    }
    
    protected function hasTabColumns() : bool
    {
        return $this->include_columns_tab;
    }
    
    protected function hasTabSetups() : bool
    {
        $confWidget = $this->getWidget();
        if (! $confWidget instanceof DataTableConfigurator) {
            return false;
        }
        // Setups explicitly disabled
        if (! $confWidget->hasSetups()) {
            return false;
        }
        // Data widgets without full configurator support (e.g. FileList)
        if (! $this->hasTabColumns()) {
            return false;
        }
        // Check if the data widget is really a DataTable
        $dataWidget = $confWidget->getDataWidget();
        if (! $dataWidget instanceof DataTable) {
            return false;
        }
        // Double-check if apply_setup is implemented
        try {
            $this->buildJsCallFunction(DataTable::FUNCTION_APPLY_SETUP);
        } catch (WidgetFunctionUnknownError $e) {
            return false;
        }
        return true;
    }
    
    /**
     * 
     * @return UI5AbstractElement
     */
    protected function getDataElement() : UI5AbstractElement
    {
        return $this->getFacade()->getElement($this->getWidget()->getDataWidget());
    }
    
    public function buildJsP13nColumnConfig() : string
    {
        return "sap.ui.getCore().byId('{$this->getId()}').getModel('{$this->getModelNameForConfig()}').getData()['columns']";
    }
    
    /**
     *
     * {@inheritdoc}
     * @see JqueryContainerTrait::buildJsResetter()
     */
    public function buildJsResetter() : string
    {
        return $this->buildJsResetModel() . $this->buildJsResetterViaTrait();
    }
    
    protected function buildJsResetModel() : string
    {
        $initialModelName = $this->getModelNameForConfig() . '_initial';
        
        if ($this->hasTabColumns() === true) {
            $dataElement = $this->getDataElement();
            if ($dataElement instanceof UI5DataTable) {
                $controller = $this->getController();
                // Need to add a controller variable here because the configurator constructor is
                // rendered BEFORE the constrcutor of the table.
                if ($controller->hasDependent(UI5DataTable::CONTROLLER_VAR_OPTIONAL_COLS, $dataElement) === false) {
                    $controller->addDependentObject(UI5DataTable::CONTROLLER_VAR_OPTIONAL_COLS, $dataElement, 'null');
                }                
                $refreshP13n = $dataElement->buildJsRefreshPersonalization();
            }
            
            $resetColumns = <<<JS
// reset columns
                oCurrentModel.setProperty('/columns', JSON.parse(JSON.stringify(oInitModel.getProperty('/columns'))));

                // reset the columns panel UI
                var oColumnsPanel = sap.ui.getCore().byId('{$this->getId()}_ColumnsPanel');
                var fnUpdateColumns = oColumnsPanel && oColumnsPanel.data('_exfTabColumnsUpdate');
                if (typeof fnUpdateColumns === 'function') {
                    fnUpdateColumns.call(oColumnsPanel, true);
                }

                {$refreshP13n}
JS;
        } else {
            $resetColumns = '';
        }

        if ($this->hasTabSetups()){
            $resetSetupTracking = <<<JS
                // reset change indicator
                {$this->getDataElement()->buildJsCallFunction('reset_setup_change_tracking')}
JS;
        }
        else {
            $resetSetupTracking = '';
        }
        
        return <<<JS

            (function(){
                var oDialog = sap.ui.getCore().byId('{$this->getId()}');
                var oInitModel = oDialog.getModel('$initialModelName');
                var oCurrentModel = oDialog.getModel('{$this->getModelNameForConfig()}');
                
                // reset advanced search filters
                sap.ui.getCore().byId('{$this->getIdOfSearchPanel()}').removeAllFilterItems();
                
                // reset sorters (use deep copy to allow multiple resets; otherwise the initial model gets modified after resetting)
                oCurrentModel.setProperty('/sorters', JSON.parse(JSON.stringify(oInitModel.getProperty('/sorters'))));
                oCurrentModel.refresh(true);

                // reset current custom width properties of the table columns
                let oDataTable = sap.ui.getCore().byId('{$this->getDataElement()->getId()}'); 
                if (oDataTable && oDataTable instanceof sap.ui.table.Table) {

                    // clear custom width data
                    oDataTable.getColumns().forEach(oCol => {
                        oCol.data("_exfCustomColWidth", null);

                        // reset column header filter properties
                        let sFilterProperty = oCol.getFilterProperty();
                        if (sFilterProperty) {
                            oCol.setFiltered(false); // indicator
                            oCol.setFilterValue(''); // clear value
                        }

                        // reset column header sort properties
                        let sSortProperty = oCol.getSortProperty();
                        if (sSortProperty) {
                            oCol.setSorted(false); // indicator
                            oCol.setSortOrder(sap.ui.table.SortOrder.None); // sort order
                        }
                    });
                }

                // Reset stored setup in indexedDB:
                // if a setup exists for this table in the indexedDB, delete it
                exfSetupManager.dexie.deleteCurrentSetup(
                    '{$this->getDataElement()->getWidget()->findUiContainer()->getSlug()}' ,
                    '{$this->getDataElement()->getWidget()->getIdWithinUiContainer()}',
                    '{$this->getDataElement()->getWidget()->getMetaObject()->getId()}'
                );

                {$resetColumns}

                {$resetSetupTracking}
            }());

JS;
    }
}