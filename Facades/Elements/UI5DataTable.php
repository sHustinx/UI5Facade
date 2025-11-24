<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Actions\iReadData;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JqueryDataTableTrait;
use exface\Core\Interfaces\Widgets\iSupportMultiSelect;
use exface\Core\Widgets\DataTableResponsive;
use exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait;
use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataButton;
use exface\Core\Facades\AbstractAjaxFacade\Elements\JsConditionalPropertyTrait;
use exface\Core\Exceptions\Widgets\WidgetConfigurationError;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Widgets\WidgetLogicError;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Interfaces\Actions\iCallOtherActions;
use exface\UI5Facade\Facades\Interfaces\UI5DataElementInterface;
use exface\Core\Widgets\Parts\DataRowGrouper;
use exface\Core\Widgets\DataTable;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\OfflineStrategyDataType;
use exface\Core\CommonLogic\Model\UiPage;
use exface\Core\Factories\WidgetFactory;

/**
 *
 * @method \exface\Core\Widgets\DataTable getWidget()
 *
 * @author Andrej Kabachnik
 *
 */
class UI5DataTable extends UI5AbstractElement implements UI5DataElementInterface
{    
    use JsConditionalPropertyTrait;
    
    use UI5DataElementTrait, JqueryDataTableTrait {
       buildJsDataLoaderOnLoaded as buildJsDataLoaderOnLoadedViaTrait;
       buildJsConstructor as buildJsConstructorViaTrait;
       getCaption as getCaptionViaTrait;
       init as initViaTrait;
       UI5DataElementTrait::buildJsResetter insteadof JqueryDataTableTrait;
       UI5DataElementTrait::buildJsDataResetter as buildJsDataResetterViaTrait;
    }
    
    const EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED = 'firstVisibleRowChanged';
    
    const CONTROLLER_METHOD_RESIZE_COLUMNS = 'resizeColumns';

    /**
     * This JS controller property will hold an object of optional column instances
     * with data_column_name as key and sap.ui.table.Column or sap.m.Column as value.
     * @var string
     */
    const CONTROLLER_VAR_OPTIONAL_COLS = 'optionalCols';
    
    protected function init()
    {
        $this->initViaTrait();
        $this->getConfiguratorElement()->setIncludeColumnsTab(true);
    }
    
    protected function buildJsConstructorForControl($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $controller = $this->getController();

        // Initialize optional column from the configurator when the
        // JS controller is initialized.
        // IDEA maybe just initialize the controller var here and run the
        // constructors of the columns only on-demand in buildJsRefreshPersonalization()?
        if ($widget->getConfiguratorWidget()->hasOptionalColumns()) {
            $colsOptional = $widget->getConfiguratorWidget()->getOptionalColumns();
            $colsOptionalInitJs = '';
            if (! empty($colsOptional)) {
                foreach ($colsOptional as $col) {
                    $colsOptionalInitJs .= <<<JS
                
                        oColsOptional['{$col->getDataColumnName()}'] = {$this->getFacade()->getElement($col)->buildJsConstructor()};
JS;
                }
            }
            $controller->addOnInitScript(<<<JS
            
                (function(){
                    var oColsOptional = {};
                    {$colsOptionalInitJs}
                    {$controller->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_COLS, $this, $oControllerJs)} = oColsOptional;
                })();
JS
            );
        }

        if ($this->isMTable()) {
            $js = $this->buildJsConstructorForMTable($oControllerJs);
        } else {
            $js = $this->buildJsConstructorForUiTable($oControllerJs);
        }
        
        if (($syncAttributeAlias = $widget->getMultiSelectSyncAttributeAlias()) !== null)
        {
            if (($syncDataColumn = $widget->getColumnByAttributeAlias($syncAttributeAlias)) !== null) {
                $this->addOnChangeScript($this->buildJsMultiSelectSync($syncDataColumn, $oControllerJs));
            } else {
                throw new WidgetConfigurationError($widget, "The attribute alias '{$syncAttributeAlias}' for multi select synchronisation was not found in the column attribute aliases for the widget '{$widget->getId()}'!");
            }
        }
        
        // Clear selection every time the prefill data changes. Otherwise in a table within
        // a dialog if the first row was selected when the dialog was opened for object 1,
        // the first row will also be selected if the dialog will be opened for object 2, etc.
        // TODO it would be even better to check if previously selected UIDs are still there
        // and select their rows again like we do in EuiData::buildJsonOnLoadSuccessSelectionFix()
        if ($this->isUiTable()) {
            $clearSelectionJs = "sap.ui.getCore().byId('{$this->getId()}').clearSelection();";
        } else {
            $clearSelectionJs = "sap.ui.getCore().byId('{$this->getId()}').removeSelections(true);";
        }
        $controller->addOnPrefillDataChangedScript($clearSelectionJs);
        
        return $js;
    }

    public function isMList() : bool
    {
        return $this->isMTable();
    }
    
    public function isMTable()
    {
        return $this->getWidget() instanceof DataTableResponsive;
    }
    
    public function isUiTable()
    {
        return ! ($this->getWidget() instanceof DataTableResponsive);
    }
    
     /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsCallFunction()
     */
    public function buildJsCallFunction(string $functionName = null, array $parameters = [], ?string $jsRequestData = null) : string
    {
        // passed parameters
        $passedParameters = json_encode($parameters ?? null);
        if ($jsRequestData === null){
            $jsRequestData = 'null';
        }

        // setups table id is needed to dynamically mark applied setup
        $jsSetupsTableId = $this->escapeString('null');
        if ($functionName === DataTable::FUNCTION_APPLY_SETUP) {
            if ($this->getP13nElement()->getSetupsTableId() !== null){
                $jsSetupsTableId = $this->escapeString($this->getP13nElement()->getSetupsTableId()); 
            }
        }
  
        switch (true) {
            case $functionName === DataTable::FUNCTION_RESET_CHANGE_TRACKING:
                return <<<JS
                /*
                    Function to reset tracking of changes in the column configuration (sorting/filtering/columns) 
                        - resets the custom data property of the ui5 table .data('_exfConfigChanged')
                        - resets the change indicator of the quick select menu (if button exists)

                    Parameters: None
                */

                (function () {

                    // reset change property in table
                    let oDataTable = sap.ui.getCore().byId('{$this->getId()}'); 
                    oDataTable.data('_exfConfigChanged', false);

                    // reset change indicator in quickselect button
                    let oButton = sap.ui.getCore().byId('{$this->getId()}' + '_setupQuickselectBtn');
                    if (oButton){
                        let oButtonModel = new sap.ui.model.json.JSONModel({
                            buttonCaption: null,
                            configChanged: false 
                        });
                        oButton.setModel(oButtonModel);
                    }
                })();
                
JS;
            case $functionName === DataTable::FUNCTION_TRACK_CHANGES:
                return <<<JS
                /*
                    Function to track changes in the column configuration (sorting/filtering/columns) and
                        - mark them in a custom data property of the ui5 table .data('_exfConfigChanged')
                        - indicate the changes visually with an asterisk (*) in the quick select menu (if button exists)

                    Parameters: None
                */

                // TODO refactor change tracking -- this is now the quick and easy wax to do it
                // but its not tracking the changes meaningfully (changing/reverting etc.) 
                // but neither does ui5 apparently??

                (function () {
                    // Listen to changes in config elements
                    // set flag to only attach the event listeners once per table
                    let oDataTable = sap.ui.getCore().byId('{$this->getId()}'); 
                    if (!oDataTable.data("_exf_fnTrackSetupChangesAttached")){
                        // track changes as data property in table
                        oDataTable.data('_exfConfigChanged', false);

                        // Get the P13n model and the filter panel
                        let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                        if (oDialog == undefined){
                            return;
                        }
                        let oP13nModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
                        let oFilterPanel = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSearchPanel()}');

                        // add change indicator (*) to quickselect button
                        let oButton = sap.ui.getCore().byId('{$this->getId()}' + '_setupQuickselectBtn');
                        
                        if (oButton){
                            let oButtonModel = new sap.ui.model.json.JSONModel({
                                buttonCaption: null,
                                configChanged: false 
                            });
                            if (oButton) {
                                oButton.setModel(oButtonModel);
                            }
                        }

                        // Function to handle changes
                        function onConfigChange() {
                            oDataTable.data('_exfConfigChanged', true);
                            
                            if (oButton){
                                oButton.getModel().setProperty("/configChanged", true);
                            }
                        }

                        // event listeners for filter, columns, sorters, manual resizes
                        if (oP13nModel){
                            oP13nModel.bindProperty("/columns").attachChange(onConfigChange);
                            oP13nModel.bindProperty("/sorters").attachChange(onConfigChange);
                        }
                        if (oFilterPanel) {
                            oFilterPanel.attachEvent("addFilterItem", function (oEvent) {
                                onConfigChange(); 
                            });
                            oFilterPanel.attachEvent("updateFilterItem", function (oEvent) {
                                onConfigChange();
                            });
                            oFilterPanel.attachEvent("removeFilterItem", function (oEvent) {
                                onConfigChange(); 
                            });
                        }
                        oDataTable.attachEvent("columnResize", function (oEvent) {
                            if (this.data("_exfIsAutoResizing")) {
                                return;
                            }
                            onConfigChange(); 
                        });

                        oDataTable.data("_exf_fnTrackSetupChangesAttached", true);
                    }

                })();
                
JS;
            case $functionName === DataTable::FUNCTION_DUMP_SETUP:
                
                /*
                    Parameters/column names: dump_setup(SETUP_UXON, PAGE, WIDGET_ID, PROTOTYPE_FILE, OBJECT, PRIVATE_FOR_USER, true/false)

                    - SETUP_UXON:
                        The name of the column where the setup UXON will be stored
                    - PAGE:
                        the name of the column for the current page UID
                    - WIDGET_ID:
                        the name of the column for the current widget ID
                    - PROTOTYPE_FILE:
                        the name of the column for the prototype file to use
                        e.g. 'exface/core/Mutations/Prototypes/DataTableSetup.php'
                    - OBJECT:
                        the name of the column for the object of the datatable
                    - PRIVATE_FOR_USER:
                        the name of the column for the current user UID
                    - true/false: (optional)
                        auto apply after dumping the data (only works for updating an existing setup, otherwise the setup UID is missing)
                */

                return <<<JS

                // Dump current table setup into inputData of the action

                // get column name parameters, remove leading/trailing spaces; return if not all params provided
                let aParams = {$passedParameters};
                if (!Array.isArray(aParams) || aParams.length < 6) {
                    console.warn('dump_setup() called with invalid parameters:', aParams);
                    return;
                }
                let [sColNameCol, sPageCol, sWidgetIdCol, sPrototypeFileCol, sObjectCol, sUserIdCol] = aParams.map(p => typeof p === 'string' ? p.trim() : p);
                let bAutoApply = (aParams[6] !== undefined && aParams[6] !== null) ? (aParams[6].trim() === 'true' || aParams[6].trim() === true) : false;

                // json object to save current state in
                let oSetupJson = {
                    columns: [],
                    advanced_search: [],
                    sorters: []
                };

                // get the current states
                let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                let oP13nModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}'); 
                let aColumns = oP13nModel.getProperty('/columns');
                let aSorters = oP13nModel.getProperty('/sorters');
                let aFilters = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSearchPanel()}').getFilterItems();

                // save current column config
                if (aColumns !== undefined && aColumns.length > 0) {
                    aColumns.forEach(function(oColumn) {
                        if (oColumn.column_name != null){
                            // save column_name and visibility
                            oSetupJson.columns.push({
                                column_name: oColumn.column_name,
                                show: oColumn.visible
                            });
                        }
                    });
                }

                // loop through table columns (not the p13n model)
                // and add any custom (manually resized) widths to the setup config
                let oTable = sap.ui.getCore().byId('{$this->getId()}'); 
                if (oTable != null){
                    oTable.getColumns().forEach(function(oCol){

                        // if a column has a manually resized width, add it to the config
                        let sCustomWidth = oCol.data("_exfCustomColWidth");
                        if (sCustomWidth) {

                            // find the column in the setup config
                            let oColumnEntry = oSetupJson.columns.find(function(column) {
                                    return column.column_name === oCol.data("_exfDataColumnName");
                            });
                            
                            // save custom width in setup
                            if (oColumnEntry){
                                oColumnEntry.custom_width = sCustomWidth;
                            }
                        }
                    });
                }

                // save sorters
                if (aSorters !== undefined && aSorters.length > 0) {
                    aSorters.forEach(function(oColumn) {
                        oSetupJson.sorters.push({
                            attribute_alias: oColumn.attribute_alias,
                            direction: oColumn.direction
                        });
                    });
                }

                // save filters/advanced search
                if (aFilters !== undefined && aFilters.length > 0) {
                    aFilters.forEach(function(oFilter){
                        oSetupJson.advanced_search.push({
                            attribute_alias: oFilter.mProperties.columnKey,
                            comparator: oFilter.mProperties.operation,
                            value: oFilter.mProperties.value1,
                            exclude: oFilter.mProperties.exclude
                        });
                    });
                }

                // if input data is empty, initialize it
                if ({$jsRequestData}.rows[0] === undefined){
                    {$jsRequestData}.rows[0] = {};
                }

                // write the current setup and info into to the input data
                {$jsRequestData}.rows[0][sColNameCol] = JSON.stringify(oSetupJson);
                {$jsRequestData}.rows[0][sPageCol] = '{$this->getWidget()->getPage()->getUid()}';
                {$jsRequestData}.rows[0][sWidgetIdCol] = '{$this->getDataWidget()->getId()}';
                {$jsRequestData}.rows[0][sPrototypeFileCol] = 'exface/core/Mutations/Prototypes/DataTableSetup.php';
                {$jsRequestData}.rows[0][sObjectCol] = '{$this->getDataWidget()->getMetaObject()->getId()}';
                {$jsRequestData}.rows[0][sUserIdCol] = '{$this->getWorkbench()->getSecurity()->getAuthenticatedUser()->getUid()}';

                if (bAutoApply === true){
                    {$this->buildJsCallFunction(DataTable::FUNCTION_APPLY_SETUP, [ '[#' . $parameters[0] . '#]' ], $jsRequestData)}
                }
                
