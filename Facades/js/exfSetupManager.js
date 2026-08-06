;(function (global, factory) {
  typeof exports === 'object' && typeof module !== 'undefined' ? module.exports = factory() :
      typeof define === 'function' && define.amd ? define(factory()) :
          global.exfSetupManager = factory()
}(this, (function () { 'use strict';

  var exfSetupManager = {

    // suffix for the id of the quickselect dropdown button
    // should be appended to the unique parent widget id (e.g. datatable id)
    _quickSelectButtonSuffix: '_setupQuickselectBtn',

    /**
     * Dexie IndexedDB configuration for widget setups database
     * 
     * The primary key matches the identity of a setup in the metamodel: slug + widget_id + object_id
     */
    _dexieDbConfig: {
        name: 'exf-ui5-widgets',
        version: 3,
        stores: {
            setups: '[slug+widget_id+object_id], setup_uid, date_last_applied'
        }
    },

    /**
     * Opens the setups database applying all schema versions
     * 
     * Note: IndexedDB cannot change the primary key of an existing store, so the store of version 1 is
     * deleted in version 2 and recreated with the new key in version 3. Old setups are cleared on that upgrade.
     * 
     * @returns {Dexie} Dexie instance with the current schema
     */
    _oOpenSetupsDb: function() {
        const oSetupsDb = new Dexie(exfSetupManager._dexieDbConfig.name);
        oSetupsDb.version(1).stores({
            setups: '[page_id+widget_id], setup_uid, date_last_applied'
        });
        oSetupsDb.version(2).stores({
            setups: null
        });
        oSetupsDb.version(exfSetupManager._dexieDbConfig.version).stores(exfSetupManager._dexieDbConfig.stores);
        return oSetupsDb;
    },

    /**
     * Checks whether Dexie (IndexedDb wrapper) is available in the current context
     * 
     * @returns boolean 
     */
    _bIsDexieAvailable: function() {
        if (typeof Dexie === 'undefined'){
            console.warn('Dexie.js not available, cannot manage setups in IndexedDB.');
            return false;
        }
        return true;
    },

    /**
     * Function to set the data property that tracks unsaved changes attached to the configured widget 
     * 
     * @param {string} sWidgetId id of configured widget 
     * @param {boolean} bValue true/false whether the configuration has changed
     */
    _setConfigChangedProperty: function(sWidgetId, bValue) {
        let oWidget = sap.ui.getCore().byId(sWidgetId); 
        if (oWidget){
            oWidget.data('_exfConfigChanged', bValue);
        }
    },

    /**
     * Initializes or resets the model for the quick select menu button 
     * @param {string} sButtonParentId Id of the parent element/widget of the button (e.g. id of the datatable)
     */
    _initializeQuickSelectButtonModel: function(sButtonParentId) {
        let oButton = sap.ui.getCore().byId(sButtonParentId + exfSetupManager._quickSelectButtonSuffix);
        if (oButton){
            let oButtonModel = new sap.ui.model.json.JSONModel({
                buttonCaption: null,
                configChanged: false 
            });
            oButton.setModel(oButtonModel);
        }
    },

    /**
     * onChange function that is called when the widget configuration changes:
     *      - Sets the change tracking data property on the widget to true 
     *      - updates the quick select button indicator (*)
     * 
     * For an example, see @trackConfigChanges
     * 
     * @param {string} sWidgetId id of the configured widget (for example a UI5DataTable)
     */
    _onConfigChange: function(sWidgetId) {
        let oWidget = sap.ui.getCore().byId(sWidgetId);
        let oButton = sap.ui.getCore().byId(sWidgetId + exfSetupManager._quickSelectButtonSuffix);

        if (oWidget){
            exfSetupManager._setConfigChangedProperty(sWidgetId, true);
        }
        if (oButton && oButton.getModel()){
            oButton.getModel().setProperty("/configChanged", true);
        }
    },

    /**
     * Returns the suffix for the id of the quickselect dropdown button. 
     * Base of the Id is the unique parent widget id. (e.g. datatable id)
     * 
     * @returns (string) suffix for the id of the quickselect dropdown button
     */
    getQuickSelectButtonSuffix: function() {
        return exfSetupManager._quickSelectButtonSuffix;
    },

    /**
     * Fires a custom event on a ui5 component, which can be used to trigger UI updates etc. 
     * (See event listener for appliedWidgetSetup in UI5DataElementTrait)
     * Event should be fired when a widget setup is applied (manually or automatically)
     * 
     * @param {string} sFullWidgetId Full Id of the affected widget
     */
    fireWidgetSetupChangedEvent: function(sFullWidgetId) {
        let oWidget = sap.ui.getCore().byId(sFullWidgetId);
        if (oWidget){
            oWidget.fireEvent('appliedWidgetSetup');
        }
    },

    /**
     * Resets the change tracking (is the current configuration different from the applied setup or initial state?)
     * for a given widget and its associated quick select button.
     * 
     * @param {string} sWidgetId id of the current UI5DataTable
     */
    resetChangeTracking: function(sWidgetId) {
        exfSetupManager._setConfigChangedProperty(sWidgetId, false);
        exfSetupManager._initializeQuickSelectButtonModel(sWidgetId);
    },

    /**
     * Attaches an event listener that marks the currently applied setup as active (with checkmark icon) in the setups table at runtime.
     * If the setup was applied manually, the table model is refreshed once to trigger the event listener.
     * 
     * @param {string} sSetupsTableId Id of the table that lists the setups
     * @param {string} sSlug slug of the current screen (part of the IndexedDb pk)
     * @param {string} sWidgetId current widget id (part of the IndexedDb pk)
     * @param {string} sObjectId UID of the meta object of the widget (part of the IndexedDb pk)
     * @param {boolean} bIsManuallyApplied whether the setup was applied manually or onLoad/form local storage (default: false)
     * @returns 
     */
    markCurrentSetupAsActive: function(sSetupsTableId, sSlug, sWidgetId, sObjectId, bIsManuallyApplied = false) {

        // get the ui5 datatable that lists the setups
        let oSetupTable = sap.ui.getCore().byId(sSetupsTableId);
        if (oSetupTable == undefined){
            return;
        }
        
        // attach event listener to that table, to mark the currently applied setup as active (with checkmark icon)
        if (!oSetupTable.data("_exf_fnSetAsDefaultAttached")){
            // the setups table seems to refresh on every re-open so its not enough to set in once,
            // so it needs to be some sort of event listener that re-sets it on re-open/update
            let fnSetAsDefault = function(oEvent) {
                // retrieve the currently applied setup uid from indexedDb/session storage
                exfSetupManager.getSetupProperty(sSlug, sWidgetId, sObjectId, null, 'setup_uid')
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
        let oModel = oSetupTable.getModel();
        if (bIsManuallyApplied === true){
            oModel.refresh(true);
        }
    },

    /**
     * Updates the caption of the setup quickselect button to the currently applied setup name.
     * (The caption name is saved in the model of the button, to ensure consistency. If the ) 
     * 
     * TODO/FIXME: technically, widgetId and buttonParentId could be the same? But with the way we store the setups, 
     * we only take the short ID (getDataWidget->getId()), not with the facade prefix. And to get the button, we need the full widget ID to find it in the DOM.
     * 
     * @param {string} sSlug slug of the current screen
     * @param {string} sWidgetId Id of the current widget
     * @param {string} sObjectId UID of the meta object of the widget
     * @param {string} sButtonParentId Id of the parent of the quickselect button (to find the button in the ui)
     */
    updateQuickSelectButtonCaption: function(sSlug, sWidgetId, sObjectId, sButtonParentId) {
        // get the currently applied setup name from indexedDb
        // and update the quickselect button caption accordingly
        exfSetupManager.getSetupProperty(sSlug, sWidgetId, sObjectId, null, 'setup_name')
        .then(sSetupName => {
            if (sSetupName !== null){
                let oButton = sap.ui.getCore().byId(sButtonParentId + exfSetupManager._quickSelectButtonSuffix);
                if (oButton){
                    // update the caption of the quickselect btn if it exists
                    oButton.getModel().setProperty("/buttonCaption", sSetupName); 
                }
            }
        });
    },

    /**
     * Helper function that returns a passed value as a resolved promise (immediately) or retrieves a specified key from the widget_setup IndexedDB entry
     * This is needed because the indexedDB calls are asynchronous, so we need to work with promises either way.
     * 
     * Example: in apply_setup, we either need to use the passed setupUxon fron the input data (if we press the applySetup button) or we need 
     * to retrieve it from indexedDb (when auto-applying steups onLoad). In both cases we need to work with a promise, because the apply_setup logic uses .then() etc.
     * 
     * @param {string} sSlug the slug of the current screen (part of the IndexedDb pk)
     * @param {string} sWidgetId the widget identifier, e.g. 'myTable' (part of the IndexedDb pk)
     * @param {string} sObjectId the UID of the meta object of the widget (part of the IndexedDb pk)
     * @param {string|null} sPassedData if a value is passed, it will be returned immediately as a resolved promise
     * @param {string|null} sKey the key of the value to retrieve from indexedDb, e.g. 'setup_uxon' or 'setup_uid'
     * @returns {Promise<string|null>} a promise that resolves to the requested setup data or null if not found
     */
    getSetupProperty: function(sSlug, sWidgetId, sObjectId, sPassedData = null, sKey = null) {
        // If data is passed in function, return it immediately as a resolved promise
        if (sPassedData !== null) {
            return Promise.resolve(sPassedData);
        }

        // get entry from indexed db and return the requested key
        return exfSetupManager.dexie.getCurrentSetup(sSlug, sWidgetId, sObjectId)
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
        });
    },

    // Indexed DB calls for the widgetsetup db (get, save, delete)
    dexie: {

        /**
         * Function that returns the current setup entry for a given page and widget from the IndexedDB, if it exists.
         * can be checked/used with .then(entry => ...) to access the values
         *  
         * Also used in @UI5DataConfigurator.php, to automatically apply the last used setup on widget load.
         * 
         * @param {string} sSlug slug of the current screen
         * @param {string} sWidgetId id of the current widget
         * @param {string} sObjectId UID of the meta object of the widget
         * @returns Promise resolving to the current setup entry from IndexedDB, if it exists
         */
        getCurrentSetup: function(sSlug, sWidgetId, sObjectId) {

            // if dexie is not available for some reason, return null
            if (! exfSetupManager._bIsDexieAvailable()){
                return Promise.resolve(null);
            }

            // open indexedDb connection 
            const oSetupsDb = exfSetupManager._oOpenSetupsDb();

            // check if setup exists for slug+widget+object pk and return the entry
            return oSetupsDb.setups.get([sSlug, sWidgetId, sObjectId])
            .catch(err => {
                console.error('Error reading from IndexedDB:', err);
            })
            .finally(() => {
                oSetupsDb.close();
            });
        },

        /**
         * Deletes the current setup entry for a given page and widget from the IndexedDB, if it exists. 
         * Does not do anything if no entry exists.
         * 
         * @param {string} sSlug slug of the current screen
         * @param {string} sWidgetId id of the current widget
         * @param {string} sObjectId UID of the meta object of the widget
         * @returns Promise that resolves when the deletion is complete
         */
        deleteCurrentSetup: function(sSlug, sWidgetId, sObjectId) {

            // if dexie is not available for some reason, return null
            if (! exfSetupManager._bIsDexieAvailable()){
                return Promise.resolve(null);
            }

            // open indexedDb connection 
            const oSetupsDb = exfSetupManager._oOpenSetupsDb();

            // delete entry if it exists
            return oSetupsDb.setups.delete([sSlug, sWidgetId, sObjectId])
            .catch(err => {
                console.error('Error deleting entry from IndexedDB:', err);
            })
            .finally(() => {
                oSetupsDb.close();
            });
        },

        /**
         * Saves or updates a passed setup as the last applied setup for a given page and widget in the IndexedDB. 
         * 
         * @param {string} sSlug slug of the current screen (part of dexie pk)
         * @param {string} sWidgetId current widget id (part of dexie pk)
         * @param {string} sObjectId UID of the meta object of the widget (part of dexie pk)
         * @param {string} sSetupUid current widget setup uid (used to identify the applied setup/adding checkmark in setups table)
         * @param {string} sSetupUxon current widget setup uxon
         * @param {string} sSetupName current widget setup name
         */
        saveLastAppliedSetup: function(sSlug, sWidgetId, sObjectId, sSetupUid, sSetupUxon, sSetupName) { 
            
            // setup entry to store in dexie.
            // combination of slug, widget id and object id as primary key for db entry
            let oSetupObj = {
                slug: sSlug,
                widget_id: sWidgetId,
                object_id: sObjectId,
                setup_uid: sSetupUid,
                date_last_applied: new Date().toISOString(),
                setup_uxon: sSetupUxon,
                setup_name: sSetupName
            };

            // exit if dexie not available
            if (! exfSetupManager._bIsDexieAvailable()){
                return;
            }

            // open indexedDb connection
            const oSetupsDb = exfSetupManager._oOpenSetupsDb();

            // Save setup in db, then close connection 
            oSetupsDb.setups.put(
                oSetupObj
            ).catch(err => {
                console.error('Error accessing IndexedDb:', err);
            }).finally(() => {
                oSetupsDb.close();
            });
        }
    },

    // UI5 DataTable specific functions (saving and applying configuration, tracking changes)
    datatable: {

        /**
         * Re-calculate the fixedColumnCount property for a sap.ui.table. This might differ from the inital count due to 
         * setups, mutations, and optional columns, as well as changes made to the configuration;
         * 
         * 
         * @param {array} aColumns columns property of widget
         * @param {integer} iFreezeColumnCount how many columns shoudl be frozen
         * @param {boolean} bHasDirtyColumn 
         * @returns {integer} fixedColumnCount
         */
        getFreezeColumnsCount: function (aColumns, iFreezeColumnCount, bHasDirtyColumn) {
            if (iFreezeColumnCount > 0){
                if (Array.isArray(aColumns)) {
                    for (let i = 0; i < iFreezeColumnCount; i++) {
                        if (aColumns[i] && aColumns[i].visible === false) {
                            iFreezeColumnCount++;
                        }
                    }
                }

                if (bHasDirtyColumn === true){
                    iFreezeColumnCount++;
                }
            }

            return iFreezeColumnCount;
        },

        /**
         * Attaches an event listener to the /columns property, to update the number of frozen columns if the configuration changes
         * @param {string} sP13nId Id of the configurator
         * @param {string} sP13nModelName name of the p13n model
         * @param {string} sDataTableId id of the DataTable
         * @param {integer} iFreezeColumnCount how many columns should be frozen in UI
         * @param {string} bHasDirtyColumn does the table have a dirty column?
         * @returns 
         */
        attachFrozenColumnChangeListener: function (sP13nId, sP13nModelName, sDataTableId, iFreezeColumnCount, bHasDirtyColumn) {
            let oDialog = sap.ui.getCore().byId(sP13nId); 
            if (oDialog == undefined){
                return;
            }
            let oP13nModel = oDialog.getModel(sP13nModelName);
            let oDataTable = sap.ui.getCore().byId(sDataTableId); 

            // updates the frozen columns count according to the configuration and attaches a listener for auto-updating the count when the configuration changes.
            // resets trigger this re-calculation via UI5DataTable::FUNCTION_RESET_CHANGE_TRACKING
            if (oP13nModel && oDataTable){
                // update count according to current state
                oDataTable.setFixedColumnCount(exfSetupManager.datatable.getFreezeColumnsCount(oP13nModel.getProperty("/columns"), iFreezeColumnCount, bHasDirtyColumn));
                
                // attach listener that auto-updates the frozen column count when a new configuration is applied
                // this fires when the user manually clicks 'ok' in the p13n dialogue, or when a setup is applied
                if (oDataTable.data('_exf_autoUpdateFrozenCols') !== true){
                    oDialog.attachOk(() => {
                        oDataTable.setFixedColumnCount(exfSetupManager.datatable.getFreezeColumnsCount(oP13nModel.getProperty("/columns"), iFreezeColumnCount, bHasDirtyColumn));
                    });
                    oDataTable.data('_exf_autoUpdateFrozenCols', true);
                }
            }
        },

        /**
         * Tracks changes made to the DataTable configuration, including columns, sorters, filters, and manual resizes.
         * This is done by attaching event listeners to the relevant UI5 components and models and updating a change flag and indicator.
         * (see @_onDataTableConfigChange for onchange event function)
         * 
         * Can be reset usinng @resetDataTableChangeTracking
         * 
         * TODO: This is now implemented quick and easy, by attaching a bunch of event listeners;
         * But it could be refactored to track changes more meaningfully. (changing/reverting etc.) This could be done 
         * by comparing the currenty state (json stringify maybe?) of configuration to the applied setup or initial state.
         * 
         * @param {string} sDataTableId ID of current UI5DataTable
         * @param {string} sP13nId ID of the personalization dialog
         * @param {string} sP13nModelName Name of the personalization model
         * @param {string} sP13nSearchPanelId Id of the personalization filter panel
         */
        trackConfigChanges: function(sDataTableId, sP13nId, sP13nModelName, sP13nSearchPanelId) {
            // in order to only attach the event listeners once per table instance we check for a flag 
            // _exf_fnTrackSetupChangesAttached
            let oDataTable = sap.ui.getCore().byId(sDataTableId); 
            if (oDataTable && !oDataTable.data("_exf_fnTrackSetupChangesAttached")){
                
                // initilize change tracking property on data table
                // and the quick select button model
                // chnages are tracked here as well, to display a change indicator (*) next to the button caption 
                exfSetupManager._setConfigChangedProperty(sDataTableId, false);
                exfSetupManager._initializeQuickSelectButtonModel(sDataTableId);

                // Get the P13n model and the filter panel
                // NOTE: we need the filter panel separately, as its not part of the p13n json model, 
                // but is being attached to the server requests as params at runtime
                let oDialog = sap.ui.getCore().byId(sP13nId); 
                if (oDialog == undefined){
                    return;
                }
                let oP13nModel = oDialog.getModel(sP13nModelName);
                let oFilterPanel = sap.ui.getCore().byId(sP13nSearchPanelId);

                // attach event listeners for filter, columns, sorters, manual resizes
                // (see @_OnConfigChange for the onchange function)
                if (oP13nModel){
                    oP13nModel.bindProperty("/columns").attachChange(() => {
                        exfSetupManager._onConfigChange(sDataTableId);
                    });
                    oP13nModel.bindProperty("/sorters").attachChange(() => {
                        exfSetupManager._onConfigChange(sDataTableId);
                    });
                }
                if (oFilterPanel) {
                    oFilterPanel.attachEvent("addFilterItem", (oEvent) => {
                        exfSetupManager._onConfigChange(sDataTableId); 
                    });
                    oFilterPanel.attachEvent("updateFilterItem", (oEvent) => {
                        exfSetupManager._onConfigChange(sDataTableId);
                    });
                    oFilterPanel.attachEvent("removeFilterItem", (oEvent) => {
                        exfSetupManager._onConfigChange(sDataTableId);
                    });
                }
                oDataTable.attachEvent("columnResize", (oEvent) => {
                    if (oDataTable.data("_exfIsAutoResizing")) {
                        return; // ignore automatic resizes
                    }
                    exfSetupManager._onConfigChange(sDataTableId); 
                });

                // update flag that listners are attached to table instance
                oDataTable.data("_exf_fnTrackSetupChangesAttached", true);
            }
        },

        /**
         * Collects and returns the current configuration of a ui5 data table (columns, advanced search, sorters) in JSON format.
         *  - Columns: column_name, show, custom_width (if manually resized)
         *  - Advanced Search: attribute_alias, comparator, value, exclude
         *  - Sorters: attribute_alias, direction
         * 
         * Example: 
         * 
         * {
         *  "columns": 
         *      [    
         *          { "column_name": "COL1", "show": true, "custom_width": "150px" },
         *      ],
         *  "advanced_search": 
         *      [ 
         *          { "attribute_alias": "ATTR1", "comparator": "Contains", "value": "Test", "exclude": false },
         *      ],
         * "sorters": 
         *      [
         *          { "attribute_alias": "ATTR2", "direction": "Ascending" },
         *      ]
         * }
         * 
         * @param {string} sDataTableId id of the current UI5DataTable
         * @param {string} sP13nId id of the personalization dialog
         * @param {string} sP13nModelName name of the personalization model
         * @param {string} sP13nSearchPanelId name of the personalization filter panel (advanced search)
         * @returns JSON object in widget_setup format representing the current configuration of the data table (columns, advanced_search, sorters)
         */
        getConfiguration : function(sDataTableId, sP13nId, sP13nModelName, sP13nSearchPanelId) {

            // json object to save current configuration state in
            let oSetupJson = {
                columns: [],
                advanced_search: [],
                sorters: [],
                header_filters: []
            };

            // get the current states
            // NOTE: we need the filter panel separately, as its not part of the p13n json model (oP13nModel), 
            // but is being attached to the server requests as params at runtime
            let oDialog = sap.ui.getCore().byId(sP13nId); 
            let oP13nModel = oDialog.getModel(sP13nModelName); 
            let aColumns = oP13nModel.getProperty('/columns');
            let aSorters = oP13nModel.getProperty('/sorters');
            let aHeaderFilters = oP13nModel.getProperty('/header_filters');
            let aFilters = sap.ui.getCore().byId(sP13nSearchPanelId).getFilterItems();

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
            let oTable = sap.ui.getCore().byId(sDataTableId); 
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

            // save header filters (these are saved in the model by the UI5DataConfigurator in the DataGetter)
            if (aHeaderFilters !== undefined && Object.keys(aHeaderFilters).length > 0) {
                let aConditions = [];

                // collect top-level conditions
                if (aHeaderFilters.conditions) {
                    aHeaderFilters.conditions.forEach(function(oCondition) {
                        if (oCondition.hidden === false){
                            // skip hidden filters
                            aConditions.push({
                                expression: oCondition.expression,
                                comparator: oCondition.comparator,
                                value: oCondition.value
                            });
                        }
                    });
                }

                // collect conditions from nested groups (just put the entire group)
                if (aHeaderFilters.nested_groups) {
                    aHeaderFilters.nested_groups.forEach(function(oGroup) {
                        // skip hidden filters
                        if (oGroup.hidden === false){
                            let mVal = null;
                            if (oGroup.conditions) {
                                mVal = oGroup.conditions[0].value; // just take the value of the first condition
                            }
                            delete oGroup.hidden; // otherwise comparing the uxon to apply the filters doesnt work
                            aConditions.push({
                                nested: true,
                                group: oGroup,
                                value: mVal
                            });
                        }
                    });
                }

                if (aConditions.length > 0) {
                    oSetupJson.header_filters = aConditions;
                }
            }

            return oSetupJson;
        },

        /**
         * Applies a given configuration/widget_setup JSON to a ui5 data table.
         * 
         * @param {string} sDataTableId Id of the ui5 DataTable
         * @param {string} sP13nModelName Name of the configuration model
         * @param {string} sP13nColumnsPanelId Id of the columns panel in the p13n dialogue
         * @param {string} sP13nSortPanelId Id of the sorters panel in the p13n dialogue
         * @param {string} sP13nSearchPanelId Id of the advanced search panel in the p13n dialogue
         * @param {object} oSetupJson JSON object in widget_setup format containing the configuration to apply
         */
        applyConfiguration : function(sDataTableId, sP13nModelName, sP13nColumnsPanelId, sP13nSortPanelId, sP13nSearchPanelId, oSetupJson) {
            
            // setup configuration
            let oSetupUxon = oSetupJson;
            if (!oSetupUxon) {
                return;
            }

            // Apply setup
            if (oSetupUxon.columns !== undefined){
                // COLUMN SETUP
                let oDialog = sap.ui.getCore().byId(sP13nColumnsPanelId);
                let oModel = oDialog.getModel(sP13nModelName);
                let oInitModel = oDialog.getModel(sP13nModelName+'_initial');
                let aInitCols = JSON.parse(JSON.stringify(oInitModel.getData()['columns'])); // deep copy to avoid reference issues
                let aColumnSetup = oSetupUxon.columns;
                let oDataTable = sap.ui.getCore().byId(sDataTableId); 

                // reset current custom width properties of the table columns
                // only do this for ui.table
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
                // for this, we use the update function attached to the panel, see Ui5DataConfigurator
                let fnUpdateColumnsPanel = oDialog && oDialog.data('_exfTabColumnsUpdate');
                if (typeof fnUpdateColumnsPanel === 'function') {
                    fnUpdateColumnsPanel.call(oDialog, false);
                }

            }
            if (oSetupUxon.sorters !== undefined) {
                // SORTER SETUP
                let oDialog = sap.ui.getCore().byId(sP13nSortPanelId);
                let oModel = oDialog.getModel(sP13nModelName);
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
                let oDialog = sap.ui.getCore().byId(sP13nSearchPanelId);
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

            // reset header filters when switching setups
            let fnResetHeaderFilters = sap.ui.getCore().byId(sDataTableId).data('fnResetVisibleHeaderFilters');
            if (fnResetHeaderFilters) {
                fnResetHeaderFilters();
            }
            
            if (oSetupUxon.header_filters !== undefined) {
                // HEADER FILTER SETUP (see Ui5DataConfigurator->buildJsFilterValueSetter)

                // setter function is stored in DataTable 
                // (otherwise it leads to namespace issues when tyring to pass it via the CallWidgetFunction, because we save the setup calling from a showDialogue action)
                let fnSetHeaderFilters = sap.ui.getCore().byId(sDataTableId).data('fnSetVisibleHeaderFilters');
                if (fnSetHeaderFilters) {
                    // values to be set
                    let aValuesJs = oSetupUxon.header_filters;
                    if (Array.isArray(aValuesJs)) {
                        aValuesJs = aValuesJs.filter(function(oCondition) {
                            // Skip conditions with empty or null values
                            return oCondition && oCondition.value !== '' && oCondition.value !== null && oCondition.value !== undefined;
                        });
                    }

                    try {
                        fnSetHeaderFilters(aValuesJs);
                    } catch (e) {
                        console.error("An error occurred while setting header filters:", e);
                    }
                }
            }
        }
    }
  }
  return exfSetupManager;
}))); 