JS;

            case $functionName === DataTable::FUNCTION_APPLY_SETUP:
                // parameter: apply_setup([#SETUP_UXON#]) -> column in which the setup is stored
                // alternatively, apply_setup(['localStorage']) -> to retrieve saved setup from indexedDb
                
                return <<<JS

                // get currently selected data from request
                let oResultData = {$jsRequestData};
                let oSetupUxon = null;
                let sPageId = '{$this->getWidget()->getPage()->getUid()}';
                let sWidgetId = '{$this->getDataWidget()->getId()}';

                // if the function is not called with 'localStorage' parameter,
                // and there is data in the request, get the setup Uxon from the request data
                // (this is the case, when the user selects a setup from the setups tab and applies it)
                if ( oResultData !== null && {$passedParameters}[0] !== 'localStorage'){
                    if (oResultData.rows.length === 0) {
                        return;
                    }

                    // get setup UXON from request data and parse it
                    let sUxonCol = {$passedParameters}[0];
                    sUxonCol = sUxonCol.match(/\[#(.*?)#\]/)[1]; // strip the placeholder syntax
                    oSetupUxon = JSON.parse(oResultData.rows[0][sUxonCol]);
                }

                // either use the passed oSetupUxon, or try and load it from IndexedDB
                // then apply the setup
                getSetupData(sPageId, sWidgetId, oSetupUxon, 'setup_uxon')
                .then(oSetupUxon => {
                    if (oSetupUxon) {

                        // Apply setup
                        if (oSetupUxon.columns !== undefined){
                            // COLUMN SETUP
                            let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfColumnsPanel()}');
                            let oModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
                            let oInitModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}'+'_initial');
                            let aInitCols = JSON.parse(JSON.stringify(oInitModel.getData()['columns'])); // deep copy to avoid reference issues
                            let aColumnSetup = oSetupUxon.columns;
                            let oDataTable = sap.ui.getCore().byId('{$this->getId()}'); 

                            // reset current custom width properties of the table columns
                            if (oDataTable && oDataTable instanceof sap.ui.table.Table) {
                                oDataTable.getColumns().forEach(oCol => {
                                    oCol.data("_exfCustomColWidth", null);
                                });
                            }
                        
                            // build the new column model:
                            let aNewColModel = [];
                            
                            // loop through the widget setup columns
                            aColumnSetup.forEach(oItem => {

                                // skip entries without attribute alias or column_name 
                                // (eg. faulty or older setups)
                                if (oItem.attribute_alias == null && oItem.column_name == null){
                                    return;
                                }

                                // find the corresponding column (by column_name) in the p13n model
                                // -> also ensure backwards compatiblity with attribute alias
                                let oColumnEntry = null;
                                if (oItem.attribute_alias != null){
                                    // old: attribute alias columns
                                    oColumnEntry = aInitCols.find(function(column) {
                                        return column.attribute_alias === oItem.attribute_alias;
                                    });
                                }
                                else if (oItem.column_name != null){
                                    // new: column_name columns
                                    oColumnEntry = aInitCols.find(function(column) {
                                        return column.column_name === oItem.column_name;
                                    });
                                }

                                // if column exists, set visibility of column according to setup
                                // and add to new config model (check if id is already in config, to avoid duplicates here)
                                if (oColumnEntry && aNewColModel.some(col => col && col.column_id === oColumnEntry.column_id) === false) {
                                    oColumnEntry.visible = oItem.show;
                                    aNewColModel.push(oColumnEntry);
                                }

                                // if column has a custom width assigned (in widget setup), set column width to that value 
                                // and also set the data property on the column (so they dont get optimized/resized in buildJsUiTableColumnResize)
                                // this is only done with ui.table
                                if (oItem.custom_width && oItem.custom_width != '' && oDataTable && oDataTable instanceof sap.ui.table.Table) {
                                    
                                    // find the actual column in the table (not p13n model)
                                    let oMatchingCol = null;
                                    if (oItem.column_name != null){
                                        oMatchingCol = oDataTable.getColumns().find(function(oCol) {
                                            return oCol.data("_exfDataColumnName") === oItem.column_name;
                                        });
                                    }
                                    else{
                                        // attribute alias cols (older setups)
                                        oMatchingCol = oDataTable.getColumns().find(function(oCol) {
                                            return oCol.data("_exfAttributeAlias") === oItem.attribute_alias;
                                        });
                                    }
                                    
                                    // if column exists, set custom width (and also custom width data property)
                                    if (oMatchingCol) {
                                        oMatchingCol.data("_exfCustomColWidth", oItem.custom_width);
                                        oMatchingCol.setWidth(oItem.custom_width);
                                    }
                                }
                            });

                            // add any missing columns back in as hidden columns at the end; 
                            // this avoids data loss when columns are missing in widget setup, 
                            // or if columns were added to the table later on (and the setup is older)
                            aInitCols.forEach(oColConf => {
                                let oColumnEntry = null;
                                oColumnEntry = aNewColModel.find(function(column) {
                                    return column.column_id === oColConf.column_id;
                                });
                                if (oColumnEntry) {
                                    // column already in new model, skip
                                    return;
                                }

                                oColConf.visible = false;
                                aNewColModel.push(oColConf);
                            });
                            oModel.setProperty('/columns', aNewColModel);

                            // toggle checkboxes in columns tab according to setup
                            // otherwise the UI doesnt seem to get updated, since we dont manually interact with the checkboxes
                            let oTable = oDialog.getAggregation('content')[1].getAggregation('content')[0];
                            let oTableModel = oTable.getModel();
                            let aColsConfig = oModel.getProperty('/columns');
                            let oVisibleFilter = new sap.ui.model.Filter("toggleable", sap.ui.model.FilterOperator.EQ, true);
                            oDialog.getBinding("items").filter(oVisibleFilter);
                            let aItems = oTableModel.getProperty('/items');
                            
                            aColsConfig.forEach(function(oColConfig){
                                aItems.forEach(function(oItem, iItemIdx){
                                    if (oItem.columnKey === oColConfig.column_id) {
                                        oItem.persistentSelected = oColConfig.visible; 
                                        return;
                                    }
                                })
                            }); 

                        }
                        if (oSetupUxon.sorters !== undefined) {
                            // SORTER SETUP
                            let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSortPanel()}');
                            let oModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
                            let aSorterSetup = oSetupUxon.sorters;

                            let aSortItems = [];
                            aSorterSetup.forEach(oItem => {
                                aSortItems.push({
                                    attribute_alias: oItem.attribute_alias,
                                    direction: oItem.direction
                                });
                            });

                            oModel.setProperty("/sorters", aSortItems);
                        }
                        if (oSetupUxon.advanced_search !== undefined) {
                            // ADVANCED SEARCH SETUP

                            // remove and re-add filters from config
                            let aFilterSetup = oSetupUxon.advanced_search;
                            let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSearchPanel()}');
                            oDialog.removeAllFilterItems();

                            aFilterSetup.forEach(oItem => {
                                var oFilterItem = new sap.m.P13nFilterItem({
                                    "columnKey": oItem.attribute_alias,
                                    "exclude": oItem.exclude,
                                    "operation": oItem.comparator,
                                    "value1": oItem.value
                                });
                                oDialog.addFilterItem(oFilterItem);
                            });
                        }

                        // store the last applied setup in session storage 
                        // do this only if it was actively applied (not when loading from indexedDb)
                        if ({$passedParameters}[0] !== 'localStorage'){
                            
                            // combination of page and widget id as primary key for db entry
                            sPageId = oResultData.rows[0]['PAGE'];
                            sWidgetId = oResultData.rows[0]['WIDGET_ID'];

                            let oSetupObj = {
                                page_id: oResultData.rows[0]['PAGE'],
                                widget_id: oResultData.rows[0]['WIDGET_ID'],
                                setup_uid: oResultData.rows[0]['UID'],
                                date_last_applied: new Date().toISOString(),
                                setup_uxon: oResultData.rows[0]['SETUP_UXON'],
                                setup_name: oResultData.rows[0]['NAME']
                            };

                            // open indexedDb connection
                            const oSetupsDb = new Dexie('exf-ui5-widgets');
                            oSetupsDb.version(1).stores({
                                'setups': '[page_id+widget_id], setup_uid, date_last_applied'
                            });

                            // Save setup in db, then close connection 
                            oSetupsDb.setups.put(
                                oSetupObj
                            ).catch(err => {
                                console.error('Error accessing IndexedDb:', err);
                            }).finally(() => {
                                oSetupsDb.close();
                            });
                        }

                        // after applying a setup, get the uid and mark it as default in the setups table
                        if ({$jsSetupsTableId} !== null){
                        
                            // get the ui5 datatable that shows the setups
                            let oSetupTable = sap.ui.getCore().byId({$jsSetupsTableId});
                            if (oSetupTable == undefined){
                                return;
                            }

                            let oModel = oSetupTable.getModel();
                            let oData = oModel.getProperty('/');
                            
                            if (!oSetupTable.data("_exf_fnSetAsDefaultAttached")){
                                // the setups table seems to refresh on every re-open so its not enough to set in once,
                                // so it needs to be some sort of event listener that re-sets it on re-open/update
                                let fnSetAsDefault = function(oEvent) {
                                    // retrieve the currently applied setup uid from indexedDb/session storage
                                    getSetupData(sPageId, sWidgetId, null, 'setup_uid')
                                    .then(sSetupUid => {
                                        let oModel = oSetupTable.getModel();
                                        let oData = oModel.getProperty('/');
                                        if (oData && Array.isArray(oData.rows) && oData.rows.length > 0) {
                                            oData.rows.forEach(row => {
                                                row.SETUP_APPLIED = "";
                                                if (row.UID === sSetupUid) {
                                                    row.SETUP_APPLIED = "sap-icon://accept";
                                                }
                                            });

                                            oModel.setProperty('/rows', oData.rows);
                                        }
                                    });
                                };

                                oSetupTable.detachUpdateFinished(fnSetAsDefault);
                                oSetupTable.attachUpdateFinished(fnSetAsDefault);

                                // make sure listener is only attached once
                                oSetupTable.data("_exf_fnSetAsDefaultAttached", true);
                            }
                            

                            // if setup is manually applied, refresh ui to trigger event listener
                            if ({$passedParameters}[0] !== 'localStorage'){
                                oModel.refresh(true);
                            }
                        }

                        // update the quick select caption
                        getSetupData(sPageId, sWidgetId, null, 'setup_name')
                        .then(sSetupName => {
                            if (sSetupName !== null){
                                let oButton = sap.ui.getCore().byId('{$this->getId()}' + '_setupQuickselectBtn');
                                if (oButton){
                                    // update the caption of the quickselect btn
                                    oButton.getModel().setProperty("/buttonCaption", sSetupName); 
                                }
                            }
                        });

                        // apply changes immediately 
                        let oP13nDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                        if (oP13nDialog) {
                            oP13nDialog.fireOk();
                        }

                        // reset change tracking
                        {$this->buildJsCallFunction(DataTable::FUNCTION_RESET_CHANGE_TRACKING)}
                    } 
                    else {
                        // return if no setup was passed or found
                        return;
                    }
                });

                /*
                    Function that returns a passed value as a promise (immediately) or retrieves a value stored in IndexedDB
                    (this is needed because the indexedDB calls are asynchronous, so we need to work with promises either way)

                    sPageId : string - the page identifier, e.g. 'page1' (part of the IndexedDb pk)
                    sWidgetId : string - the widget identifier, e.g. 'myTable' (part of the IndexedDb pk)
                    sPassedData = null : string|null - if a value is passed, it will be returned immediately
                    sKey = null : string|null - the key of the value to retrieve from indexedDb, e.g. 'setup_uxon' or 'setup_uid'
                */
                function getSetupData(sPageId, sWidgetId, sPassedData = null, sKey = null) {

                    // If data is passed in function, resolve immediately and return it 
                    if (sPassedData !== null) {
                        return Promise.resolve(sPassedData);
                    }

                    // Otherwise, load the setup from IndexedDB
                    const oSetupsDb = new Dexie('exf-ui5-widgets');
                    oSetupsDb.version(1).stores({
                        'setups': '[page_id+widget_id], setup_uid, date_last_applied'
                    });

                    return oSetupsDb.setups.get([sPageId, sWidgetId])
                    .then(entry => {
                        if (entry && entry[sKey]) {
                            if (sKey === 'setup_uxon') {
                                return JSON.parse(entry[sKey]); //parse setup uxon
                            }
                            return entry[sKey]; //return other values as is
                        }
                        return null;
                    })
                    .catch(err => {
                        console.error('Error reading from IndexedDB:', err);
                        return null;
                    })
                    .finally(() => {
                        oSetupsDb.close();
                    });
                }
JS;
        }

        return parent::buildJsCallFunction($functionName, $parameters, $jsRequestData);
    }
    

    /**
     * Returns the javascript constructor for a sap.m.Table
     *
     * @return string
     */
    protected function buildJsConstructorForMTable(string $oControllerJs = 'oController')
    {
        $mode = $this->getWidget()->getMultiSelect() ? 'sap.m.ListMode.MultiSelect' : 'sap.m.ListMode.SingleSelectMaster';
        $striped = $this->getWidget()->getStriped() ? 'true' : 'false';
        
        if ($this->getDynamicPageShowToolbar() === false) {
            $toolbar = $this->buildJsToolbar($oControllerJs, $this->buildJsSetupQuickSelectMenu());
        } else {
            $toolbar = '';
        }
        
        $controller = $this->getController();
        return <<<JS
        new sap.m.VBox({
            {$this->buildJsPropertyVisibile()}
            width: "{$this->getWidth()}",
    		items: [
                new sap.m.Table("{$this->getId()}", {
            		fixedLayout: false,
                    contextualWidth: "Auto",
                    sticky: [sap.m.Sticky.ColumnHeaders, sap.m.Sticky.HeaderToolbar],
                    alternateRowColors: {$striped},
                    noDataText: "{$this->getWidget()->getEmptyText()}",
            		itemPress: {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, true)},
                    selectionChange: function (oEvent) { {$this->buildJsPropertySelectionChange('oEvent')} },
                    updateFinished: function(oEvent) { {$this->buildJsColumnStylers()} },
                    mode: {$mode},
                    headerToolbar: [
                        {$toolbar}
            		],
            		columns: [
                        {$this->buildJsColumnsForMTable()}
            		],
            		items: {
            			path: '/rows',
                        {$this->buildJsBindingOptionsForGrouping()}
                        template: new sap.m.ColumnListItem({
                            type: "Active",
                            cells: [
                                {$this->buildJsCellsForMTable()}
                            ]
                        }),
            		},
                    contextMenu: [
                        // A context menu is required for the contextmenu browser event to fire!
                        new sap.ui.unified.Menu()
                    ]
                })
                {$this->buildJsClickHandlers('oController')}
                {$this->buildJsPseudoEventHandlers()}
                ,
                {$this->buildJsConstructorForMTableFooter()}
            ]
        })
        
JS;
    }

     /**
      * Builds a UI5 quick select menu with widget setups for the datatable.
      * Menu uses the same model as (so is dependant on) the table containing the setups in the p13n dialogue for consistency
      * if the quickselect is opened before the setup configurator, it will load the data of the configurator table
      * @return string
      */
     protected function buildJsSetupQuickSelectMenu() : string
    {

        if (!$this->getConfiguratorElement()->getWidget()->hasSetups()){
            return '';
        }
        
        $setupsTable = $this->getP13nElement()->getWidget()->getSetupsTab()->getWidgetFirst();
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();

        // Caption of Popover Button
        // -> if the table caption is hidden and no setup selected, it shows just the dropdown arrow 
        // -> if the table has a visible caption, it sets it as the caption of the button and hides the table caption
        // -> if a setup is applied, the caption will be the name of the setup
        $tableCaption = $this->escapeString('');
        $popoverTitle = $this->escapeString($translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_CAPTION'));
        if ($this->getWidget()->getHideCaption() !== true && $this->getCaption() !== null){
            $tableCaption = $this->escapeString($this->getCaption());
            $popoverTitle = $this->escapeString($this->getCaption() . ' ' . $translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_CAPTION'));
            $this->getWidget()->setHideCaption(true);
        }
        
        // button to apply selected setup
        $applySetupButtonJs = <<<JS
            new sap.m.Button({
                text: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_APPLY')}",
                tooltip: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_APPLY')}",
                type: sap.m.ButtonType.Emphasized,
                press: () => {
                    // return if nothing selected
                    if (this._oTable.getSelectedItem() == null){
                        return;
                    }

                    // apply selected setup
                    let oQuickSelectData = {
                        rows: [ this._oTable.getSelectedItem().getBindingContext().getObject() ]
                    };
                    {$this->buildJsCallFunction(DataTable::FUNCTION_APPLY_SETUP, [ '[#SETUP_UXON#]' ], 'oQuickSelectData')}
                }
            })
                    
JS;

        // button to open the configrator 
        $openConfiguratorBtnJs = <<<JS
                    new sap.m.Button({
                        text: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_ALL')}",
                        tooltip: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_ALL')}",
                        layoutData: new sap.m.OverflowToolbarLayoutData({
                            priority: sap.m.OverflowToolbarPriority.AlwaysOverflow
                        }),
                        press: function() {
                			{$this->getController()->buildJsDependentControlSelector('oConfigurator', $this, 'oController')}.open();
                		}
                    })
JS;

        // button to close the popup
        $closePopoverBtnJs = <<<JS
            new sap.m.Button({
                text: "{$translator->translate('ACTION.GENERIC.CANCEL')}",
                type: sap.m.ButtonType.Transparent,
                press: () => {
                    if (this._oPopover) {
                        this._oPopover.close();
                    }
                }
            })
JS;

        return <<<JS
                    new sap.m.Button({
                        id: '{$this->getId()}' + '_setupQuickselectBtn',
                        text: {
                            parts: [
                                { path: "/buttonCaption" },
                                { path: "/configChanged" }
                            ],
                            formatter: function (sButtonCaption, bConfigChanged) {
                                // Use $tableCaption if buttonCaption is null
                                let sCaption = sButtonCaption === null ? {$tableCaption} : sButtonCaption;
                                // append * if configChanged 
                                return bConfigChanged ? sCaption + " *" : sCaption;
                            }
                        },
                        icon: "sap-icon://slim-arrow-down",
                        press: function (oEvent) {

                            let oButton = oEvent.getSource();
                            let oOriginalTable = sap.ui.getCore().byId("{$this->getP13nElement()->getSetupsTableId()}");
                            let oModel = oOriginalTable.getModel();
                            let sPath = oOriginalTable.getBinding("items").getPath();
                            let oBinding = oOriginalTable.getBinding("items");

                            if (oBinding && oBinding.getLength() === 0) {
                                // Load data if the table is empty (on first open for example)
                                // this calls the onload method of the table
                                {$this->getFacade()->getElement($setupsTable)->buildJsRefresh()}
                            }

                            // only create popover once per table
                            if (!this._oPopover) {
                                if (!this._oTable) {

                                    // create new table
                                    this._oTable = new sap.m.Table({
                                        columns: [
                                            new sap.m.Column({ header: new sap.m.Text({ text: '{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_ACTIVE')}'}), width: "10%", hAlign: "Center"}),
                                            new sap.m.Column({ header: new sap.m.Text({ text: "Name" })}),
                                            new sap.m.Column({ header: new sap.m.Text({ text: "Favorit" }), width: "30%", hAlign: "Center"})
                                        ],
                                        mode: sap.m.ListMode.SingleSelectMaster
                                    });

                                    // use same model as in setups tab so data is consisnent
                                    this._oTable.setModel(oModel);

                                    // table template
                                    const oTemplate = new sap.m.ColumnListItem({
                                        cells: [
                                            new sap.ui.core.Icon({
                                                visible: {
                                                    path: "SETUP_APPLIED",
                                                    formatter: function (v) {
                                                        return !!v; // hide icon if empty, otherwise UI5 gives warnings
                                                    }
                                                },
                                                src: {
                                                    path: "SETUP_APPLIED",
                                                    formatter: function (v) {
                                                        return v || "sap-icon://less";
                                                    }
                                                }
                                            }),
                                            new sap.m.Text({ text: "{NAME}" }),
                                            new sap.ui.core.Icon({
                                                src: {
                                                    path: "WIDGET_SETUP_USER__FAVORITE_FLAG",
                                                    formatter: function (v) {
                                                        return v == 1 ? "sap-icon://favorite" : "sap-icon://unfavorite";
                                                    }
                                                }
                                            })
                                        ]
                                    });

                                    // bind items to the table 
                                    // sort by favourite desc; and limit to 10 elements
                                    // (we need to do that here in the binding, in order to not change the original table in the configurator)
                                    this._oTable.bindItems({
                                        path: sPath,
                                        template: oTemplate,
                                        sorter: new sap.ui.model.Sorter("WIDGET_SETUP_USER__FAVORITE_FLAG", true),
                                        length: 10
                                    });
                                }

                                // create popover and add table and buttons as content
                                this._oPopover = new sap.m.Popover({
                                    title: {$popoverTitle},
                                    contentWidth: "500px",
                                    contentHeight: "200px",
                                    placement: sap.m.PlacementType.VerticalPreferredBottom,
                                    resizable: true,
                                    showArrow: true,
                                    content: [
                                        this._oTable 
                                    ],
                                    footer: new sap.m.OverflowToolbar({
                                        content: [
                                            new sap.m.ToolbarSpacer(), 
                                            {$applySetupButtonJs},
                                            {$closePopoverBtnJs},
                                            {$openConfiguratorBtnJs}
                                        ]
                                    })
                                });

                                this.addDependent(this._oPopover);
                            }

                            // open popover relative to the button
                            this._oPopover.openBy(oButton);
                        }
                    }),
                    
                    
JS;
    }


    
    /**
     * 
     * @param string $oControllerJs
     * @return string
     */
    protected function buildJsConstructorForMTableFooter(string $oControllerJs = 'oController') : string
    {
        $visible = $this->getWidget()->isPaged() === false || $this->getWidget()->getHideFooter() === true ? 'false' : 'true';
        return <<<JS
                new sap.m.OverflowToolbar({
                    visible: {$visible},
    				content: [
                        {$this->getPaginatorElement()->buildJsConstructor($oControllerJs)},
                        new sap.m.ToolbarSpacer(),
                        {$this->buildJsConfiguratorButtonConstructor($oControllerJs, 'Transparent')}
                    ]
                })
                
JS;
    }



    /**
     * On every selection change event save the selection and perform on-change scripts
     * 
     * This method also ensures, that previously selected rows are selected again when
     * following the pagination back and fourth or using filters. That is, 
     * - if you select a row on one page, go to the next one and return back, the initially 
     * selected row will still be selected
     * - if you select a row on one page, go to another one and press a button, the (invisible)
     * selected row on the first page will be among the action data
     * 
     * @return string
     */
    protected function buildJsPropertySelectionChange(string $oEventJs)
    {
        $controller = $this->getController();
        $widget = $this->getWidget();
        $uidColJs = $widget->hasUidColumn() ? $this->escapeString($widget->getUidColumn()->getDataColumnName()) : 'null';
        if ($widget->getMultiSelect() === false) {
            return <<<JS

            {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
JS;
            
        }
        
        return <<<JS
        
            const oTable = $oEventJs.getSource();
            const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
            const bMultiSelect = oTable.getMode !== undefined ? oTable.getMode() === sap.m.ListMode.MultiSelect : {$this->escapeBool($widget->getMultiSelect())};
            const bMultiSelectSave = {$this->escapeBool(($widget instanceof DataTable) && $widget->isMultiSelectSavedOnNavigation())}
            const sUidCol = {$uidColJs};
            var aRowsVisible = [];
            var aRowsMerged = [];
            var aRowsSelectedVisible = {$this->buildJsGetRowsSelected('oTable')};

            if (bMultiSelect === true && bMultiSelectSave === true) {
                aRowsVisible = {$this->buildJsGetRowsAll('oTable')};
                // Keep all previously selected rows, that are NOT in the current page
                // because they definitely could not be deselected
                oModelSelected.getProperty('/rows').forEach(oRowOld => {
                    if (exfTools.data.indexOfRow(aRowsVisible, oRowOld, sUidCol) === -1) { 
                        aRowsMerged.push(oRowOld);
                    }
                });
                // Add all currently visible selected rows
                aRowsMerged.push(...aRowsSelectedVisible);

                oModelSelected.setProperty('/rows', aRowsMerged);
            } else {
                oModelSelected.setProperty('/rows', aRowsSelectedVisible);
            }
            
            {$controller->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
JS;
    }


    
    /**
     * 
     * @return string
     */
    protected function buildJsBindingOptionsForGrouping()
    {
        $widget = $this->getWidget();
        
        if (! $widget->hasRowGroups()) {
            return '';
        }
        
        $grouper = $widget->getRowGrouper();
        
        $sorterDir = 'true';
        foreach ($this->getWidget()->getSorters() as $sorterUxon) {
            if ($sorterUxon->getProperty('attribute_alias') === $grouper->getGroupByColumn()->getAttributeAlias()) {
                if ($sorterUxon->getProperty('direction') === SortingDirectionsDataType::DESC) {
                    $sorterDir = 'true';
                } else {
                    $sorterDir = 'false';
                }
                break;
            }
        }
        
        $caption = $grouper->getHideCaption() ? '' : $this->escapeJsTextValue($grouper->getCaption());
        $caption .= $caption ? ': ' : '';
        
        // Row grouping is defined inside a sorter, so we must add a client-side sorter to have the
        // groups. Since the actual sorting is normally done elsewhere (in the server or by the data,
        // loader) we use a sorter with a custom compare function here, that does not really do anything.
        // This is important, as the built-in sorter yielded very strage result for some data types like
        // dates.
        return <<<JS
        
                sorter: new sap.ui.model.Sorter(
    				'{$grouper->getGroupByColumn()->getDataColumnName()}', // sPath
    				{$sorterDir}, // bDescending
    				true, // vGroup
                    function(a, b) { // fnComparator
                        return 0;
                    }
    			),
    			groupHeaderFactory: function(oGroup) {
                    // TODO add support for counters
                    return new sap.m.GroupHeaderListItem({
        				title: "{$caption}" + (oGroup.key !== null ? oGroup.key : "{$this->escapeJsTextValue($grouper->getEmptyText())}"),
                        type: "Active",
                        press: function(oEvent) {
                            var oHeaderItem = oEvent.getSource();
                            var oList = oHeaderItem.getParent();
                            var iHeaderIdx = oList.indexOfItem(oHeaderItem);
                            var aItems = oList.getItems();
                            var oItem;

                            for (var i=0; i<aItems.length; i++) {
                                if (i <= iHeaderIdx) continue;
                                oItem = aItems[i];
                                if (oItem instanceof sap.m.GroupHeaderListItem) break;
                                if (oItem.getVisible()) {
                                    oItem.setVisible(false);
                                    oHeaderItem.setType('Navigation');
                                } else {
                                    oItem.setVisible(true);
                                    oHeaderItem.setType('Active');
                                }
                            }
                        }
        			});
                },
JS;
    }
    
    /**
     * Returns the javascript constructor for a sap.ui.table.Table
     *
     * @return string
     */
    protected function buildJsConstructorForUiTable(string $oControllerJs = 'oController')
    {
        $widget = $this->getWidget();
        $controller = $this->getController();
        
        $selection_mode = $widget->getMultiSelect() ? 'sap.ui.table.SelectionMode.MultiToggle' : 'sap.ui.table.SelectionMode.Single';
        $selection_behavior = $widget->getMultiSelect() ? 'sap.ui.table.SelectionBehavior.Row' : 'sap.ui.table.SelectionBehavior.RowOnly';
        
        if ($this->getDynamicPageShowToolbar() === false) {
            $toolbar = $this->buildJsToolbar($oControllerJs, $this->buildJsSetupQuickSelectMenu() .$this->getPaginatorElement()->buildJsConstructor($oControllerJs));
        } else {
            $toolbar = '';
        }
        $freezeColumnsCount = $widget->getFreezeColumns();
        if ($freezeColumnsCount > 0) {
            $columns = $widget->getColumns();
            for ($i = 0; $i < $freezeColumnsCount; $i++) {
                if ($columns[$i]->isHidden() == true) {
                    $freezeColumnsCount++;
                }
            }
            // increase the count if the DirtyFlag column is added as the first column in the table
            if ($this->hasDirtyColumn()) {
                $freezeColumnsCount++;
            }
        }
        $enableGrouping = $widget->hasRowGroups() ? 'enableGrouping: true,' : '';
        
        if ($widget->getDragToOtherWidgets() === true) {
            $initDnDJs = <<<JS

                dragDropConfig: [
                    new sap.ui.core.dnd.DragInfo({
                        sourceAggregation: "rows",
                        dragStart: function(oEvent) {
                            var oDraggedRow = oEvent.getParameter("target");
                            var oModel = oDraggedRow.getModel();
                            var oRow = oModel.getProperty(oDraggedRow.getBindingContext().getPath());
                            var oDataSheet = {
                                oId: '{$this->getMetaObject()->getId()}',
                                rows: (oRow ? [oRow] : [])
                            };
                            oEvent.getParameter('browserEvent').dataTransfer.setData("dataSheet", JSON.stringify(oDataSheet));
                        }
                    }),
                ],
JS;
        } else {
            $initDnDJs = '';
        }
        
        $js = <<<JS
            new sap.ui.table.Table("{$this->getId()}", {
                width: "{$this->getWidth()}",
                visibleRowCountMode: sap.ui.table.VisibleRowCountMode.Auto,
                {$this->buildJsPropertyMinAutoRowCount()}
                selectionMode: {$selection_mode},
        		selectionBehavior: {$selection_behavior},
                enableColumnReordering:true,
                fixedColumnCount: {$freezeColumnsCount},
                enableColumnFreeze: true,
                {$enableGrouping}
        		filter: {$controller->buildJsMethodCallFromView('onLoadData', $this)},
        		sort: {$controller->buildJsMethodCallFromView('onLoadData', $this)},
                rowSelectionChange: function (oEvent) { {$this->buildJsPropertySelectionChange('oEvent')} },
                firstVisibleRowChanged: {$controller->buildJsEventHandler($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, true)},
        		columnResize: function (oEvent) {
                    // skip if the table is currently auto-resizing
                    if (this.data("_exfIsAutoResizing")) {
                        return;
                    }

                    // otherwise assume its a manual resize, and save in custom width property
                    var sNewWidth = oEvent.getParameter("width");
                    var oColumn = oEvent.getParameter("column");
                    oColumn.data("_exfCustomColWidth", sNewWidth);
                },
                {$this->buildJsPropertyVisibile()}
                {$initDnDJs}
                toolbar: [
        			{$toolbar}
        		],
        		columns: [
        			{$this->buildJsColumnsForUiTable()}
        		],
                noData: [
                    new sap.m.FlexBox({
                        height: "100%",
                        width: "100%",
                        justifyContent: "Center",
                        alignItems: "Center",
                        items: [
                            new sap.m.Text("{$this->getIdOfNoDataOverlay()}", {text: "{$widget->getEmptyText()}"})
                        ]
                    })
                ],
                rows: "{/rows}"
        	})
            {$this->buildJsClickHandlers('oController')}
            {$this->buildJsPseudoEventHandlers()}
JS;
            
            return $js;
    }
    
    /**
     * 
     * @return string
     */
    protected function getIdOfNoDataOverlay() : string
    {
        return $this->getId() . '_noData';
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsPropertyMinAutoRowCount() : string
    {
        $widget = $this->getWidget();
        $heightInRows = $widget instanceof DataTable ? $widget->getHeightInRows() : null;
        $heightInRowsDefault = $this->getFacade()->getConfig()->getOption('WIDGET.DATATABLE.ROWS_SHOWN_BY_DEFAULT');
        $height = $widget->getHeight();
        $singleRowHeightPx = '33';

        switch (true) {
            case $heightInRows !== null:
                $minAutoRowCount = $heightInRows;
                break;
            case $height->isRelative():
            case $height->isFacadeSpecific() && StringDataType::endsWith($height->getValue(), 'px', false):
                // TODO determine the height elements via JS
                // iRowHeight = oTable.getRowHeight() // but oTable is not there yet. Maybe on-resize?
                $heightPx = StringDataType::substringBefore($height->getValue(), 'px', $height->getValue(), true);
                $heightPx = NumberDataType::cast($heightPx);
                $minAutoRowCount = <<<JS
                function(){
                    var iRowHeight = {$singleRowHeightPx};
                    var jqTest = $('<div class="sapMTB sapMTBHeader-CTX"></div>').appendTo('body');
                    var iToolbarHeight = jqTest.height();
                    var iTableHeight = {$heightPx};
                    jqTest.remove();
                    return Math.floor((iTableHeight - iRowHeight - iToolbarHeight) / iRowHeight);
                }()

JS;
                break;
            // Calculate the max. number of rows, that will fit the given percentage of the
            // height of the container. Note, the immediate container might be simply the
            // FormElement, that will always have the same height as the table, so look for
            // the Form further up the hierarchy
            // TODO add other container types in case the table is not part of the form
            case $height->isPercentual():
            case $height->isMax():
                if ($height->isPercentual()) {
                    $heightPercent = StringDataType::substringBefore($height->getValue(), '%', $height->getValue());
                    $heightPercent = NumberDataType::cast($heightPercent);
                } else {
                    $heightPercent = 100;
                }
                // NOTE: this JS code makes use of the oController variable. Since this is called inside the
                // table constructor, oController should always be defined as 
                $minAutoRowCount = <<<JS
                function(){
                    var iRowHeight = iHeaderHeight = {$singleRowHeightPx};
                    var iRowsDefault = {$heightInRowsDefault};
                    var jqTest = $('<div class="sapMTB sapMTBHeader-CTX"></div>').appendTo('body');
                    var iToolbarHeight = jqTest.height();
                    var fnCalcHeight = function(oContainer){
                        var jqContainer = oContainer.$();
                        var iContainerHeight = jqContainer ? jqContainer.innerHeight() : null;
                        var iTargetHeight;
                        if (iContainerHeight) {
                            iTargetHeight = iContainerHeight / 100 * {$heightPercent};
                            return Math.floor((iTargetHeight - iHeaderHeight - iToolbarHeight) / iRowHeight);
                        }
                        return null;
                    };
                    jqTest.remove();

                    setTimeout(function(){
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var oContainer = oController.findParentOfType(oTable, sap.ui.layout.form.Form);
                        var bResizing;
                        if (! oContainer) {
                            return;
                        }

                        sap.ui.core.ResizeHandler.register(oContainer, function(){
                            var iRows;
                            if (bResizing === true) {
                                bResizing = false;
                                return;
                            }
                            bResizing = true;
                            setTimeout(function(){
                                iRows = fnCalcHeight(oContainer);
                                if (iRows !== null) {
                                    oTable.setMinAutoRowCount(iRows);
                                }
                            }, 10);
                        });
                    }, 0);
                    
                    return iRowsDefault;
                }()

JS;
                break;
            //case $height->isUndefined():
            //case $height->isAuto():
            default:
                $minAutoRowCount = $heightInRowsDefault;
                break;            
        }
        
        return "minAutoRowCount: {$minAutoRowCount},";
    }
    
    /**
     * Returns a comma separated list of column constructors for sap.ui.table.Table
     *
     * @return string
     */
    protected function buildJsColumnsForUiTable()
    {
        $widget = $this->getWidget();
        $column_defs = '';
        
        // Add dirty-column for offline actions
        if ($this->hasDirtyColumn()) {
            $column_defs .= <<<JS
            
        new sap.ui.table.Column('{$this->getDirtyFlagAlias()}',{
            hAlign: "Center",
            autoResizable: true,
            width: "48px",
            minWidth: 48,
            visible: true,
            template: new sap.m.Button({
                icon: "sap-icon://time-entry-request",
                visible: "{= \$\{{$this->getDirtyFlagAlias()}\}  === true}",
                tooltip: "{i18n>WEBAPP.SHELL.NETWORK.OFFLINE_CHANGES_PENDING}",
                type: sap.m.ButtonType.Transparent,
                press: function(oEvent) {
                    var oBtn = oEvent.getSource();
                    exfLauncher.showOfflineQueuePopoverForItem(
                        "{$widget->getMetaObject()->getAliasWithNamespace()}",
                        "{$widget->getUidColumn()->getDataColumnName()}",
                        oBtn.getModel().getProperty(oBtn.getBindingContext().getPath() + '/{$widget->getUidColumn()->getDataColumnName()}'),
                        oBtn
                    );
                }
            })
        }),
JS;
        }
        
        foreach ($widget->getColumns() as $column) {
            $column_defs .= $this->getFacade()->getElement($column)->buildJsConstructorForUiColumn() . ',';
        }
        
        return $column_defs;
    }
    
    protected function buildJsCellsForMTable()
    {
        $widget = $this->getWidget();
        $cells = '';
        
        
        // Add dirty-column for offline actions
        // NOTE: in the case of sap.m.Table it is important to place the dirty column
        // first because it checks for the UID column and eventually adds it. This MUST
        // happen before columns are rendered as there is no explicit link between columns
        // and cells and having more columns than cells (because of adding the UID column
        // at some point) causes very strange behavior!
        if ($this->hasDirtyColumn()) {
            $cells .= <<<JS
        new sap.m.Button({
            icon: "sap-icon://time-entry-request",
            visible: "{= \$\{{$this->getDirtyFlagAlias()}\}  === true}",
            tooltip: "{i18n>WEBAPP.SHELL.NETWORK.OFFLINE_CHANGES_PENDING}",
            type: sap.m.ButtonType.Transparent,
            press: function(oEvent) {
                var oBtn = oEvent.getSource();
                exfLauncher.showOfflineQueuePopoverForItem(
                    "{$widget->getMetaObject()->getAliasWithNamespace()}",
                    "{$widget->getUidColumn()->getDataColumnName()}",
                    oBtn.getModel().getProperty(oBtn.getBindingContext().getPath() + '/{$widget->getUidColumn()->getDataColumnName()}'),
                    oBtn
                );
            }
        }),
JS;
        }
        
        foreach ($widget->getColumns() as $column) {
            $cells .= $this->getFacade()->getElement($column)->buildJsConstructorForCell() . ",";
        }
        
        return $cells;
    }
    
    /**
     * Returns a comma-separated list of column constructors for sap.m.Table
     *
     * @return string
     */
    protected function buildJsColumnsForMTable()
    {
        $widget = $this->getWidget();
        
        // See if there are promoted columns. If not, make the first visible column promoted,
        // because sap.m.table would otherwise have no column headers at all.
        $promotedFound = false;
        $first_col = null;
        foreach ($widget->getColumns() as $col) {
            if (is_null($first_col) && ! $col->isHidden()) {
                $first_col = $col;
            }
            if ($col->getVisibility() === EXF_WIDGET_VISIBILITY_PROMOTED && ! $col->isHidden()) {
                $promotedFound = true;
                break;
            }
        }
        
        if (! $promotedFound && $first_col !== null) {
            $first_col->setVisibility(EXF_WIDGET_VISIBILITY_PROMOTED);
        }
        
        $column_defs = '';
        
        // Add dirty-column for offline actions
        if ($this->hasDirtyColumn()) {
            $column_defs .= <<<JS
            
                    new sap.m.Column('{$this->getDirtyFlagAlias()}',{
                        hAlign: "Center",
                        importance: "High",
                        visible: false,
                        popinDisplay: sap.m.PopinDisplay.Inline,
						demandPopin: true,
                    }),
JS;
        }
        
        foreach ($this->getWidget()->getColumns() as $column) {
            $column_defs .= $this->getFacade()->getElement($column)->buildJsConstructorForMColumn() . ",";
        }
        
        return $column_defs;
    }
    
    /**
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderParams()
     */
    protected function buildJsDataLoaderParams(string $oControlEventJsVar = 'oControlEvent', string $oParamsJs = 'params', $keepPagePosJsVar = 'bKeepPagingPos') : string
    {
        $commonParams = $this->buildJsDataLoaderParamsPaging($oParamsJs, $keepPagePosJsVar);
                  
        if ($this->isUiTable() === true) {            
            $tableParams = <<<JS

            {$oParamsJs}.data.columns = [];
            // Process currently visible columns:
            // - Add filters and sorters from column menus
            // - Add column name to ensure even optional data is read if required
            oTable.getColumns().forEach(oColumn => {
                var mVal = oColumn.getFilterValue();
                var fnParser = oColumn.data('_exfFilterParser');
                var oColParam;
                if (oColumn.data('_exfDataColumnName')) {
                    if (oColumn.data('_exfAttributeAlias')) {
                        oColParam = {
                            attribute_alias: oColumn.data('_exfAttributeAlias')
                        };
                        if (oColumn.data('_exfDataColumnName') !== oColParam.attribute_alias) {
                            oColParam.name = oColumn.data('_exfDataColumnName');
                        }
                    } else if (oColumn.data('_exfCalculation')) {
                        oColParam = {
                            name: oColumn.data('_exfDataColumnName'),
                            expression: oColumn.data('_exfCalculation')
                        };
                    }
                    if (oColParam !== undefined) {
                        {$oParamsJs}.data.columns.push(oColParam);
                    }
                }
    			if (oColumn.getFiltered() === true && mVal !== undefined && mVal !== null && mVal !== ''){
                    mVal = fnParser !== undefined ? fnParser(mVal) : mVal;
    				{$oParamsJs}['{$this->getFacade()->getUrlFilterPrefix()}' + oColumn.getFilterProperty()] = mVal;
    			}
    		});
            
            // If filtering just now, make sure the filter from the event is set too (eventually overwriting the previous one)
    		if ({$oControlEventJsVar} && {$oControlEventJsVar}.getId() == 'filter'){
                (function(oEvent) {
                    var oColumn = oEvent.getParameters().column;
                    var sFltrProp = oColumn.getFilterProperty();
                    var sFltrVal = oEvent.getParameters().value;
                    var fnParser = oColumn.data('_exfFilterParser'); 
                    var mFltrParsed = fnParser !== undefined ? fnParser(sFltrVal) : sFltrVal;

                    {$oParamsJs}['{$this->getFacade()->getUrlFilterPrefix()}' + sFltrProp] = mFltrParsed;
                    
                    if (mFltrParsed !== null && mFltrParsed !== undefined && mFltrParsed !== '') {
                        oColumn.setFiltered(true).setFilterValue(sFltrVal);
                    } else {
                        oColumn.setFiltered(false).setFilterValue('');
                    }  

                    // also set the filter as an advanced search item in the p13n panel
                    let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSearchPanel()}');

                    // Check if a filter for the property already exists
                    let aFilterItems = oDialog.getFilterItems();
                    let oExistingFilter = aFilterItems.find(oFilterItem => oFilterItem.getColumnKey() === sFltrProp);

                    if (oExistingFilter) {
                        // delete exiting property (if any)
                        oDialog.removeFilterItem(oExistingFilter);
                    } 
                    if (mFltrParsed !== null && mFltrParsed !== undefined && mFltrParsed !== ''){
                        // create new filter item if value is valid/not empty
                        var oFilterItem = new sap.m.P13nFilterItem({
                            "columnKey": sFltrProp,
                            "exclude": false,
                            "operation": "Contains",
                            "value1": mFltrParsed
                        });

                        oDialog.addFilterItem(oFilterItem);
                    }

                    // apply the changes from the p13n dialogue 
                    let oP13nDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'); 
                    if (oP13nDialog) {
                        oP13nDialog.fireOk();
                    }

                    // Also make sure the built-in UI5-filtering is not applied.
                    oEvent.cancelBubble();
                    oEvent.preventDefault();
                })($oControlEventJsVar);
            }
    		
    		// If sorting just now, overwrite the sort string and make sure the sorter in the configurator is set too
    		if ({$oControlEventJsVar} && {$oControlEventJsVar}.getId() == 'sort'){
                {$oParamsJs}.sort = {$oControlEventJsVar}.getParameters().column.getSortProperty();
                {$oParamsJs}.order = {$oControlEventJsVar}.getParameters().sortOrder === 'Descending' ? 'desc' : 'asc';
                
                // get p13n model 
                let oDialog = sap.ui.getCore().byId('{$this->getP13nElement()->getIdOfSortPanel()}');
                let oModel = oDialog.getModel('{$this->getConfiguratorElement()->getModelNameForConfig()}');
                let aSorters = oModel.getProperty('/sorters') || [];

                // new sorter object
                let oNewSorter = {
                    attribute_alias: {$oControlEventJsVar}.getParameters().column.getSortProperty(),
                    direction: {$oControlEventJsVar}.getParameters().sortOrder
                };
                
                let bExists = aSorters.some(oSorter => oSorter.attribute_alias === oNewSorter.attribute_alias);
                if (!bExists) {
                    // if entry doesnt exist, add new sorter
                    aSorters.push(oNewSorter);
                }
                else{
                    // if it exists, update sorting direction
                    let oExistingSorter = aSorters.find(oSorter => oSorter.attribute_alias === oNewSorter.attribute_alias);
                    if (oExistingSorter) {
                        oExistingSorter.direction = oNewSorter.direction;
                    }
                }
                
                // update the model/refresh
                oModel.setProperty('/sorters', aSorters);
                oModel.refresh(true);

                // Also make sure, the built-in UI5-sorting is not applied.
                $oControlEventJsVar.cancelBubble();
                $oControlEventJsVar.preventDefault();
    		}

            // Set sorting indicators for columns
            var aSortProperties = ({$oParamsJs}.sort ? {$oParamsJs}.sort.split(',') : []);
            var aSortOrders = ({$oParamsJs}.sort ? {$oParamsJs}.order.split(',') : []);
            var iIdx = -1;
            sap.ui.getCore().byId('{$this->getId()}').getColumns().forEach(function(oColumn){
                iIdx = aSortProperties.indexOf(oColumn.getSortProperty());
                if (iIdx > -1) {
                    oColumn.setSorted(true);
                    oColumn.setSortOrder(aSortOrders[iIdx] === 'desc' ? sap.ui.table.SortOrder.Descending : sap.ui.table.SortOrder.Ascending);
                } else {
                    oColumn.setSorted(false);
                }
            });
            
            // Make sure, the column filter indicator is ON if the column is filtered over via advanced search 
            (function(){
                var oSearchPanel = sap.ui.getCore().byId('{$this->getConfiguratorElement()->getIdOfSearchPanel()}');
                var aSearchFItems = oSearchPanel.getFilterItems();
                var aColumns = oTable.getColumns();
                aColumns.forEach(function(oColumn) {
                    var sFilterVal = oColumn.getFilterValue();
                    var bFiltered = sFilterVal !== '' && sFilterVal !== null && sFilterVal !== undefined;
                    if (bFiltered) {
                        return;
                    }
                    aSearchFItems.forEach(function(oItem){
                        if (oItem.getColumnKey() === oColumn.data('_exfAttributeAlias')) {
                            bFiltered = true;
                        }
                    });
                    oColumn.setFiltered(bFiltered);
                });
            })();
		
JS;
        } elseif ($this->isMTable()) {
            $tableParams = <<<JS

            // request config for opt. columns
            {$oParamsJs}.data.columns = [];

            // Set sorting indicators for columns
            var aSortProperties = ({$oParamsJs}.sort ? {$oParamsJs}.sort.split(',') : []);
            var aSortOrders = ({$oParamsJs}.sort ? {$oParamsJs}.order.split(',') : []);
            var iIdx = -1;
            sap.ui.getCore().byId('{$this->getId()}').getColumns().forEach(function(oColumn){
                iIdx = aSortProperties.indexOf(oColumn.data('_exfAttributeAlias'));
                var oColParam;

                if (iIdx > -1) {
                    oColumn.setSortIndicator(aSortOrders[iIdx] === 'desc' ? 'Descending' : 'Ascending');
                } else {
                    oColumn.setSortIndicator(sap.ui.core.SortOrder.None);
                }

                // add optional columns as request data 
                if (oColumn.data('_exfDataColumnName')) {
                    if (oColumn.data('_exfAttributeAlias')) {
                        oColParam = {
                            attribute_alias: oColumn.data('_exfAttributeAlias')
                        };
                        if (oColumn.data('_exfDataColumnName') !== oColParam.attribute_alias) {
                            oColParam.name = oColumn.data('_exfDataColumnName');
                        }
                    } else if (oColumn.data('_exfCalculation')) {
                        oColParam = {
                            name: oColumn.data('_exfDataColumnName'),
                            expression: oColumn.data('_exfCalculation')
                        };
                    }
                    if (oColParam !== undefined) {
                        {$oParamsJs}.data.columns.push(oColParam);
                    }
                }
            });

JS;
        }
		
        return $commonParams . $tableParams;
    }
    
    /**
     * Returns inline JS code to refresh the table.
     *
     * If the code snippet is to be used somewhere, where the controller is directly accessible, you can pass the
     * name of the controller variable to $oControllerJsVar to increase performance.
     *
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsRefresh()
     *
     * @param bool $keepPagingPos
     * @param string $oControllerJsVar
     *
     * @return UI5DataTable
     */
    public function buildJsRefresh(bool $keepPagingPos = false, string $oControllerJsVar = null)
    {
        $params = "undefined, " . ($keepPagingPos ? 'true' : 'false');
        if ($oControllerJsVar === null) {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, $params);
        } else {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, $params, $oControllerJsVar);
        }
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null)
    {
        $widget = $this->getWidget();
        $dataObj = $this->getMetaObjectForDataGetter($action);
        
        $data = <<<JS
{
            oId: '{$this->getWidget()->getMetaObject()->getId()}',
            rows: aRows
        }
JS;
        if ($action !== null && $action->isDefinedInWidget() && $action->getWidgetDefinedIn() instanceof DataButton) {
            $customMode = $action->getWidgetDefinedIn()->getInputRows();
        } else {
            $customMode = null;
        }
        
        switch (true) {
            // If no action is specified, return the entire row model
            case $customMode === DataButton::INPUT_ROWS_ALL:
            case $action === null:
                $aRowsJs = "{$this->buildJsGetRowsAll('oTable')} || []";
                break;
            
            // If the button requires none of the rows explicitly
            case $customMode === DataButton::INPUT_ROWS_NONE:
                return '{}';
                
            // If we are reading, than we need the special data from the configurator
            // widget: filters, sorters, etc.
            case $action instanceof iReadData:
                return $this->getConfiguratorElement()->buildJsDataGetter($action);
                
            // Editable tables with modifying actions return all rows either directly or as subsheet
            case $customMode === DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
            case $this->isEditable() && ($action instanceof iModifyData):
            case $this->isEditable() && ($action instanceof iCallOtherActions) && $action->containsActionClass(iModifyData::class):
                $aRowsJs = "{$this->buildJsGetRowsAll('oTable')} || []";
                switch (true) {
                    case $dataObj->is($widget->getMetaObject()) && $customMode !== DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
                    case $action->getInputMapper($widget->getMetaObject()) !== null && $customMode !== DataButton::INPUT_ROWS_ALL_AS_SUBSHEET:
                        break;
                    default:
                        // If the data is intended for another object, make it a nested data sheet
                        // If the action is based on the same object as the widget's parent, use the widget's
                        // logic to find the relation to the parent. Otherwise try to find a relation to the
                        // action's object and throw an error if this fails.
                        if ($widget->hasParent() && $dataObj->is($widget->getParent()->getMetaObject()) && $relPath = $widget->getObjectRelationPathFromParent()) {
                            $relAlias = $relPath->toString();
                        } elseif ($relPath = $dataObj->findRelationPath($widget->getMetaObject())) {
                            $relAlias = $relPath->toString();
                        }
                        
                        if ($relAlias === null || $relAlias === '') {
                            throw new WidgetLogicError($widget, 'Cannot use editable table with object "' . $widget->getMetaObject()->getName() . '" (alias ' . $widget->getMetaObject()->getAliasWithNamespace() . ') as input widget for action "' . $action->getName() . '" with object "' . $dataObj->getName() . '" (alias ' . $dataObj->getAliasWithNamespace() . '): no forward relation could be found from action object to widget object!', '7B7KU9Q');
                        }
                        $data = <<<JS
({$this->buildJsIsDataPending()} ? {} : {
            oId: '{$dataObj->getId()}',
            rows: [
                {
                    '{$relAlias}': {
                        oId: '{$widget->getMetaObject()->getId()}',
                        rows: aRows
                    }
                }
            ]
        })
            
JS;
                }
                break;
                
            // In all other cases the data are the selected rows
            default:
                if (($widget instanceof iSupportMultiSelect) && $widget->getMultiSelect() === true) {
                    $aRowsJs = "oTable.getModel('{$this->getModelNameForSelections()}').getProperty('/rows')";
                } else {
                    $aRowsJs = $this->buildJsGetRowsSelected('oTable');
                }
                
        }
        
        // Determine the columns we need in the actions data
        $colNamesList = implode(',', $widget->getActionDataColumnNames());
        
        return <<<JS
    function() {
        var oTable = sap.ui.getCore().byId('{$this->getId()}');
        var oDirtyColumn = sap.ui.getCore().byId('{$this->getDirtyFlagAlias()}');
        var aRows = {$aRowsJs};
        
        // Remove any keys, that are not in the columns of the widget
        aRows = aRows.map(({ $colNamesList }) => ({ $colNamesList }));

        return $data;
    }()
JS;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\Traits\UI5DataElementTrait::buildJsDataLoaderOnLoadedRestoreSelection()
     */
    protected function buildJsDataLoaderOnLoadedRestoreSelection(string $oTableJs) : string
    {
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true ) {
            $uidColJs = $widget->hasUidColumn() ? $this->escapeString($widget->getUidColumn()->getDataColumnName()) : 'null';
                    
            // Restore previous selection
            return <<<JS
                setTimeout(function(oTable) {
                    const oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
                    const aPrevSelectedRows = oModelSelected.getProperty('/rows');
                    const aNowSelectedRows = {$this->buildJsGetRowsSelected($oTableJs, true)};
                    const aRows = {$this->buildJsGetRowsAll($oTableJs)};
                    const sUidCol = $uidColJs;
                    const fnSelect = function(iRowIdx) {
                        {$this->buildJsSelectRowByIndex($oTableJs, 'iRowIdx', false, 'false')}
                    };
                    const fnDeselect = function(iRowIdx) {
                        {$this->buildJsSelectRowByIndex($oTableJs, 'iRowIdx', true, 'false')}
                    }
                    if (aPrevSelectedRows === undefined) {
                        return;
                    }
                    aNowSelectedRows.forEach(function (oRow) {
                        var bSelected = exfTools.data.indexOfRow(aPrevSelectedRows, oRow, sUidCol) > -1;
                        var iRowIdx = exfTools.data.indexOfRow(aRows, oRow, sUidCol);
                        if (iRowIdx === -1) {
                            return;
                        }
                        if (bSelected) {
                            fnSelect(iRowIdx);
                        } else {
                            fnDeselect(iRowIdx);
                        }
                    });
                    aPrevSelectedRows.forEach(function (oRow) {
                        var iRowIdx = exfTools.data.indexOfRow(aRows, oRow, sUidCol);
                        if (iRowIdx === -1) {
                            return;
                        } else {
                            fnSelect(iRowIdx);
                        }
                    });
                }, 0, {$oTableJs});

JS;
        } else {
            return '';
        }
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsGetRowsSelected()
     */
    protected function buildJsGetRowsSelected(string $oTableJs) : string
    {
        if ($this->isUiTable()) {
            if($this->getWidget()->getMultiSelect() === false) {
                $rows = "($oTableJs && $oTableJs.getSelectedIndex() !== -1 && $oTableJs.getContextByIndex($oTableJs.getSelectedIndex()) !== undefined ? [$oTableJs.getContextByIndex($oTableJs.getSelectedIndex()).getObject()] : [])";
            } else {
                $rows = <<<JS
                    (function(oTable){
                        var selectedIdx = oTable.getSelectedIndices(); 
                        var aRows = []; 
                        selectedIdx.forEach(function(i) {
                            var oCtxt = oTable.getContextByIndex(i);
                            if (oCtxt && oCtxt.getObject()) {
                                aRows.push(oCtxt.getObject());
                            }
                        }); 
                        return aRows;
                    })($oTableJs)
JS;
            }
        } else {
            if($this->getWidget()->getMultiSelect() === false) {
                $rows = "($oTableJs && $oTableJs.getSelectedItem() ? [$oTableJs.getSelectedItem().getBindingContext().getObject()] : [])";
            } else {
                $rows = <<<JS
                    $oTableJs.getSelectedContexts().reduce(
                        function(aRows, oCtxt) {
                            aRows.push(oCtxt.getObject()); 
                            return aRows;
                        },
                        []
                    )
JS;
            }
        }
        return $rows;
    }
        
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetter()
     */
    public function buildJsValueSetter($value, $dataColumnName = null, $rowNr = null)
    {
        if ($rowNr === null) {
            if ($this->isUiTable()) {
                $rowNr = "oTable.getSelectedIndex()";
            } else {
                $rowNr = "oTable.indexOfItem(oTable.getSelectedItem())";
            }
        }
        
        if ($dataColumnName === null) {
            $dataColumnName = $this->getWidget()->getUidColumn()->getDataColumnName();
        }
        
        return <<<JS
        
function(){
    var oTable = sap.ui.getCore().byId('{$this->getId()}');
    var oModel = oTable.getModel();
    var iRowIdx = {$rowNr};
    
    if (iRowIdx !== undefined && iRowIdx >= 0) {
        var aData = oModel.getData().rows;
        aData[iRowIdx]["{$dataColumnName}"] = $value;
        oModel.setProperty("/rows", aData);
        // TODO why does the code below not work????
        // oModel.setProperty("/rows(" + iRowIdx + ")/{$dataColumnName}", {$value});
    }
}()

JS;
    }
        
    /**
     * Returns an inline JS-condition, that evaluates to TRUE if the given oTargetDom JS expression
     * is a DOM element inside a list item or table row.
     * 
     * This is important for handling browser events like dblclick. They can only be attached to
     * the entire control via attachBrowserEvent, while we actually only need to react to events
     * on the items, not on headers, footers, etc.
     * 
     * @param string $oTargetDomJs
     * @return string
     */
    protected function buildJsClickIsTargetRowCheck(string $oTargetDomJs = 'oTargetDom') : string
    {
        if ($this->isUiTable()) {
            return "{$oTargetDomJs} !== undefined && $({$oTargetDomJs}).parents('.sapUiTableCCnt').length > 0";
        }
        
        if ($this->isMTable()) {
            return "{$oTargetDomJs} !== undefined && ($({$oTargetDomJs}).parents('tr.sapMListTblRow:not(.sapMListTblHeader)').length > 0 || $({$oTargetDomJs}).parents('tr.sapMListTblSubRow').length > 0)";
        }
        
        if ($this->isMList()) {
            return "{$oTargetDomJs} !== undefined && $({$oTargetDomJs}).parents('li.sapMSLI').length > 0";
        }
        
        return 'true';
    }
    
    /**
     * 
     * @param string $oDomElementClickedJs
     * @return string
     */
    protected function buildJsClickGetRowIndex(string $oDomElementClickedJs) : string
    {
        if ($this->isUiTable()) {
            return "sap.ui.getCore().byId('{$this->getId()}').getFirstVisibleRow() + $({$oDomElementClickedJs}).parents('tr').index()";
        } 
        
        if ($this->isMTable()) {
            // NOTE: sap.m.Table with row groups will count group headers as "items" - same as the actual rows.
            // So we can't call `indexOfItem()` here. Instead, to get the index of the real row, we need to filter 
            // away the group headers explicitly
            return <<<JS
(function(){
    var jqTr = $({$oDomElementClickedJs}).parents('tr');
    var oItem;
    var iIdx = -1;
    if (jqTr.hasClass('sapMListTblSubRow')) {
        jqTr = jqTr.prev();
    }
    oItem = sap.ui.getCore().byId(jqTr[0].id);
    if (oItem) {
        iIdx = sap.ui.getCore().byId('{$this->getId()}')
            .getItems()
            .filter(function(oItem){
                return oItem.getBindingContext() !== undefined
            })
            .indexOf(oItem);
    }
    return iIdx;
})()
JS;
        }
           
        if ($this->isMList()) {
            return "$({$oDomElementClickedJs}).parents('li.sapMSLI').length";
        }
        
        return "-1";
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsClickGetColumnAttributeAlias()
     */
    protected function buildJsClickGetColumnAttributeAlias(string $oDomElementClickedJs) : string
    {
        if ($this->isUiTable()) {
            return "(function(domEl){var oCell = sap.ui.getCore().byId($(domEl).closest('[data-sap-ui-colid]').data('sap-ui-colid')); return oCell ? oCell.data('_exfAttributeAlias') : null;})($oDomElementClickedJs)";
        }
        if ($this->isMTable()) {
            return "(function(domEl){var oCell = sap.ui.getCore().byId($(domEl).closest('[data-sap-ui-column]').data('sap-ui-column')); return oCell ? oCell.data('_exfAttributeAlias') : null;})($oDomElementClickedJs)";
        }
        return "null";
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsClickHandlerLeftClick()
     */
    protected function buildJsClickHandlerLeftClick($oControllerJsVar = 'oController') : string
    {
        // IDEA Theoretically the sap.m.ListBase has it's own support for a context menu, but that triggers
        // the browser context menu too. Could not find a way to avoid it, so we use a custom context
        // menu here. This requires an empty menu in the contextMenu property of the list control - 
        // see. buildJsConstructorForMTable()
        
        // Single click. Currently only supports one click action - the first one in the list of buttons
        if ($leftclick_button = $this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_LEFT_CLICK)[0]) {
            if ($this->isUiTable()) {
                return <<<JS
                
            .attachBrowserEvent("click", function(oEvent) {
        		var oTargetDom = oEvent.target;
                if(! ({$this->buildJsClickIsTargetRowCheck('oTargetDom')})) return;

                {$this->getFacade()->getElement($leftclick_button)->buildJsClickEventHandlerCall($oControllerJsVar)};
            })
JS;
            } else {
                return <<<JS
                
            .attachItemPress(function(oEvent) {
                var oListItem = oEvent.getParameters().listItem;
                if (oListItem === undefined || oListItem.getMetadata().getName() === 'sap.m.GroupHeaderListItem') {
                    return;
                }

                {$this->getFacade()->getElement($leftclick_button)->buildJsClickEventHandlerCall($oControllerJsVar)};
            })
JS;
            }
        }
        
        return '';
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderOnLoaded()
     */
    protected function buildJsDataLoaderOnLoaded(string $oModelJs = 'oModel') : string
    {
        $paginator = $this->getPaginatorElement();
        $uidColNameJs = $this->getDataWidget()->hasUidColumn() ? "'{$this->getDataWidget()->getUidColumn()->getDataColumnName()}'" : 'undefined';
        
        // Add single-result action to onLoadSuccess. Make sure it is only fired once as
        // long as the same UID is selected. This means, if the row itself changes (e.g.
        // being saved from the single-select-action) it is still regarded as the same row
        // as long as it has the same UID. Thus the action will not get called repeatedly.
        if (($singleResultButton = $this->getWidget()->getButtons(function($btn) {return ($btn instanceof DataButton) && $btn->isBoundToSingleResult() === true;})[0]) || $this->getWidget()->getSelectSingleResult()) {
            $buttonClickJs = '';
            if ($singleResultButton) {
                $buttonClickJs = <<<JS

                    if (lastRow === undefined || exfTools.data.compareRows(curRow, lastRow, sUidCol) === false) {
                        oTable._singleResultActionPerformedFor = curRow;
                        {$this->getFacade()->getElement($singleResultButton)->buildJsClickEventHandlerCall('oController')};
                    }
JS;
            }
            $singleResultJs = <<<JS

            (function(oTable, oModel){
                if (oModel.getData().rows.length === 1) {
                    var sUidCol = $uidColNameJs;
                    var curRow = {$oModelJs}.getData().rows[0];
                    var lastRow = oTable._singleResultActionPerformedFor;
                    {$this->buildJsSelectRowByIndex('oTable', '0')}
                    {$buttonClickJs}                
                } else {
                    oTable._singleResultActionPerformedFor = {};
                }
            })(oTable, {$oModelJs});            
JS;
        }
                    
        // For some reason, the sorting indicators on the column are changed to the opposite after
        // the model is refreshed. This hack fixes it by forcing sorted columns to keep their
        // indicator.
        $uiTablePostprocessing = '';
        $uiTableSetFooterRows = '';
        if ($this->isUiTable() === true) {
            $this->getController()->addMethod(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, oModel', $this->buildJsUiTableColumnResize('oTable', 'oModel'));
            
            $uiTablePostprocessing .= <<<JS

            oTable.getColumns().forEach(function(oColumn){
                if (oColumn.getSorted() === true) {
                    var order = oColumn.getSortOrder()
                    setTimeout(function(){
                        oColumn.setSortOrder(order);
                    }, 0);
                }
            });
JS;
            $uiTableSetFooterRows = <<<JS

            if (footerRows){
				oTable.setFixedBottomRowCount(parseInt(footerRows));
			}
JS;
            
            // Weird code to make the table fill it's container. If not done, tables within
            // sap.f.Card will not be high enough. 
            $uiTablePostprocessing .= 'oTable.setVisibleRowCountMode("Fixed").setVisibleRowCountMode("Auto");';
            
            // Make sure, row grouping works
            $uiTablePostprocessing .= $this->buildJsUiTableInitRowGrouping('oTable', 'oModel');
            
            // Optimize column width AFTER all columns are rendered
            // TODO #ui5-update mode column width optimization to rowsUpdated event of sap.ui.table.Table (available since 1.86)
            $uiTablePostprocessing .= <<<JS

            setTimeout(function(){
                {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, ' . $oModelJs)}
            }, 100);
JS;
            
            // TODO #ui5-update move stylers to rowsUpdated event of sap.ui.table.Table (available since 1.86)
            if ($stylersJs = $this->buildJsColumnStylers()) {
                $this->getController()->addOnEventScript($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, "setTimeout(function(){ $stylersJs }, 0);");
                $uiTablePostprocessing .= <<<JS

            setTimeout(function(){
                $stylersJs
            }, 500);
JS;
            }
        }
        
        $updatePaginator = '';
        if ($this->hasPaginator()) {
            $updatePaginator = <<<JS
            
            {$paginator->buildJsSetTotal($oModelJs . '.getProperty("/recordsFiltered")', 'oController')};
            {$paginator->buildJsRefresh('oController')};
JS;
        }
        return $this->buildJsDataLoaderOnLoadedViaTrait($oModelJs) . <<<JS

			var footerRows = {$oModelJs}.getProperty("/footerRows");
            {$uiTableSetFooterRows}
            {$updatePaginator}
            {$this->getController()->buildJsEventHandler($this, self::EVENT_NAME_CHANGE, false)};
            {$singleResultJs};
            {$uiTablePostprocessing};
            {$this->buildJsCellConditionalDisablers()};           
JS;
    }
    
    /**
     * To get the experimental row grouping of the ui.table working, we need to
     * 
     * 1. set `enableGrouping` of the table (see `buildJsConstructorForUiTable()`)
     * 2. set the `grouped` flag on the column (see `UI5DataColumn::buildJsConstructorForUiColumn()`)
     * 3. pass the column or its id to the table via `setGroupBy` which is done here
     * 
     * Strangely `.setGroupBy()` fails if the column has no model data, so we
     * must do it here after the model was loaded.
     * 
     * Also note, that group names are cached in the context of each row binding context,
     * so we must call resetExperimentalGrouping() every time data is replaced.
     * See `sap.ui.table.utils._GroupingUtils` in UI5 for more details.
     * 
     * In contrast to the sap.m.Table, there is no official way to influence the group names,
     * so we use a hack here to explicitly set them in the group info of the contexts, which
     * is used by UI5 internally.
     * 
     * @param string $oTableJs
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsUiTableInitRowGrouping(string $oTableJs, string $oModelJs) : string
    {
        if (! $this->getWidget()->hasRowGroups()) {
            return '';
        }
        $grouper = $this->getWidget()->getRowGrouper();
        $groupFormatterJs = $this->getFacade()->getDataTypeFormatter($grouper->getGroupByColumn()->getDataType())->buildJsFormatter('mVal');
        $groupCaption = $grouper->getHideCaption() ? '' : $this->escapeJsTextValue($grouper->getCaption());
        $groupCaption .= $groupCaption ? ': ' : '';
        switch ($grouper->getExpandGroups()) {
            case DataRowGrouper::EXPAND_NO_GROUPS: $expandGroupJs = 'false'; break;
            case DataRowGrouper::EXPAND_FIRST_GROUP: $expandGroupJs = '1'; break;
            default: $expandGroupJs = 'true';
            
        }
        
        // NOTE: sap.ui.table.utils._GroupingUtils.resetExperimentalGrouping($oTableJs) did not work: it produced
        // empty group titles whenever their content was to change
        return  <<<JS
            
            (function(oTable, oModel) {
                if (! oModel.getData().rows || oModel.getData().rows.length === 0) {
                    return;
                }
                oTable.setEnableGrouping(true);
                oTable.setGroupBy('{$this->getFacade()->getElement($grouper->getGroupByColumn())->getId()}');
                
                var oBinding = oTable.getBinding('rows');
                var iRowCnt = oTable._getTotalRowCount();
                var iHeaderIdx = -1;
                var mExpand = $expandGroupJs;
                var aCtxts = oBinding.getContexts(0, iRowCnt);
                var iExpanded = 0;
                for (var i = 0; i < iRowCnt; i++) {
                    if (aCtxts[i].__groupInfo) {
                        aCtxts[i].__groupInfo.name = (function(mVal) {
                            if (mVal === null || mVal === undefined) {
                                return '{$groupCaption}{$this->escapeJsTextValue($grouper->getEmptyText())}';
                            }
                            return '{$groupCaption}' + {$groupFormatterJs}
                        })(aCtxts[i].__groupInfo.name);
                    }
                    if (oBinding.isGroupHeader(i)) {
                        iHeaderIdx++;
                        if (mExpand === false || (Number.isInteger(mExpand) && iHeaderIdx >= (mExpand - 1))) {
                            oBinding.collapse(i);
                        }
                    }
                }
                // Resize columns every time a group gets expanded
                oBinding.attachChange(function(oEvent) {
                    var iExpandedBefore = iExpanded;
                    // Change-events on expand/collapse do not have a reason. Ignore others
                    if (oEvent.getParameters().reason !== undefined) {
                        return;
                    }
                    iExpanded = 0;
                    for (var i=0; i<iRowCnt; i++) {
                        if(oBinding.isExpanded(i)) iExpanded += 1;
                    }
                    // If a group just got expanded, there are more expanded nodes now.
                    // Resize the columns to match the newly visible data
                    if (iExpanded > iExpandedBefore) {
                        setTimeout(function(){
                            {$this->getController()->buildJsMethodCallFromController(self::CONTROLLER_METHOD_RESIZE_COLUMNS, $this, 'oTable, ' . $oModelJs)}
                        }, 100);
                    }
                });
            })($oTableJs, $oModelJs);
JS;
    }
    
    /**
     * Optimize column width. This is not easy with sap.ui.table.Table :(
     * 
     * 1. oTable.autoResizeColumn() sets the focus to the column, so it is scrolled into
     * view. After a number of workaround attempts, a hack of UI5 solves the problem now. 
     * See Docs/UI5_modifications.md for details.
     * 2. The optimizer only works AFTER all column were populated, so we need a setTimeout().
     * TODO would be better to have an event, but none seemed suitable... Perhaps rowsUpdated? #ui5-update
     * 3. Since the optimizer works asynchronously, it will break if while it is running the
     * underlying data changes. In an attempt to avoid this, we do not optimize empty data.
     * 4. It seems, that the empty space on the right side of the table (if it is not occupied
     * with columns completely) is a column too. Optimizing that column will stretch some of
     * the others again as it does not have any data. So we check if each column has a DOM
     * element (that special column does not) and only optimize it then.
     * 4. The optimizer does not take the column header into account, so on narrow columns
     * the header gets truncated. We need to double-check this after all columns are resized
     * 5. Also need to make sure, the maximum width of the column is not exceeded
     * 6. TODO might need to check for minimum width too!
     * 
     * @param string $oTableJs
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsUiTableColumnResize(string $oTableJs, string $oModelJs) : string
    {
        if (($this->getWidget() instanceof DataTable) && $this->getWidget()->getAutoColumnWidth() === false) {
            return '';
        }
        return <<<JS

                $oTableJs.data("_exfIsAutoResizing", true);  // set auto resize flag

                var bResized = false;
                var oInitWidths = {};
                if (! $oModelJs.getData().rows || $oModelJs.getData().rows.length === 0) {
                    return;
                }
                
                $oTableJs.getColumns().reverse().forEach(function(oCol, i) {
                    var oWidth = oCol.data('_exfWidth');
                    if (! oWidth || $('#'+oCol.getId()).length === 0) return;
                    oInitWidths[$oTableJs.indexOfColumn(oCol)] = $('#'+oCol.getId()).width();
                    if (oCol.getVisible() === true && oWidth.auto === true) {
                        bResized = true;
                        $oTableJs.autoResizeColumn($oTableJs.indexOfColumn(oCol));
                    }
                    if (oWidth.fixed) {
                        oCol.setWidth(oWidth.fixed);
                    }
                });

                if (bResized) {
                    setTimeout(function(){
                        $oTableJs.getColumns().forEach(function(oCol){

                            var oWidth = oCol.data('_exfWidth');
                            var jqCol = $('#'+oCol.getId());
                            var jqLabel = jqCol.find('label');
                            var iWidth = null;
                            var iColIdx = $oTableJs.indexOfColumn(oCol);

                            if (! oWidth) return;
                            if (! oCol.getWidth() && oWidth.auto === true && oInitWidths[iColIdx] !== undefined) {
                                oCol.setWidth(oInitWidths[iColIdx] + 'px');
                            }
                            if (oCol.getVisible() === true && oWidth.auto === true) {
                                if (! jqLabel[0]) return;
                                if (jqLabel[0].scrollWidth > jqLabel.width()) {
                                    oCol.setWidth((jqLabel[0].scrollWidth + (jqCol.outerWidth()-jqLabel.width()) + 1).toString() + 'px');
                                }
                                if (oWidth.min) {
                                    iWidth = $('<div style="width: ' + oWidth.min + '"></div>').width();
                                    if (jqCol.outerWidth() < iWidth) {
                                        oCol.setWidth(oWidth.min);
                                    }
                                }
                                if (oWidth.max) {
                                    iWidth = $('<div style="width: ' + oWidth.max + '"></div>').width();
                                    if (jqCol.outerWidth() > iWidth) {
                                        oCol.setWidth(oWidth.max);
                                    }
                                }
                            }
                        });
                    }, 0);
                }
                
                // manually resized columns should always keep their width
                // (only skipping them didnt work, so we set the saved value then return)
                setTimeout(function(){
                    $oTableJs.getColumns().forEach(function(oCol){
                        if (oCol.data('_exfCustomColWidth')){
                            oCol.setWidth(oCol.data('_exfCustomColWidth'));
                            return;
                        } 
                    });
                }, 0);

                setTimeout(function(){
                    {$this->buildJsFixRowHeight($oTableJs)}
                    $oTableJs.data("_exfIsAutoResizing", false);  // done auto resizing
                }, 0);
JS;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsDataLoaderPrepare()
     */
    protected function buildJsDataLoaderPrepare() : string
    {
        return $this->buildJsShowMessageOverlay($this->getWidget()->getEmptyText());
    }
    
    /**
     *
     * {@inheritdoc}
     * @see UI5DataElementTrait::buildJsOfflineHint()
     */
    protected function buildJsOfflineHint(string $oTableJs = 'oTable') : string
    {
        $hint = $this->escapeJsTextValue($this->translate('WIDGET.DATATABLE.OFFLINE_HINT'));
        if ($this->isMList() || $this->isMTable()) {
            return $oTableJs . '.setNoDataText("' . $hint . '");';
        } else {
            return "sap.ui.getCore().byId('{$this->getIdOfNoDataOverlay()}').setText(\"{$hint}\")";
        }
        return '';
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see UI5DataElementTrait::getCaption()
     */
    public function getCaption() : string
    {
        if ($caption = $this->getCaptionViaTrait()) {
            $caption .= ($this->isUiTable() && $this->hasPaginator() ? ': ' : '');
        }
        return $caption;
    }
    
    /**
     * Returns the JS code to select the row with the zero-based index $iRowIdxJs and scroll it into view.
     * 
     * @param string $oTableJs
     * @param string $iRowIdxJs
     * @param bool $deSelect
     * @return string
     */
    public function buildJsSelectRowByIndex(string $oTableJs = 'oTable', string $iRowIdxJs = 'iRowIdx', bool $deSelect = false, string $bScrollToJs = 'true') : string
    {
        if ($this->isMList() === true) {
            $setSelectJs = ($deSelect === true) ? 'false' : 'true';
            //filter items to only get items with binding context
            //necessary as row groupers add item without binding to table
            return <<<JS

                var oItem = {$oTableJs}.getItems().filter(function(oItem){
                    return oItem.getBindingContext() !== undefined
                })[{$iRowIdxJs}];
                {$oTableJs}.setSelectedItem(oItem, {$setSelectJs});
                {$oTableJs}.fireSelectionChange({
                    listItem: oItem, 
                    selected: $setSelectJs
                });
                if ($bScrollToJs && oItem !== undefined) {
                    oItem.focus();
                }

JS;

                
        } else {
            $deSelectJs = $deSelect ? 'true' : 'false';
            // Cannot use the row index directly here because row group headers
            // are also part of the row numbering. In any case, it is much more
            // reliable to check each binding and compare its path to the row
            // number inside the rows array of the model.
            return <<<JS
                (function(oTable, iRowIdx, bDeselect, bScrollTo) {
                    var aSelections = oTable.getSelectedIndices();
                    var iTableIdx = iRowIdx;
                    var oBinding = oTable.getBinding("rows");
                    var fnFindTableIdx = function(iRowIdx) {
                        for (var i = 0; i < oBinding.getLength(); i++) {
                            var context = oBinding.getContexts(i, 1)[0]; // Get context for each row
                            if (context && context.getPath() === `/rows/` + iRowIdx) {
                                return i; // Match found
                            }
                        }
                    };
                    iTableIdx = fnFindTableIdx(iRowIdx);
                    oTable.clearSelection();
                    if (bDeselect === false) {
                        oTable.setSelectedIndex(iTableIdx);
                    }
                    if (bScrollTo) {
                        oTable.setFirstVisibleRow(iTableIdx);
                    }
                    aSelections.forEach(function(i){
                        if (i !== iTableIdx) {
                            oTable.addSelectionInterval(i, i);
                        }
                    });
                })($oTableJs, $iRowIdxJs, $deSelectJs, $bScrollToJs)

JS;
        }
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsShowMessageOverlay()
     */
    protected function buildJsShowMessageOverlay(string $message) : string
    {
        $hint = $this->escapeJsTextValue($message);
        if ($this->isMList() || $this->isMTable()) {
            $setNoData = "sap.ui.getCore().byId('{$this->getId()}').setNoDataText('{$hint}')";
        } elseif ($this->isUiTable()) {
            $setNoData = "sap.ui.getCore().byId('{$this->getIdOfNoDataOverlay()}').setText('{$hint}')";
        }
        return $this->buildJsDataResetter() . ';' . $setNoData;
    }
    
    public function buildJsRefreshPersonalization() : string
    {
        $widget = $this->getWidget();
        $uidColName = $widget->hasUidColumn() ? $widget->getUidColumn()->getDataColumnName() : "''";
        $colsOptional = $widget->getConfiguratorWidget()->getOptionalColumns();
        $colsOptionalJs = "var oColsOptional = {};";
        if (! empty($colsOptional)) {
            $colsOptionalJs = "var oColsOptional = {$this->getController()->buildJsDependentObjectGetter(self::CONTROLLER_VAR_OPTIONAL_COLS, $this, 'oController')};";
        }
        if ($this->isUiTable() === true) {
            return <<<JS

                        var oController = {$this->getController()->buildJsControllerGetter($this)};
                        var aColsConfig = {$this->getConfiguratorElement()->buildJsP13nColumnConfig()};
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var aColumns = oTable.getColumns();
                        {$colsOptionalJs}
                        var aColumnsNew = [];
                        var bOrderChanged = false;
                        var iConfOffset = 0;
                        var oDirtyColumn = aColumns.filter(oColumn => oColumn.getId() === "{$this->getDirtyFlagAlias()}")[0];
                        var oUidCol = aColumns.filter(oColumn => oColumn.data('data_column_name') === {$this->escapeString($uidColName)})[0];

                        if (oDirtyColumn !== undefined) {
                            iConfOffset += 1;
                            aColumnsNew.push(oDirtyColumn);  
                        }
                        
                        aColsConfig.forEach(function(oColConfig, iConfIdx) {
                            var bFoundCol = false;
                            var oColumn;
                            // See if the column is part of the table right now
                            for (var iColIdx = 0; iColIdx < aColumns.length; iColIdx++) {
                                oColumn = aColumns[iColIdx];
                                if (oColumn.getId() === oColConfig.column_id) {
                                    if (iColIdx !== iConfIdx + iConfOffset) bOrderChanged = true;
                                    oColumn.setVisible(oColConfig.visible);
                                    aColumnsNew.push(oColumn);
                                    bFoundCol = true;
                                    return;
                                }
                            }
                            // If it is not AND it is an optional column, add it to the table
                            if (oColConfig.visible === true) {
                                oColumn = oColsOptional[oColConfig.column_name];
                                if (oColumn !== undefined) {
                                    oColumn.setVisible(true);
                                    aColumnsNew.push(oColumn); 
                                    bOrderChanged = true;
                                }   
                            }  
                        });

                        // TODO what if the column was part of the table, but is not in the config?
                        // e.g. the UID column, that is always added automatically. It seems to be
                        // added at the end, so we handle it here. But that does not feel good!
                        // UPDATE: Doesnt seem to work??
                        if (oUidCol !== undefined) {
                            aColumnsNew.push(oUidCol); 
                        }

                        if (bOrderChanged === true) {
                            oTable.removeAllColumns();
                            aColumnsNew.forEach(oColumn => {
                                oTable.addColumn(oColumn);
                            });
                        }

JS;
        } else {
            return <<<JS
                        // Responsive table
                        var aColsConfig = {$this->getConfiguratorElement()->buildJsP13nColumnConfig()};
                        var oTable = sap.ui.getCore().byId('{$this->getId()}');
                        var aColumns = oTable.getColumns();
                        var aColumnsNew = [];
                        var oController = {$this->getController()->buildJsControllerGetter($this)};
                        {$colsOptionalJs}

                        var bOrderChanged = false;

                        // add dirty column first
                        var oDirtyColumn = aColumns.find(col => col.getId() === "{$this->getDirtyFlagAlias()}");
                        if (oDirtyColumn) {
                            aColumnsNew.push(oDirtyColumn);
                        }

                        aColsConfig.forEach(function(oColConfig, iConfIdx) {
                            // table columns
                            aColumns.forEach(function(oColumn, iColIdx) {
                                if (oColumn.getId() === oColConfig.column_id) {
                                    if (oColumn.getVisible() !== oColConfig.visible) {
                                        oColumn.setVisible(oColConfig.visible);
                                    }
                                    aColumnsNew.push(oColumn);                                    
                                    return;
                                }  
                            });
                            // optional columns
                            if (oColConfig.visible === true && oColsOptional !== null) {
                                var oColumn = oColsOptional[oColConfig.column_name];
                                if (oColumn !== undefined) {
                                    oColumn.setVisible(true);
                                    aColumnsNew.push(oColumn); 
                                    return;
                                }   
                            }
                        });

                        // compare cols if re-order is needed
                        var aOldOrderIds = aColumns.filter(col => col.getVisible()).map(col => col.getId());
                        var aNewOrderIds = aColumnsNew.map(col => col.getId());

                        if (JSON.stringify(aOldOrderIds) !== JSON.stringify(aNewOrderIds)) {
                            bOrderChanged = true;
                        }

                        //if order/content changed, apply new columns and rebuild template
                        if (bOrderChanged) {
                            var oTemplate = oTable.getBindingInfo("items").template;
                            var aOldCells = oTemplate.getCells();
                            var aNewCells = [];

                            // map data by column id for re-ordering
                            var mColumnIdToCell = {};
                            aColumns.forEach((col, idx) => {
                                var colId = col.getId();
                                if (aOldCells[idx]) {
                                    mColumnIdToCell[colId] = aOldCells[idx];
                                }
                            });

                            // remove all columns
                            oTable.removeAllColumns();

                            // re-add columns in new order and update their cells
                            aColumnsNew.forEach((col, idx) => {
                                oTable.addColumn(col);

                                var colId = col.getId();
                                if (mColumnIdToCell[colId]) {
                                    // if is existing column, reuse cell from old template
                                    aNewCells.push(mColumnIdToCell[colId]);
                                } 
                                else {
                                    // if is new/optional column, bind data to col name from config
                                    var oColConfig = aColsConfig.find(c => c.column_id === colId);
                                    var sProperty = oColConfig.column_name;
                                    
                                    aNewCells.push(new sap.m.Text({
                                        text: '{' + sProperty + '}'
                                    }));
                                }
                            });

                            //update template
                            oTemplate.removeAllAggregation("cells");
                            aNewCells.forEach(cell => oTemplate.addCell(cell));
                        }

JS;
        }
    }

    /**
     * 
     * @return string
     */
    protected function buildJsCellConditionalDisablers() : string
    {
        foreach ($this->getWidget()->getColumns() as $col) {
            if ($conditionalProperty = $col->getCellWidget()->getDisabledIf()) {
                // TODO how to implement on-true/false widget functions here?
                foreach ($conditionalProperty->getConditions() as $condition) {
                    $leftExpressionIsRef = $condition->getValueLeftExpression()->isReference();
                    $rightExpressionIsRef = $condition->getValueRightExpression()->isReference();
                    if ($leftExpressionIsRef === true || $rightExpressionIsRef === true) {
                        $cellWidget = $col->getCellWidget();
                        $cellControlJs = 'oCellCtrl';
                        $cellElement =  $this->getFacade()->getElement($cellWidget);
                        $disablerJS = $cellElement->buildJsSetDisabled(true);
                        $disablerJS = str_replace("sap.ui.getCore().byId('{$cellElement->getId()}')", $cellControlJs, $disablerJS);
                        $enablerJS = $cellElement->buildJsSetDisabled(false);
                        $enablerJS = str_replace("sap.ui.getCore().byId('{$cellElement->getId()}')", $cellControlJs, $enablerJS);
                        $conditionalPropertyJs = $this->buildJsConditionalProperty($conditionalProperty, $disablerJS, $enablerJS);
                        
                        $selfRefOnTheLeft = ($leftExpressionIsRef && $this->getFacade()->getElement($condition->getValueLeftExpression()->getWidgetLink($cellWidget)->getTargetWidget()) === $this);
                        $selfRefOnTheRight = ($rightExpressionIsRef && $this->getFacade()->getElement($condition->getValueRightExpression()->getWidgetLink($cellWidget)->getTargetWidget()) === $this);
                        
                        if ($this->isUiTable() === true) {
                            return $this->buildJsCellConditionalDisablerForUiTable($col, $cellControlJs, $conditionalPropertyJs, ($selfRefOnTheLeft || $selfRefOnTheRight));
                        } elseif ($this->isMTable() === true) {
                            return $this->buildJsCellConditionalDisablerForMTable($col, $cellControlJs, $conditionalPropertyJs, ($selfRefOnTheLeft || $selfRefOnTheRight));
                        }
                    }
                }
            }
        }
        
        return '';
    }
    
    /**
     * Performs the $conditionalLogicJs for every current row and makes sure the cell control is available via $cellControlJs.
     * 
     * While iterating, every row is selected for a fraction of a second to make sure, that if
     * the conditional logic includes a call to the value-getter of the table itself, that getter
     * will return the value of processed row. This makes it possible to disable cell widget
     * depending on the value of other cells of the same row. E.g.:
     * 
     * ```
        {
          "widget_type": "DataTable",
          "object_alias": "exface.Core.ATTRIBUTE",
          "id": "tabelle",
          "filters": [
            {
              "attribute_alias": "OBJECT"
            }
          ],
          "columns": [
            {
              "attribute_alias": "NAME",
              "editable": false
            },
            {
              "attribute_alias": "RELATED_OBJ__LABEL",
              "editable": false
            },
            {
              "attribute_alias": "DELETE_WITH_RELATED_OBJECT",
              "cell_widget": {
                "widget_type": "InputCheckBox",
                "disabled_if": {
                  "operator": "AND",
                  "conditions": [
                    {
                      "value_left": "=tabelle!RELATED_OBJ__LABEL",
                      "comparator": "==",
                      "value_right": ""
                    }
                  ]
                }
              }
            }
          ]
        }
     * ```
     * 
     * TODO will this cause on-change-events to fire for every row selection???
     * 
     * @param DataColumn $col
     * @param string $cellControlJs
     * @param string $conditionalLogicJs
     * @param bool $logicDependsOnTable
     * @return string
     */
    protected function buildJsCellConditionalDisablerForMTable(DataColumn $col, string $cellControlJs, string $conditionalLogicJs, bool $logicDependsOnTable = false) : string
    {
        $colName = $col->getDataColumnName();
        
        // If the logic depends on the table itself, select the current row before executing it
        // and unselect it afterwards. Restore the selection after going through all rows
        if ($logicDependsOnTable === true) {
            $saveSelectionJs = 'var oldSelection = tbl.getSelectedItems(); tbl.removeSelections();';
            $conditionalLogicJs = <<<JS

            tbl.setSelectedItem(r);
            {$conditionalLogicJs}
            tbl.setSelectedItem(r, false);
JS;
            $restoreSelectionJs = <<<JS

    if (Array.isArray(oldSelection) && oldSelection.length > 0) {
        for (var i = 0; i < oldSelection.length; i++) {
            tbl.setSelectedItem(oldSelection[i]);
        }
    }
JS;
        } else {
            $saveSelectionJs = '';
            $restoreSelectionJs = '';
        }
        
        return <<<JS
        
setTimeout(function(){
    var tbl = sap.ui.getCore().byId('{$this->getId()}');
    {$saveSelectionJs}
    var iColIdx = 1;
    if (tbl.getMode() == sap.m.ListMode.MultiSelect) {
        iColIdx++;
    }
    tbl.getColumns().some(function(oColumn){
        if (oColumn.data('_exfDataColumnName') === '$colName') {
            return true; // stop iterating! .some() stops if a callback returns TRUE.
        }
        if (oColumn.getVisible() === true) {
            iColIdx++;
        }
    });
    
    tbl.getItems().forEach(function(r) {
        var cb = r.$().children('td').eq(iColIdx).children().first();
        var {$cellControlJs} = sap.ui.getCore().byId(cb.attr('id'));
        if ({$cellControlJs} != undefined) {
            {$conditionalLogicJs}
        }
    });
    {$restoreSelectionJs}
},0);

JS;
    }
    
    /**
     * @see buildJsCellConditionalDisablerForMTable()
     * @param DataColumn $col
     * @param string $cellControlJs
     * @param string $conditionalLogicJs
     * @param bool $logicDependsOnTable
     * @return string
     */
    protected function buildJsCellConditionalDisablerForUiTable(DataColumn $col, string $cellControlJs, string $conditionalLogicJs, bool $logicDependsOnTable = false) : string
    {
        $colName = $col->getDataColumnName();
        
        // If the logic depends on the table itself, select the current row before executing it
        // and unselect it afterwards. Restore the selection after going through all rows
        if ($logicDependsOnTable === true) {
            $saveSelectionJs = 'var oldSelection = tbl.getSelectedIndices().slice(); tbl.clearSelection();';
            $conditionalLogicJs = <<<JS
            
            tbl.addSelectionInterval(r.getIndex(), r.getIndex());
            {$conditionalLogicJs}
JS;
            $clearSelectionJs = 'tbl.clearSelection();';
            $restoreSelectionJs = <<<JS
            
    if (Array.isArray(oldSelection) && oldSelection.length > 0) {
        for (var i = 0; i < oldSelection.length; i++) {
            tbl.addSelectionInterval(oldSelection[i], oldSelection[i]);
        }
    }
JS;
        } else {
            $saveSelectionJs = '';
            $restoreSelectionJs = '';
            $clearSelectionJs = '';
        }
        
        $conditionalPropertiesJs = <<<JS
        
(function() {
    var tbl = sap.ui.getCore().byId('{$this->getId()}');
    {$saveSelectionJs}
    var iColIdx = 0;
    tbl.getColumns().some(function(oColumn){
        if (oColumn.data('_exfDataColumnName') === '$colName') {
            return true;
        }
        if (oColumn.getVisible() === true) {
            iColIdx++;
        }
    });
    tbl.getRows().forEach(function(r) {
        var cb = r.$().find('.sapUiTableCellInner').eq(iColIdx).children().first();
        var {$cellControlJs} = sap.ui.getCore().byId(cb.attr('id'));
        if ({$cellControlJs} != undefined) {
            {$conditionalLogicJs}
        }
        {$clearSelectionJs}
    });
    {$restoreSelectionJs}
})();

JS;
            
        $this->getController()->addOnEventScript($this, self::EVENT_NAME_FIRST_VISIBLE_ROW_CHANGED, $conditionalPropertiesJs);
        return $conditionalPropertiesJs;
    }
    
    /**
     * Builds the javascript to select all rows with the same value in the DataColumn as the selected row
     * 
     * @param DataColumn $column
     * @return string
     */
    protected function buildJsMultiSelectSync(DataColumn $column) : string
    {
        $widget = $this->getWidget();
        $syncDataColumnName = $column->getDataColumnName();
        if ($this->isMList() === true) {
            
            return <<<JS
            
                var oTable = sap.ui.getCore().byId('{$this->getId()}');
                if (oTable.getModel()._syncChanges === undefined) {
                    oTable.getModel()._syncChanges = false;
                }
                var selected = false;
                var selectedItems = [];
                if (oEvent !== undefined) {
                    selected = oEvent.getParameters().selected;
                    selectedItems = oEvent.getParameter("listItems");
                }
                
                if (oTable.getModel()._syncChanges === false && oEvent !== undefined && selectedItems.length !== 0) {
                    oTable.getModel()._syncChanges = true;
                    var itemValues = selectedItems[0].getBindingContext().getObject();
                    var value = itemValues['{$syncDataColumnName}'];
                    if (value !== undefined) {
                        var aData = oTable.getModel().getData().rows;
                        for (var i in aData) {
                            if (value === aData[i]['{$syncDataColumnName}']) {
                                var index = parseInt(i);
                                var oItem = oTable.getItems()[index];
                                oTable.setSelectedItem(oItem, selected);
                            }
                        }                        
                        var exfSelection = {$this->buildJsGetRowsSelected('oTable')};
                        oTable.data('exfPreviousSelection', exfSelection);
                    } else {
                        var error = "Data Column '{$syncDataColumnName}' not found in data columns for widget '{$widget->getId()}'!";
                        {$this->buildJsShowMessageError('error', '"ERROR"')}
                    }
                
                    oTable.getModel()._syncChanges = false;
                }
                
JS;
        } else {
            
            return <<<JS
            
                var oTable = sap.ui.getCore().byId('{$this->getId()}');
                if (oTable.getModel()._syncChanges === undefined) {
                    oTable.getModel()._syncChanges = false;
                }
                var rowIdx = -1;
                if (oEvent !== undefined) {
                    rowIdx = oEvent.getParameters().rowIndex;
                }
                var selectedRowsIdx = [];
                selectedRowsIdx = oTable.getSelectedIndices();
                var selected = false; 
                if (selectedRowsIdx.includes(rowIdx)) {
                    selected = true;    
                }
                
                if (oTable.getModel()._syncChanges === false && oEvent !== undefined) {
                    oTable.getModel()._syncChanges = true;
                    var rowValues = oEvent.getParameters().rowContext.getObject();
                    var value = rowValues['{$syncDataColumnName}'];
                    if (value !== undefined) {
                            var aData = oTable.getModel().getData().rows;
                            for (var i in aData) {
                                if (value === aData[i]['{$syncDataColumnName}']) {
                                    var index = parseInt(i);
                                    if (selected === true) {
                                        oTable.addSelectionInterval(index, index);
                                    } else {
                                        oTable.removeSelectionInterval(index, index);
                                    }
                                }
                            }
                    } else {
                        var error = "Data Column '{$syncDataColumnName}' not found in data columns for widget '{$widget->getId()}'!";
                        {$this->buildJsShowMessageError('error', '"ERROR"')}
                    }

                    oTable.getModel()._syncChanges = false;
                }
                
JS;
        }
    }
    
    public function needsContainerHeight() : bool
    {
        return $this->isWrappedInDynamicPage() || $this->isUiTable();
    }
    
    protected function buildJsColumnStylers() : string
    {
        $js = '';
        foreach ($this->getWidget()->getColumns() as $col) {
            $js .= StringDataType::replacePlaceholders(($col->getCellStylerScript() ?? ''), ['table_id' => $this->getId()]);
        }
        return $js;
    }
    
    /**
     * 
     * @see UI5DataElementTrait::buildJsDataResetter()
     */
    protected function buildJsDataResetter() : string
    {
        $resetTableJs = '';
        if ($this->isUiTable()) {
            $resetTableJs = ! $this->isUiTable() ? '' : <<<JS

            if (sap.ui.getCore().byId('{$this->getId()}').getEnableGrouping() === true) {
                sap.ui.getCore().byId('{$this->getId()}').setEnableGrouping(false);
            }   
JS;
        } elseif ($this->isMTable()) {
            /* TODO clear selectios or not??? Not sure, why we removed this, but it caused trouble
            $resetTableJs = <<<JS

            //sap.ui.getCore().byId('{$this->getId()}').removeSelections();
JS;*/
        }
        return $resetTableJs . $this->buildJsDataResetterViaTrait();
    }
    
    protected function buildJsFixRowHeight(string $oTableJs) : string
    {
        if ($this->hasFixedRowHeight() === true) {
            return '';
        }
        
        return <<<JS

                    var jqTable = $('#{$this->getId()}');
                    var iRowCntOrig = jqTable.data('_exfMinRows');
                    var iHeaderHeight = jqTable.find('.sapUiTableHeaderRow').height() - 1;
                    var iRowHeightMax = iHeaderHeight;
                    var fnCalcRowHeight = function() {
                        var iNewVisibleRowCount;
                        var iRowCntCur = $oTableJs.getMinAutoRowCount();
                        // On first run, just remember the curent min row count
                        // On subsequent runs, check if min row count was decreased. If so, restore
                        // it, wait for rerender and repeat the optimization
                        if (iRowCntOrig === undefined) {
                            iRowCntOrig = iRowCntCur;
                            jqTable.data('_exfMinRows', iRowCntOrig);
                        } else if (iRowCntCur < iRowCntOrig) {
                            $oTableJs.setMinAutoRowCount(iRowCntOrig);
                            setTimeout(fnCalcRowHeight, 0);
                            return;
                        }
                        // Find the maximum height of immediate children of table cells
                        iRowHeightMax = Math.max.apply(null, jqTable.find('.sapUiTableRow > td > *').map(
                                function () {
                                    return $(this).height();
                                }
                            ).get()
                        );
                        // If the maximum height is greater, than the default height, increase row
                        // row height and decrease the minimum number of rows shown
                        if (iRowHeightMax > iHeaderHeight) {
                            iNewVisibleRowCount = Math.round(iRowCntOrig / (iRowHeightMax / iHeaderHeight));
                            $oTableJs
                                .setColumnHeaderHeight(iHeaderHeight)
                                .setMinAutoRowCount(iNewVisibleRowCount)
                                .setRowHeight(iRowHeightMax);
                        }
                    };
                    $oTableJs.setRowHeight(0);
                    fnCalcRowHeight();
JS;
    }
    
    protected function hasFixedRowHeight() : bool
    {
        foreach ($this->getWidget()->getColumns() as $col) {
            switch (true) {
                case $col->isHidden() === true:
                    continue 2;
                case $col->getCellWidget()->getHeight()->isUndefined() === false:
                    continue 2;
                case $col->getNowrap() === false:
                    return false;
            }
        }
        return true;
    }
}