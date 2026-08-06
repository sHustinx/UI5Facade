<?php
namespace exface\UI5Facade\Facades\Elements\Traits;

use exface\Core\DataTypes\AutoloadStrategyDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\DataTypes\TextDataType;
use exface\Core\Exceptions\Widgets\WidgetFunctionArgumentError;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iCanBeRequired;
use exface\Core\Interfaces\Widgets\iCanEditData;
use exface\Core\Interfaces\Widgets\iHaveSidebar;
use exface\Core\Interfaces\Widgets\iSupportMultiSelect;
use exface\Core\Widgets\Data;
use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataTable;
use exface\Core\Widgets\DataTableConfigurator;
use exface\Core\Widgets\Tab;
use exface\UI5Facade\Facades\Elements\UI5DataTable;
use exface\UI5Facade\Facades\Elements\UI5Button;
use exface\UI5Facade\Facades\Elements\UI5Sidebar;
use exface\UI5Facade\Facades\Interfaces\UI5ControllerInterface;
use exface\UI5Facade\Facades\Elements\UI5AbstractElement;
use exface\UI5Facade\Facades\Elements\UI5DataConfigurator;
use exface\UI5Facade\Facades\Elements\UI5SearchField;
use exface\Core\Widgets\Input;
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Widgets\Dialog;
use exface\Core\DataTypes\WidgetVisibilityDataType;
use exface\UI5Facade\Facades\Elements\UI5DataPaginator;
use exface\Core\Widgets\Button;
use exface\Core\Widgets\MenuButton;
use exface\Core\Widgets\ButtonGroup;
use exface\Core\Exceptions\Facades\FacadeLogicError;
use exface\Core\Exceptions\Widgets\WidgetConfigurationError;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\Actions\iReadData;
use exface\UI5Facade\Facades\Elements\ServerAdapters\UI5FacadeServerAdapter;
use exface\UI5Facade\Facades\Elements\ServerAdapters\OfflineServerAdapter;
use exface\Core\Facades\AbstractAjaxFacade\Interfaces\AjaxFacadeElementInterface;
use exface\Core\Widgets\DataButton;
use exface\Core\Interfaces\Widgets\iHaveQuickSearch;
use exface\Core\Exceptions\Facades\FacadeRuntimeError;
use exface\Core\Widgets\DataLookupDialog;

/**
 * This trait helps wrap thrid-party data widgets (like charts, image galleries, etc.) in 
 * UI5 panels with standard toolbars, a configurator dialog, etc. 
 * 
 * ## How it works:
 * 
 * The method buildJsConstructor() is pre-implemented and takes care of creating the report floorplan,
 * toolbars, the P13n-Dialog, etc. The control to be placed with the report floorplan is provided by the
 * method buildJsConstructorForControl(), which nees to be implemented in every class using the trait.
 * 
 * ### Default data loader
 * 
 * The trait also provides a default data loader implementation via buildJsDataLoader(), which supports
 * different server adapter (i.e. to switch to direct OData communication in exported Fiori apps), lazy 
 * loading and data preload out of the box. 
 * 
 * It is definitely a good idea to use this data loader in all data controls like tables, lists, etc.! 
 * 
 * The data loaded is automatically placed in the main model of the control (use 
 * `sap.ui.getCore().byId({$this->getId()}).getModel()` to access it). However, you can still customize 
 * the data loading logic by implementing 
 * 
 * - `buildJsDataLoaderPrepare()` - called right before the default loading starts.
 * - `buildJsDataLoaderParams()` - called after the default request parameters were computed and allowing
 * to customize them
 * - `buildJsDataLoaderOnLoaded()` - called right after the data was placed in the model, but before
 * the busy-state is dismissed. This is the place, where you would add all sorts of postprocessing or
 * the logic to load the data into a non-UI5 control.
 * 
 * **NOTE:** The main model of the control and it's page wrapper (if the report floorplan is used) is 
 * NOT the view model - it's a separate one. It contains the data set loaded. 
 * 
 * ### Wrapping the control in sap.f.DynamicPage
 * 
 * The trait will automatically wrap the data control in a sap.f.DynamicPage turning it into a "report
 * floorplan" if the method `isWrappedInDynamicPage()` returns `true`. Override this method to implement
 * a custom wrapping condition.
 * 
 * The page will have a collapsible header with filters (instead of the filter tab in the widget 
 * configurator (see below). The behavior of the dynamic page can be customized via
 * 
 * - `getDynamicPageXXX()` methods - override them to change the page's behavior from the element class
 * - `setDynamicPageXXX()` methods - call them from other classes to set available options externally 
 * 
 * ### Toolbars
 * 
 * The trait provides methods to generate the standard toolbar with the widget's `caption`, buttons,
 * quick search field and the configurator-button.
 * 
 * You can also customize the toolbars by overriding
 * - `buildJsToolbar()` - returns the constructor of the top toolbar (sap.m.OverflowToolbar by default)
 * - `buildJsToolbarContent()` - returns the toolbar content (i.e. title, buttons, etc.)
 * - `buildJsQuickSearchConstructor()`
 * 
 * ### Configurator: filters, sorters, advanced search query builder, etc.
 * 
 * There is also a secondary
 * model for the configurator (i.e. filter values, sorting options, etc.) - it's name can be obtained
 * from `getModelNameForConfigurator()`.
 * 
 * ### Editable columns and tracking changes
 * 
 * The trait will automatically track changes for editable columns if the built-in data loader is used 
 * (see above). All changes are strored in a separate model (see. `getModelNameForChanges()`). The trait 
 * provides `buildJsEditableChangesXXX()` methods to use in your JS. If the data widget has 
 * `editable_changes_reset_on_refresh` set to `false`, the trait will automatically restore changes
 * after every refresh.
 * 
 * @author Andrej Kabachnik
 *
 * @method UI5Facade getFacade()
 */
trait UI5DataElementTrait {
    
    use UI5HelpButtonTrait;
    use UI5TourGuideTrait;
    use UI5SidebarTrait;
    
    private $quickSearchElement = null;
    
    private $dynamicPageHeaderCollapsed = null;
    
    private $dynamicPageShowToolbar = false;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::init()
     */
    protected function init()
    {
        $dataWidget = $this->getDataWidget();
        $configuratorElement = $this->getConfiguratorElement();
        $configuratorLinked = $dataWidget->isConfiguratorLinked();
        
        // Tweak this widget a little if it uses a shared configurator
        if ($configuratorLinked) {
            $this->getWidget()->setHideHeader(true);
            $dataWidget->setConfiguratorSetupsEnabled(false);
        }
        
        if ($this->isWrappedInDynamicPage() && ! $configuratorLinked) {
            $configuratorElement->setIncludeFilterTab(false);
        }
        
        // Make sure to call the default init() AFTER the configurator is set up because the
        // init might need the configurator for all sorts of live refs.
        parent::init();
        
        // Manually create an element for the quick search input, because we need a sap.m.SearchField
        // instead of regular input elements.
        if ($this->hasQuickSearch()) {
            $qsWidget = $this->getWidget()->getQuickSearchWidget();
            // TODO need to add support for autosuggest. How can we use InputCombo or InputComboTable widget here?
            if ($qsWidget instanceof Input) {
                $this->quickSearchElement = new UI5SearchField($qsWidget, $this->getFacade());
                $this->getFacade()->registerElement($this->quickSearchElement);
                $this->quickSearchElement
                    ->setPlaceholder($this->getWidget()->getQuickSearchPlaceholder());
            } else {
                $this->quickSearchElement = $this->getFacade()->getElement($this->getWidget()->getQuickSearchWidget());
            }
        }
        
        // Handle `refresh_with_widget`: add an on-refresh listener to the target widget
        if (null !== $refreshLink = $dataWidget->getRefreshWithWidget()) {
            $refreshEl = $this->getFacade()->getElement($refreshLink->getTargetWidget());
            $refreshEl->addOnRefreshScript("setTimeout(function(){ {$this->buildJsRefresh()} }, 0);");
        }
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsConstructor()
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {
        $widget = $this->getWidget();
        $controller = $this->getController();
        
        $autoloadDataStrategy = $this->getDataWidget()->getAutoloadDataStrategy();
        $autoloadJs = '';
        
        $this->registerExternalModules($this->getController());
        
        // Add placeholders for the custom events here. If not done so, at least the select-event will be
        // added too late and won't be there in the generated controller.
        $this->getController()->addOnEventScript($this, 'select', '');
        $this->getController()->addOnEventScript($this, UI5AbstractElement::EVENT_NAME_REFRESH, '');

        // if the widget has the setups quickselect enabled, we attach an listener to the 'appliedWidgetSetup' event
        // this gets fired whenever a setup is applied (see @exfSetupManager.js for event), 
        // we can use this to update the quickselect button caption and the change indicator (*)
        if ($this->hasSetupsQuickSelector()){
            $updateQuickselectUIJs = <<<JS
                (function (){ 

                    // attach listener only once per widget
                    let oWidget = sap.ui.getCore().byId('{$this->getId()}');
                    if (oWidget && oWidget.data('_exfWidgetSetupChangeListenerAttached') !== true){ 
                        
                        // Update UI Elements onchange of widget setup 
                        oWidget.attachEvent('appliedWidgetSetup', function (oEvent) {
                            exfSetupManager.markCurrentSetupAsActive('{$this->getP13nElement()->getSetupsTableId()}', '{$this->getDataWidget()->findUiContainer()->getSlug()}' , '{$this->getDataWidget()->getIdWithinUiContainer()}', '{$this->getDataWidget()->getMetaObject()->getId()}', true);
                            exfSetupManager.updateQuickSelectButtonCaption('{$this->getDataWidget()->findUiContainer()->getSlug()}' , '{$this->getDataWidget()->getIdWithinUiContainer()}', '{$this->getDataWidget()->getMetaObject()->getId()}', '{$this->getId()}');
                            oWidget.data('_exfWidgetSetupChangeListenerAttached', true);
                        });
                    }
                })();
JS;

            $this->getController()->addOnInitScript($updateQuickselectUIJs);
        }
        
        $controller->addMethod('onUpdateFilterSummary', $this, '', $this->buildJsFilterSummaryUpdater());
        $controller->addMethod('onLoadData', $this, 'oControlEvent, bKeepPagingPos', $this->buildJsDataLoader());
        $this->initConfiguratorControl($controller, $oControllerJs);
        
        if ($this->hasPaginator()) {
            $this->getPaginatorElement()->registerControllerMethods();
        }

        // Data reload logic.
        // Look inside the buildAutoloadJsForAutoloadStrategy() function for more documentation.
        $strategy = AutoloadStrategyDataType::NEVER;

        if ($autoloadDataStrategy === AutoloadStrategyDataType::ALWAYS) {
            $strategy = AutoloadStrategyDataType::ALWAYS;
        } else if ($autoloadDataStrategy === AutoloadStrategyDataType::IF_VISIBLE) {
            // NOTE: The Data that is marked as "IF_VISIBLE" should be loaded
            // at special events like onSelect in corresponding widget facades.
            // You can implement it inside the "buildJsDataLoader()" function in this trait.

            // special logic for tabs treatment.
            if (null !== $tab = $this->findParentTab()) {

                /* @var $tabEl \exface\UI5Facade\Facades\Elements\UI5Tab */
                $tabEl = $this->getFacade()->getElement($tab);
                
                // If the tab is active or is an ObjectPageSection then we load data always
                $strategy = $tab->isActive() || $tabEl->isObjectPageSection()
                    ? AutoloadStrategyDataType::ALWAYS
                    : AutoloadStrategyDataType::IF_VISIBLE;
            }
        }
        $autoloadJs .= $this->buildAutoloadJsForAutoloadStrategy($strategy);
        $controller->addOnShowViewScript($autoloadJs);
        
        // add trigger to refresh data automatically when widget has autorefresh_intervall set.
        if ($widget->hasAutorefreshIntervall()) {
            //add a UI5 IntervalTrigger for every data element
            if (! $controller->hasProperty("autoRefreshTrigger_{$this->getId()}")) {
                $controller->addProperty("autoRefreshTrigger_{$this->getId()}", 'new sap.ui.core.IntervalTrigger(0)');
            }
            //multiplicate value with 1000 as intervall in UI5 is set in milliseconds
            $intervall = $widget->getAutorefreshIntervall() * 1000;
            //add listeners on init
            $controller->addOnInitScript(<<<JS
                var oController = {$controller->buildJsControllerGetter($this)};
                oController.autoRefreshTrigger_{$this->getId()}.addListener(function(){{$this->buildJsRefresh()}});
JS);
            //activate trigger by setting interval on show view
            $controller->addOnShowViewScript(<<<JS
                var oController = {$controller->buildJsControllerGetter($this)};
                oController.autoRefreshTrigger_{$this->getId()}.setInterval({$intervall});
JS);
            //deactivate trigger by setting interval to 0 on hide view
            $controller->addOnHideViewScript(<<<JS
                var oController = {$controller->buildJsControllerGetter($this)};
                oController.autoRefreshTrigger_{$this->getId()}.setInterval(0);
JS);
        }
        
        // Generate the constructor for the inner widget
        $js = $this->buildJsConstructorForControl();
        
        
        $initModels = <<<JS

        .setModel(new sap.ui.model.json.JSONModel())
        .setModel(new sap.ui.model.json.JSONModel(), '{$this->getModelNameForConfigurator()}')
        .setModel(new sap.ui.model.json.JSONModel({rows: []}), '{$this->getModelNameForSelections()}')
JS;
        
        // If the table has editable columns, we need to track changes made by the user.
        // This is done by listening to changes of the /rows property of the model and
        // comparing it's current state with the initial state. This IF initializes
        // the whole thing. The rest ist handlede by buildJsEditableChangesWatcherXXX()
        // methods.
        if ($this->isEditable()) {
            $initModels .= <<<JS

        .setModel(new sap.ui.model.json.JSONModel(), '{$this->getModelNameForDataLastLoaded()}')
        .setModel(new sap.ui.model.json.JSONModel({changes: {}, watching: false}), '{$this->getModelNameForChanges()}')
JS;
            $controller->addMethod('updateChangesModel', $this, 'oDataChanged', $this->buildJsEditableChangesWatcherUpdateMethod('oDataChanged'));
            $bindChangeWatcherJs = <<<JS

            (function(oTable){
                var oModel = oTable.getModel();
                var oRowsBinding = new sap.ui.model.Binding(oModel, '/rows', oModel.getContext('/rows'));
                oRowsBinding.attachChange(function(oEvent){
                    var oBinding = oEvent.getSource();
                    var oDataChanged = oBinding.getModel().getData();
                    {$controller->buildJsMethodCallFromController('updateChangesModel', $this, 'oDataChanged')};
                });
            })(sap.ui.getCore().byId('{$this->getId()}'));
JS;
            $controller->addOnInitScript($bindChangeWatcherJs);
        }
        
        if ($this->isWrappedInDynamicPage()){
            $js = $this->buildJsPage($js, $oControllerJs) . $initModels . $this->buildJsAddCssWidgetClasses();
        } else {
            $js = $js . $initModels . $this->buildJsAddCssWidgetClasses();
        }
        
        if ($this->getWidget() instanceof iHaveSidebar && $this->getWidget()->hasSidebar()) {
            $sidebarEl = $this->getFacade()->getElement($this->getWidget()->getSidebar());
            if ($sidebarEl instanceof UI5Sidebar) {
                $js = $sidebarEl->buildJsConstructorForDynamicSideContent($js, $oControllerJs);
            }
        }
        
        $controller->addOnInitScript($this->buildJsWindowTourContent());
        
        return $js;
    }

    /**
     * Returns autoloadJs for given AutoloadStrategy that is used in buildJsConstructor
     * 
     * @param $strategy
     * @return string
     */
    protected function buildAutoloadJsForAutoloadStrategy($strategy) : string {
        $js = '';
        
        switch ($strategy) {
            case AutoloadStrategyDataType::ALWAYS:
                // Reload the data every time the view is shown. This is important, because otherwise 
                // old rows may still be visible if a dialog is open, closed and then reopened for another 
                // instance.
                // The data resetter will empty the table as soon as the view is opened, then the refresher
                // is run after all the view loading logic finished - that's what the setTimeout() is for -
                // otherwise the refresh would run before the view finished initializing, before the prefill
                // is started and will probably be empty.
                // Finally, all selections are removed to ensure child elements of one instance are not
                // automatically selected when opening another one.
                $js .= <<<JS

                    (function() {
                      // 'isBack' is triggered when the user navigates back to the page. 
                      //  in this case, we do not refresh the data.
                      var bIsBack = !!(oEvent?.isBack || oEvent?.isBackToPage || oEvent?.isBackToTop);
                      if (!bIsBack) {
                            try { 
                                {$this->buildJsDataResetter()} 
                                {$this->buildJsSelectionModelReset()}
                            } catch (e) {} 
                            setTimeout(function(){ 
                                {$this->buildJsRefresh()} 
                            }, 0);
                        }
                    })();
JS;
                break;
            case AutoloadStrategyDataType::IF_VISIBLE:
                // No data refresh.
                // The AutoloadStrategy "NEVER" logic is used if the page is not displayed due to back navigation.
                // Data that has already been loaded is not flushed away in the event of back navigation here.
                
                // NOTE: The Data that is marked as "IF_VISIBLE" can be loaded
                // inside the "buildJsDataLoader()" function in this trait.
                $dataWidget = $this->getDataWidget();
                $js .= <<<JS
                
                    (function() {
                        var bIsBack = !!(oEvent?.isBack || oEvent?.isBackToPage || oEvent?.isBackToTop);
                        if (!bIsBack) {
                            {$this->buildJsSelectionModelReset()}
                            {$this->buildJsShowMessageOverlay($dataWidget->getAutoloadDisabledHint())}
                        }
                    })();
JS;
                break;
            case AutoloadStrategyDataType::NEVER:
                // No data refresh. Shows the hint that autoload is disabled.
                $dataWidget = $this->getDataWidget();
                $js .= <<<JS

                    {$this->buildJsSelectionModelReset()}
                    {$this->buildJsShowMessageOverlay($dataWidget->getAutoloadDisabledHint())}
JS;
                break;
        }
        return $js;
    }
    
    /**
     * Returns the constructor for the inner data control (e.g. table, chart, etc.)
     * 
     * @param string $oControllerJs
     * @return string
     */
    abstract protected function buildJsConstructorForControl($oControllerJs = 'oController') : string;
    
    /**
     * Wraps the given content in a sap.m.Panel with data-specific toolbars (configurator button, etc.).
     * 
     * This is usefull for third-party widget libraries, that need this wrapper to look like UI5 controls.
     * 
     * @param string $contentConstructorsJs
     * @param string $oControllerJs
     * @param string $caption
     * 
     * @return string
     */
    protected function buildJsPanelWrapper(string $contentConstructorsJs, string $oControllerJs = 'oController', string $toolbar = null, bool $padding = true)  : string
    {
        $toolbar = $toolbar ?? $this->buildJsToolbar($oControllerJs);
        $hDim = $this->getWidget()->getHeight();
        if (! $hDim->isUndefined()) {
            $height = $this->getHeight();
        } else {
            $height = $this->buildCssHeightDefaultValue();
        }
        
        $panelCssClass = $padding === false ? 'sapUiNoContentPadding' : '';
        if ($this->isFillingContainer()) {
            $panelCssClass .= ' exf-panel-no-border';
        }
        return <<<JS

        new sap.m.Panel("{$this->getId()}_panel", {
            height: "$height",
            headerToolbar: [
                {$toolbar}
            ],
            content: [
                {$contentConstructorsJs}
            ]
        })
        .addStyleClass('{$panelCssClass}')        
JS;
    }
    
    protected function isFillingContainer() : bool
    {
        $widget = $this->getWidget();
        return $widget->hasParent() && $widget->getParent()->countWidgetsVisible() === 1;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsSetHidden()
     */
    protected function buildJsSetHidden(bool $hidden, string $elementId = null) : string
    {
        if ($this->isWrappedInPanel()) {
            $elementId = $elementId ?? $this->getId() . '_panel';
        } else {
            $elementId = $elementId ?? $this->getId();
        }
        return parent::buildJsSetHidden($hidden, $elementId);
    }
    
    /**
     * Returns the constructor for the table's main toolbar (OverflowToolbar).
     *
     * The toolbar contains the caption, all the action buttons, the quick search
     * and the button for the personalization dialog as well as the P13nDialog itself.
     *
     * The P13nDialog is appended to the toolbar wrapped in an invisible container in
     * order not to affect the overflow behavior. The dialog must be included in the
     * toolbar to ensure it is destroyed with the toolbar and does not become an
     * orphan (e.g. when the view containing the table is destroyed).
     * 
     * @param string $oControllerJsVar
     * @param string $leftExtras
     * @param string $rightExtras
     *
     * @return string
     */
    protected function buildJsToolbar($oControllerJsVar = 'oController', string $leftExtras = null, string $rightExtras = null)
    {
        $visible = $this->hasToolbarTop() ? 'true' : 'false';
        
        // Remove bottom line of the toolbar if it is to be integrated into the dynamic page header
        if ($this->getDynamicPageShowToolbar() === true) {
            $style = 'style: "Clear",';
        }
        
        return <<<JS

			new sap.m.OverflowToolbar({
                design: "Transparent",
                {$style}
                visible: {$visible},
				content: [
					{$this->buildJsToolbarContent($oControllerJsVar, $leftExtras, $rightExtras)}
				]
			}).addStyleClass('exf-datatoolbar')

JS;
    }
    
    /**
     * 
     * @return bool
     */
    public function hasToolbarTop() : bool
    {
        return ! ($this->getWidget()->getHideHeader() === true && $this->getWidget()->getHideCaption());
    }

    /**
     * @return bool
     */
    protected function hasSetupsQuickSelector() : bool
    {
        if ($this->getDataWidget()->getConfiguratorSetupsEnabled() === false) {
            return false;
        }
        $configWidget = $this->getWidget()->getConfiguratorWidget();
        return ($configWidget instanceof DataTableConfigurator) 
            && $configWidget->hasSetups() 
            && ($this instanceof UI5DataTable) // FIXME Remove this restriction once the setups have been completely moved to this trait
            && $this->getFacade()->getConfig()->getOption('WIDGET.DATA.SETUPS.QUICK_SELECT_ENABLED');
    }

    /**
      * Builds a UI5 quick select menu with widget setups for the datatable.
      * Menu uses the same model as (so is dependant on) the table containing the setups in the p13n dialogue for consistency
      * if the quickselect is opened before the setup configurator, it will load the data of the configurator table
      *
      * @param string $buttonCaption optional button caption for the quickselect menu (if no caption is passed, the button will be an arrow icon)
      * @return string
      */
    protected function buildJsSetupQuickSelectMenu(string $buttonCaption = null) : string
    {
        // this menu depends on the configurator and the setups tab
        // TODO setups currently only work for facade elements based on UI5DataTable because lots of their
        // code is in there. We should probably extract that code to a separate JS lib and move some of it
        // to the UI5DataElementTrait
        if (! $this->hasSetupsQuickSelector()){
            return '';
        }
        
        /* @var $configuratorWidget \exface\Core\Widgets\DataTableConfigurator */
        $configuratorWidget = $this->getP13nElement()->getWidget();
        $setupsTable = $configuratorWidget->getSetupsTab()->getWidgetFirst();
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();

        // default captions
        $tableCaption = $this->escapeString('');
        $popoverTitle = $this->escapeString($translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_CAPTION'));
        
        // if a caption was passed, use it as the caption of the quickselect button
        if ($buttonCaption !== null){
            $tableCaption = $this->escapeString($buttonCaption);
            $popoverTitle = $this->escapeString($buttonCaption . ' ' . $translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_CAPTION'));
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
                        tooltip: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_ALL')}",
                        icon: "sap-icon://action-settings",
                        press: function() {
                			{$this->getController()->buildJsDependentControlSelector('oConfigurator', $this, 'oController')}.open();
                		}
                    })
JS;

        // button to save a new setup
        $saveSetupBtnEl = $this->getFacade()->getElement($configuratorWidget->getButtonToSaveSetup());
        $saveSetupBtnJs = <<<JS
            new sap.m.Button({
                text: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_SAVE')}",
                tooltip: "{$translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_SAVE')}",
                type: sap.m.ButtonType.Transparent,
                press: function() {
                    // TODO: fixme - add a getter for the save button in configurator widget to avoid id issues here
                    // issue in some nested dialogues:
                    // generated: x11eeaef721a6f716aef7005056bef75d__SplitHorizontal_SplitPanel_DataTable_DataToolbar_ButtonGroup_DataButton04_SplitHorizontal_SplitPanel_DataTable_DataToolbar_ButtonGroup_DataButton04_Dialog_Tabs_Tab03_DataTable_DataTableConfigurator_saveSetupBtn
                    // expected: x11eeaef721a6f716aef7005056bef75d__SplitHorizontal_SplitPanel_DataTable_DataToolbar_ButtonGroup_DataButton04_Dialog_Tabs_Tab03_DataTable_DataTableConfigurator"+'_saveSetupBtn'

                    let oSaveSetupBtn = sap.ui.getCore().byId('{$saveSetupBtnEl->getId()}');
                    if (oSaveSetupBtn){
                        oSaveSetupBtn.firePress();
                    }
                }
            })
JS;

        // button to reset configuration
        // TODO sah: in the future, this would probably be nicer with a default entry in table
        $resetBtnJs = <<<JS
            new sap.m.Button({
                text: "{$translator->translate('ACTION.RESETWIDGET.NAME')}",
                tooltip: "{$translator->translate('ACTION.RESETWIDGET.NAME')}",
                type: sap.m.ButtonType.Transparent,
                press: function() {
                    let oResetConfigBtn = sap.ui.getCore().byId('{$this->getP13nElement()->getId()}'+'-reset');
                    if (oResetConfigBtn){
                        oResetConfigBtn.firePress();
                    }
                }
            })
JS;

        return <<<JS
                    new sap.m.Button({
                        id: '{$this->getId()}' + exfSetupManager.getQuickSelectButtonSuffix(),
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
                                            {$saveSetupBtnJs},
                                            {$resetBtnJs},
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
     * @param string $oControllerJsVar
     * @param string $leftExtras
     * @param string $rightExtras
     * @return string
     */
    protected function buildJsToolbarContent($oControllerJsVar = 'oController', string $leftExtras = null, string $rightExtras = null) : string
    {   
        $widget = $this->getWidget();
        $heading = $this->isWrappedInDynamicPage() || $widget->getHideCaption() === true ? '' : 'new sap.m.Label({text: ' . $this->escapeString($this->getCaption()) . '}),';
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator();

        // if we have a datatable with widget_setups, we need to set the heading empty
        // and show a quickselect menu instead of the normal heading
        if ($this->hasSetupsQuickSelector()) {
            // Don't show plain text caption if there is the setups dropdown
            $heading = '';

            // Caption of Popover Button
            // default: show a fallback text as button caption (e.g. 'default-view')
            //    -> if the table has a visible caption, set that as the caption of the button
            //    -> if a setup is applied, the caption will be the name of the setup (in any case) see UI5DataTable->apply_setup() 
            $popoverBtnCaption = $translator->translate('WIDGET.DATACONFIGURATOR.SETUPS_TAB_DEFAULT_CAPTION');
            if ($this->isWrappedInDynamicPage() === false && $widget->getHideCaption() !== true){
                $popoverBtnCaption = $this->getCaption();
            }
            
            // add quickselect menu to the left extras 
            $leftExtras = $this->buildJsSetupQuickSelectMenu($popoverBtnCaption) . $leftExtras;
        }
        
        $leftExtras = $leftExtras === null ? '' : rtrim($leftExtras, ", ") . ',';
        $rightExtras = $rightExtras === null ? '' : rtrim($rightExtras, ", ") . ',';
        
        if ($this->getDynamicPageShowToolbar() === false) {
            $quickSearch = $this->buildJsQuickSearchConstructor() . ',';
        } else {
            $quickSearch = '';
        }
        
        return <<<JS

                    {$this->buildJsToolbarSelectionCounter()}
                    {$heading}
                    {$leftExtras}
			        new sap.m.ToolbarSpacer(),
                    {$this->buildJsButtonsConstructors()}
                    {$rightExtras}
                    {$quickSearch}
                    {$this->buildJsFullscreenButtonConstructor()}
					{$this->buildJsConfiguratorButtonConstructor()}
                    {$this->buildJsHelpButtonConstructor()}

JS;
    }

    protected function buildJsToolbarSelectionCounter() : string
    {
        $widget = $this->getWidget();
        $dataWidget = $this->getDataWidget();
        if ($dataWidget->getMetaObject()->hasLabelAttribute()) {
            $labelCol = $dataWidget->getColumnByAttributeAlias($dataWidget->getMetaObject()->getLabelAttributeAlias());
        }
        // Find the most suitable columns to show in the little menu with all selected items
        // If the object has a label attribute and there is a column with this attribute, take
        // that column. Otherwise, find the left-most visiblecolumn, that is a regular Display 
        // widget and is a "short" string. 
        // IDEA would it be even better to only take attribute columns with required attributes
        // to make sure, it always has value?
        $firstVisibleCol = null;
        $firstDisplayCol = null;
        if (! $labelCol) {
            foreach ($dataWidget->getColumns() as $col) {
                $cellWidget = $col->getCellWidget();
                if ($firstVisibleCol === null && $col->isHidden !== false) {
                    $firstVisibleCol = $col;
                }
                if ($cellWidget->getWidgetType() !== 'Display') {
                    continue;
                }
                if ($firstDisplayCol === null && $col->isHidden !== false) {
                    $firstDisplayCol = $col;
                }
                $cellDataType = $cellWidget->getValueDataType();
                if ($cellDataType instanceof StringDataType && ! ($cellDataType instanceof TextDataType)) {
                    $labelCol = $col;
                    break;
                }
            }
        }
        if (! $labelCol) {
            $labelCol = $firstDisplayCol ?? $firstVisibleCol;
        }
        $uidColName = $dataWidget->hasUidColumn() ? "'{$dataWidget->getUidColumn()->getDataColumnName()}'" : 'null';

        if (($widget instanceof iSupportMultiSelect) && $widget->getMultiSelect() === true && ! $this->getDynamicPageShowToolbar()) {
            $modelName = $this->getModelNameForSelections();
            $translator = $this->getFacade()->getApp()->getTranslator();
            $minSelectsToShowIndicator = ($widget instanceof DataTable) && $widget->isMultiSelectSavedOnNavigation() ? 1 : 2;
            $js = <<<JS

                    new sap.m.Button({
                        icon: 'sap-icon://complete',
                        type: 'Ghost',
                        tooltip: '{= \${{$modelName}>/rows}.length} {$translator->translate('WIDGET.DATATABLE.SELECTED_ROWS')}',
                        visible: '{= \${{$modelName}>/rows}.length >= {$minSelectsToShowIndicator} ? true : false}',
                        layoutData: new sap.m.OverflowToolbarLayoutData({priority: "NeverOverflow"}),
                        customData: [
                            new sap.m.BadgeCustomData({
                                key: "badge",
                                value: '{= \${{$modelName}>/rows}.length}'
                            })
                        ],
                        press: function(oEvent) {
                            var oBtn = oEvent.getSource();
                            var sPopoverId = '{$this->getId()}_selectionsPopover';
                            var oPopover = sap.ui.getCore().byId(sPopoverId);
                            if (oPopover === undefined) {
                                oPopover = new sap.m.Popover(sPopoverId, {
                                    contentWidth: "16rem",
                                    // place deselect all button in custom header layout next to title
                                    customHeader: new sap.m.Bar({
                                        contentLeft: [
                                            new sap.m.Title({
                                                text: '{= \${{$modelName}>/rows}.length} {$translator->translate('WIDGET.DATATABLE.SELECTED_ROWS')}',
                                                wrapping: false
                                            })
                                        ],
                                        contentRight: [
                                            // clear-all button: deselects every selected row and empties the list
                                            new sap.m.Button({
                                                icon: 'sap-icon://decline',
                                                type: 'Transparent',
                                                tooltip: "{$translator->translate('WIDGET.DATATABLE.SELECTED_CLEAR')}",
                                                press: function(oEvent) {
                                                    // deselect everything (visible) in the table
                                                    var oTable = sap.ui.getCore().byId('{$this->getId()}');
                                                    var oModel = oTable.getModel('{$modelName}');
                                                    oTable.__modifyingSelection = true;
                                                    if (typeof oTable.clearSelection === 'function') {
                                                        // sap.ui.table.Table
                                                        oTable.clearSelection();
                                                    } else if (typeof oTable.removeSelections === 'function') {
                                                        // sap.m.List / sap.m.Table
                                                        oTable.removeSelections(true);
                                                    }
                                                    oTable.__modifyingSelection = false;

                                                    // empty model and force refresh model binding, see comment in the delete-handler below
                                                    oModel.setProperty('/rows', []);
                                                    oModel.refresh(true);

                                                    // Tell everything bound to this widget, that the selection changed
                                                    {$this->getController()->buildJsEventHandler($this, UI5AbstractElement::EVENT_NAME_CHANGE, false)};
                                                    oPopover.close();
                                                }
                                            })
                                        ]
                                    }),
                                    content: [
                                        new sap.m.List({
                                            mode: "Delete",
                                            items: {
                                                path: "{$modelName}>/rows",
                                                template: new sap.m.StandardListItem({
                                                    title: "{{$modelName}>{$labelCol->getDataColumnName()}}",
                                                    type: "Active"
                                                })
                                            },
                                            delete: function(oEvent) {
                                                var oItem = oEvent.getParameters().listItem;
                                                var oList = oPopover.getContent()[0];
                                                var oRowUnselected = oItem.getBindingContext('{$modelName}').getObject();
                                                var iItem = oItem.getParent().indexOfItem(oItem);
                                                var sUidCol = {$uidColName};
                                                var oTable = sap.ui.getCore().byId('{$this->getId()}');
                                                var oModel = oTable.getModel('{$modelName}');
                                                var iTableIdx = exfTools.data.indexOfRow(oTable.getModel().getProperty('/rows'), oRowUnselected, sUidCol);
                                                oModel.getProperty('/rows').splice(iItem, 1);
                                                // Force refresh model binding - otherwise if you select items, paginate to next
                                                // page and remove one of previously selected items, the indicator will not be
                                                // updated
                                                oModel.refresh(true);
                                                if (iTableIdx > -1) {
                                                    {$this->buildJsSelectRowByIndex('oTable', 'iTableIdx', true, 'false')}
                                                }
                                            }
                                        })
                                    ]
                                }).setModel(oBtn.getModel('{$modelName}'), '{$modelName}');
                                {$this->getController()->getView()->buildJsViewGetter($this)}.addDependent(oPopover);
                            }
                            oPopover.openBy(oBtn);
                        }
                    }),
JS;
        } else {
            $js = '';
        }
        return $js;
    }
    
    /**
     * Returns the text to be shown a table title
     *
     * @return string
     */
    public function getCaption() : string
    {
        $widget = $this->getWidget();
        return $widget->getCaption() ? $widget->getCaption() : $widget->getMetaObject()->getName();
    }

    /**
     * 
     * @return bool
     */
    protected function hasActionButtons() : bool
    {
        return $this->getWidget()->hasButtons();
    }
    
    /**
     * Returns a comma separated list of javascript constructors for all buttons of the table.
     *
     * Must end with a comma unless it is an empty string!
     * 
     * @return string
     */
    protected function buildJsButtonsConstructors()
    {
        if ($this->hasActionButtons() === false) {
            return '';
        }
        
        $widget = $this->getWidget();
        $buttons = '';
        foreach ($widget->getToolbars() as $toolbar) {
            if ($toolbar->getIncludeSearchActions()){
                $search_button_group = $toolbar->getButtonGroupForSearchActions();
            } else {
                $search_button_group = null;
            }
            $grps = $widget->getToolbarMain()->getButtonGroups();
            foreach ($grps as $btn_group) {
                if ($btn_group === $search_button_group){
                    continue;
                }
                $buttons .= ($buttons && $btn_group->getVisibility() > EXF_WIDGET_VISIBILITY_OPTIONAL ? ",\n new sap.m.ToolbarSeparator()," : '');
                foreach ($btn_group->getButtons() as $btn) {
                    $buttons .= $this->getFacade()->getElement($btn)->buildJsConstructor() . ",\n";
                }
            }
        }
        return $buttons;
    }
    
    /**
     * Returns the JS constructor for the configurator button.
     * 
     * Must end with a comma unless it is an empty string!
     * 
     * @param string $oControllerJs
     * @return string
     */
    protected function buildJsConfiguratorButtonConstructor(string $oControllerJs = 'oController', string $buttonType = 'Default') : string
    {
        // No button if configurator disabled explicitly
        if (! $this->hasConfigurator()) {
            return '';
        }
        // No button if configurator is shared
        if ($this->getWidget()->isConfiguratorLinked()) {
            return '';
        }
        $btnPriorityJs = $this->getDynamicPageShowToolbar() ? '"AlwaysOverflow"' : '"High"';
        return <<<JS
        
                    new sap.m.OverflowToolbarButton({
                        type: sap.m.ButtonType.{$buttonType},
                        icon: "sap-icon://action-settings",
                        text: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.TITLE')}",
                        tooltip: "{$this->translate('WIDGET.DATATABLE.SETTINGS_DIALOG.TITLE')}",
                        layoutData: new sap.m.OverflowToolbarLayoutData({
                            priority: {$btnPriorityJs}
                        }),
                        press: function() {
                			{$this->getController()->buildJsDependentControlSelector('oConfigurator', $this, $oControllerJs)}.open();
                		}
                    }),
                    
JS;
    }
    
    /**
     * Returns an inline JS snippet to select the jQuery element, that should be maximized.
     * 
     * Technically, the respective DOM element will get the CSS class `fullscreen`. Depending
     * on the DOM structure of a speicifc control, you might need to override this method.
     * 
     * @return string
     */
    protected function buildJsFullscreenContainerGetter() : string
    {
        // if we're in a data lookup dialog, maximize entire dialog
        // (otherwise the toolbar is missing, and you cannot minimize anymore)
        if ($this->getWidget()->getParentByClass(DataLookupDialog::class) !== null){
            return "$('#{$this->getId()}').parents('.sapMDialog').first()";
        }
        // otherwise return normal parent elements
        return $this->isWrappedInDynamicPage() ? "$('#{$this->getId()}').parent()" : "$('#{$this->getId()}').parent().parent()";
    }
    
    /**
     * Returns the JS constructor for the fullscreen button.
     * 
     * Must end with a comma unless it is an empty string!
     * 
     * @param string $oControllerJs
     * @param string $buttonType
     * @return string
     */
    protected function buildJsFullscreenButtonConstructor(string $oControllerJs = 'oController', string $buttonType = 'Default') : string
    {
        $btnPriorityJs = $this->getDynamicPageShowToolbar() ? '"AlwaysOverflow"' : '"High"';
        $id = $this->getId() . '_fullscreenButton';
        
        $script = <<<JS

var jqFullscreenContainer = {$this->buildJsFullscreenContainerGetter()};
var oButton = sap.ui.getCore().getElementById('{$id}');
var jqButton = $('#{$this->getId()}')[0];

if (jqFullscreenContainer.length == 0){
    console.warn('Could not find fullsize container.');
    return;
}

if (jqFullscreenContainer.hasClass('fullscreen') === false) {
    // Enter full-screen
    //set the z-index of the fullscreen dynamically so it works with popovers
    var iZIndex = 0;
    var iMaxZIndex = 0;
    var parent = jqFullscreenContainer.parent();
    jqButton._zIndexDefault = jqFullscreenContainer.css('z-index');
    //get the maximum z-index of parent elements of the data element
    while (parent.length !== 0 && parent[0].tagName !== "BODY") {
        iZIndex = parseInt(parent.css("z-index"));
        if (!isNaN(iZIndex) && iZIndex > iMaxZIndex) {
            iMaxZIndex = iZIndex;
        }    
        parent = parent.parent();
    }

    //check if the currently found maximum z-index is bigger than the z-index of the app header 
    ([
        '.sapUiUfdShellHead',
        '.sapUiUfdShell .sapUiUfdShellBrand'
    ]).forEach(function(sSelector){
        var jqEl = $(sSelector);
        var iZIndex = jqEl.length > 0 ? parseInt(jqEl.css("z-index")) : null;
        if (!isNaN(iZIndex) && iZIndex > iMaxZIndex) {
            iMaxZIndex = iZIndex;
        }    
    });    
    iMaxZIndex = iMaxZIndex + 1;
    jqFullscreenContainer.css('z-index', iMaxZIndex);

    jqButton._originalParent = jqFullscreenContainer.parent();
    jqFullscreenContainer.appendTo($('#sap-ui-static')[0]).addClass('fullscreen');
    oButton.setTooltip("{$this->translate('WIDGET.CHART.FULLSCREEN_MINIMIZE')}");
    oButton.setText("{$this->translate('WIDGET.CHART.FULLSCREEN_MINIMIZE')}");
    oButton.setIcon('sap-icon://exit-full-screen');
} else {
    // Exit full-screen
    jqFullscreenContainer.css('z-index', jqButton._zIndexDefault);
    jqFullscreenContainer.appendTo(jqButton._originalParent).removeClass('fullscreen');
    oButton.setTooltip("{$this->translate('WIDGET.CHART.FULLSCREEN_MAXIMIZE')}");
    oButton.setText("{$this->translate('WIDGET.CHART.FULLSCREEN_MAXIMIZE')}");
    oButton.setIcon('sap-icon://full-screen');
}
setTimeout(function(){
    {$this->getOnResizeScript()}
}, 0);
JS;
        $this->getController()->addOnHideViewScript("if ({$this->buildJsFullscreenContainerGetter()}.hasClass('fullscreen') === true) {{$script}}", true);
        return <<<JS
        
                    new sap.m.OverflowToolbarButton({
                        id: "{$id}",
                        type: sap.m.ButtonType.{$buttonType},
                        icon: "sap-icon://full-screen",
                        text: "{$this->translate('WIDGET.CHART.FULLSCREEN_MAXIMIZE')}",
                        tooltip: "{$this->translate('WIDGET.CHART.FULLSCREEN_MAXIMIZE')}",
                        layoutData: new sap.m.OverflowToolbarLayoutData({
                            priority: {$btnPriorityJs}
                        }),
                        press: function() {
                			{$script}
                		}
                    }),
                    
JS;
    }
    
    /**
     * Initializes the configurator control (sap.m.P13nDialog or similar) and makes it available in the given controller.
     * 
     * Use buildJsConfiguratorOpen() to show the configurator dialog. 
     * 
     * @param UI5ControllerInterface $controller
     * 
     * @return UI5AbstractElement
     */
    protected function initConfiguratorControl(UI5ControllerInterface $controller, string $oControllerJs) : UI5AbstractElement
    {
        // If the table does not have a configurator, there will not be a control for it. Instead, the
        // configurator will work in unrendered mode
        if (! $this->hasConfigurator()) {
            // Make sure the table is refreshed when needed even if there is no configurator button!
            $this->getConfiguratorElement()->registerRefreshListeners($oControllerJs);
            return $this;
        }
        if (! $this->getWidget()->isConfiguratorLinked()) {
            $controller->addDependentControl('oConfigurator', $this, $this->getConfiguratorElement());
        }
        return $this;
    }
    
    /**
     *
     * @return bool
     */
    protected function hasQuickSearch() : bool
    {
        return ($this->getWidget() instanceof iHaveQuickSearch) && $this->getWidget()->getQuickSearchEnabled() !== false;
    }
    
    /**
     * Returns a JS self invoking function, that returns TRUE if all required filters are present and FALSE otherwise.
     * 
     * @return string
     */
    protected function buildJsCheckRequiredFilters() : string
    {
        return <<<JS

(function (){
    try {
        if ({$this->getConfiguratorElement()->buildJsValidator()}) {
            return true;
        } else {
            return false;
        }
    } catch (e) {
        console.warn('Could not check filter validity - ', e);
    }
})()
JS;
    }
    
    /**
     * Empties the table by replacing it's model by an empty object.
     *
     * @return string
     */
    protected function buildJsDataResetter() : string
    {
        $widget = $this->getWidget();
        if (($widget instanceof iCanEditData) && $widget->isEditable() && $widget->getEditableChangesResetOnRefresh()) {            
            $resetEditableTable = $this->buildJsEditableChangesWatcherReset();
        }
        return <<<JS

        ;(function(oTable){
            oTable.getModel().setData({});
            {$resetEditableTable}
                {$this->getController()->buildJsEventHandler($this, UI5AbstractElement::EVENT_NAME_REFRESH, false)}
        })(sap.ui.getCore().byId('{$this->getId()}'));  
JS;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsRefresh()
     */
    public function buildJsRefresh(bool $keepPagingPosition = false)
    {
        if ($keepPagingPosition === false) {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, '');
        } else {
            return $this->getController()->buildJsMethodCallFromController('onLoadData', $this, 'undefined, true');
        }
    }
    
    /**
     * Returns the body of a controller method to fill the widget with data: onLoadDataTableId(oControlEvent).
     * 
     * **IMPORTANT:** The controller metho MUST return a promise that resolves to the JSONModel
     * containing the loaded data. Thus, if you add cancelling logic, don't forget to return
     * a promise - see the `if (oViewModel.getProperty(sPendingPropery) === true)` branch below
     * for an example. 
     *
     * @param string $oControlEventJsVar
     * @param string $keepPagePosJsVar
     * 
     * @return string
     */
    protected function buildJsDataLoader($oControlEventJsVar = 'oControlEvent', $keepPagePosJsVar = 'bKeepPagingPos', $showWarning = true)
    {
        $widget = $this->getWidget();
        $disableEditableChangesWatcher = '';
        $preventDataLoad = '';
        if ($widget instanceof iShowData && $widget->isEditable()) {
            $disableEditableChangesWatcher = <<<JS
                
                // Disable editable-column-change-watcher because reloading from server
                // changes the data but does not mean a change by the editor
                {$this->buildJsEditableChangesWatcherDisable()}
JS;
                
            if ($widget->getEditableChangesResetOnRefresh() && $showWarning) {
                $translator = $this->getWidget()->getWorkbench()->getCoreApp()->getTranslator();
                $preventDataLoad = <<<JS
                    
                    var oChanges = {$this->buildJsEditableChangesGetter()};
                    if (oChanges !== undefined && ! $.isEmptyObject(oChanges)) {
                        var oComponent = this.getOwnerComponent();
                        var oDialog = oComponent.showDialog('{$translator->translate('WIDGET.DATA.DISCARD_INPUT')}', '{$translator->translate('WIDGET.DATA.DISCARD_INPUT.TEXT')}', 'Warning');
                        var oResetButton = new sap.m.Button({
                            icon: 'sap-icon://font-awesome/eraser',
                            type: 'Emphasized',
                            text: "{$translator->translate('WIDGET.DATA.DISCARD_INPUT')}",
                            press: function() {{$this->buildJsDataResetter()} {$this->buildJsRefresh()}; oDialog.close()},
                        });
                        var oCloseButton = new sap.m.Button({
                            icon: 'sap-icon://font-awesome/close',
                            text: "{$translator->translate('WIDGET.DIALOG.CLOSE_BUTTON_CAPTION')}",
                            press: function() {oDialog.close()},
                        });
                        oDialog.addButton(oResetButton);
                        oDialog.addButton(oCloseButton);
                        return;
                    }
JS;
            }
        }
        
        // Adds data load logic to specific widgets in case of `autoload_data: if_visible`.
        if ($this->getDataWidget()->getAutoloadDataStrategy() === AutoloadStrategyDataType::IF_VISIBLE) {
            $this->registerDataLoaderVisibilityCheck();
        }
        
        // Before we load anything, we need to make sure, the view data is loaded.
        // The view model has a special property to indicate if view (prefill) data
        // is being loaded. So we check that property and, if it shows a prefill
        // running right now, we listen for changes on the property. Once it is not
        // set to true anymore, we can do the refresh. The setTimeout() wrapper is
        // needed to make sure all filters bound to the prefill model got their values!
        // NOTE: the setTimeout() in the prefill-change-handler is needed because it seems
        // to take time for the model bindings to get their values. Without a setTimeout()
        // or with setTimeout(..., 0) the filters till have their values from before the
        // prefill!
        $js = <<<JS
        
                {$preventDataLoad}
                var oViewModel = sap.ui.getCore().byId("{$this->getId()}").getModel("view");
                var sPendingPropery = "/_prefill/pending";
                if (oViewModel.getProperty(sPendingPropery) === true) {
                    {$this->buildJsBusyIconShow()}
                    var oPrefillBinding = new sap.ui.model.Binding(oViewModel, sPendingPropery, oViewModel.getContext(sPendingPropery));
                    var fnPrefillHandler = function(oEvent) {
                        oPrefillBinding.detachChange(fnPrefillHandler);
                        {$this->buildJsBusyIconHide()};
                        setTimeout(function() {
                            {$this->buildJsRefresh()};
                        }, 10);
                    };
                    oPrefillBinding.attachChange(fnPrefillHandler);
                    return Promise.resolve(sap.ui.getCore().byId('{$this->getId()}').getModel());
                }
                
                {$disableEditableChangesWatcher}
                {$this->buildJsDataLoaderPrepare()}

JS;
                
                if (! $this->isLazyLoading()) {
                    $js .= $this->buildJsDataLoaderFromLocal($oControlEventJsVar, $keepPagePosJsVar);
                } else {
                    $js .= $this->buildJsDataLoaderFromServer($oControlEventJsVar, $keepPagePosJsVar);
                }
                
                return $js;
    }

    /**
     * Makes sure loading data is triggered every time this control becomes visible
     * @return void
     */
    protected function registerDataLoaderVisibilityCheck() : void
    {
        // That adds a TabsSelectionScript to UI5Tabs
        // that causes the tab data to be loaded if the tab is selected.
        if (null !== $tab = $this->findParentTab()) {

            /* @var $tabEl \exface\UI5Facade\Facades\Elements\UI5Tab */
            $tabEl = $this->getFacade()->getElement($tab);

            // Currently the ObjectPageSection Tabs are loaded always if AutoloadStrategy != "NEVER"
            if (!$tab->isActive() && !$tabEl->isObjectPageSection()) {

                $onChangeJs = <<<JS

                        (function() {
                            const oModelData = sap.ui.getCore().byId('{$this->getId()}').getModel().getData();
                            const tabIsNotLoaded = Object.keys(oModelData).length === 0;
                                
                            if (tabIsNotLoaded) {
                              setTimeout(function(){ 
                                {$this->buildJsRefresh()}
                              }, 0);
                            }
                        })();
JS;

                /* @var $tabsEl \exface\UI5Facade\Facades\Elements\UI5Tabs */
                $tabsEl = $this->getFacade()->getElement($tab->getTabs());
                $tabsEl->addOnTabSelectScript($onChangeJs, $tab);
            }
        }
    }
    
    /**
     * Returns the first parent Tab, if present.
     * 
     * @return WidgetInterface|null
     */
    protected function findParentTab() : ? WidgetInterface {
        if ((null !== $tab = $this->findParentsByClass(Tab::class, 1)[0])
        && $tab->getIdSpace() === $this->getWidget()->getIdSpace()
        ) {
            return $tab;
        }
        return null;
    }


    /**
     * Returns an array of parent widgets with the given class or interface
     * 
     * @param string $classOrInterface
     * @param int|null $maxResults
     * @return array
     */
    public function findParentsByClass(string $classOrInterface, ?int $maxResults = null) : array
    {
        $result  = [];
        $widget = $this->getWidget();
        while ($widget->getParent()) {
            $widget = $widget->getParent();
            if ($widget instanceof $classOrInterface) {
                $result[] = $widget;
                if (count($result) >= $maxResults) {
                    break;
                }
            }
        }

        return $result;
    }
    
    /**
     *
     * @return string
     */
    protected function buildJsDataLoaderFromLocal($oControlEventJsVar = 'oControlEvent', $keepPagePosJsVar = 'bKeepPagingPos')
    {
        $widget = $this->getWidget();
        $dataWidget = $this->getDataWidget();
        $data = $widget->prepareDataSheetToRead($dataWidget->getValuesDataSheet());
        if (! $data->isFresh() && $data->getMetaObject()->isReadable()) {
            $data->dataRead();
        }
        
        // Since non-lazy loading means all the data is embedded in the view, we need to make
        // sure the the view is not cached: so we destroy the view after it was hidden!
        $this->getController()->addOnHideViewScript("var oView = {$this->getController()->getView()->buildJsViewGetter($this)}; if (oView !== undefined) {oView.destroy();}", false);
        
        // FIXME make filtering, sorting, pagination, etc. work in non-lazy mode too!
        
        return <<<JS
            
            (function(oCtrl){
                try {
        			var oServerData = {$this->getFacade()->encodeData($this->getFacade()->buildResponseData($data, $widget))};
        		} catch (err){
                    console.error('Cannot load data into widget {$this->getId()}!');
                    return;
        		}
                if (oCtrl._exfServerData === undefined) {
                    oCtrl._exfServerData = oServerData;
                } 
                oCtrl.getModel().setData($.extend({}, oCtrl._exfServerData));
            })(sap.ui.getCore().byId("{$this->getId()}"))
                
JS;
    }
    
    /**
     * Returns an inline JS snippet that resolves to TRUE if data is currenlty being loaded.
     * 
     * @return string
     */
    protected function buildJsIsDataPending() : string
    {
        return "(sap.ui.getCore().byId('{$this->getId()}')._exfPendingData !== undefined)";
    }
    
    /**
     * Returns the JS code to load data from the server and return a promise resolving to the loaded JSONModel.
     * 
     * This code is used in the controller method `onLoadDataTableId(oControlEvent)` generated by
     * `buildJsDataLoader` if the table uses lazy loading.
     * 
     * The loader remembers the `data` request parameter of the last refresh attempted and compares
     * it to every subsequent refresh until a response from the server is received. This helps avoid
     * concurrent server requests. If a refresh with other data is attempted, it is performed once
     * the current server request comes back. Refreshes with same data are simply ignored. This logic
     * is especially important when multiple filters with apply_on_change are used. Their auto-refreshes
     * may get triggered totally independently, so this logic here makes sure the last one wins and
     * there are as few server requests as possible: not more than two in case the filters change 
     * while data is being loaded.
     * 
     * Note: the request-skipping logic is currently implemented for server-requests only, while the
     * general wait-for-prefill logic is executed by `buildJsDataLoader()`.
     * 
     * @see buildJsDataLoader()
     * 
     * @return string
     */
    protected function buildJsDataLoaderFromServer($oControlEventJsVar = 'oControlEvent', $keepPagePosJsVar = 'bKeepPagingPos')
    {
        $widget = $this->getDataWidget();
        
        $onLoadedJs = <<<JS
            
            if (oModel.getProperty('/rows')?.length > 100) {
                oModel.setSizeLimit(oModel.getProperty('/rows').length);
            }
            if (typeof fnCheckPendingData === 'function' && fnCheckPendingData() === true) {
                {$this->buildJsRefresh()}
                return Promise.resolve(oModel);
            }
            {$this->buildJsBusyIconHide()};
            {$this->buildJsDataLoaderOnLoaded('oModel')}
            {$this->buildJsDataLoaderOnLoadedRestoreSelection('oTable')};
JS;
            
        $onErrorJs = 'delete oTable._exfPendingData; ' . $this->buildJsBusyIconHide();
        $onOfflineJs = $onErrorJs . $this->buildJsOfflineHint('oTable');
        
        if ($this->hasQuickSearch()) {
            $quickSearchParam = "params.q = {$this->getQuickSearchElement()->buildJsValueGetter()};";
        }
        
        return <<<JS
        
        		var oTable = sap.ui.getCore().byId("{$this->getId()}");
                var params = {
					action: "{$widget->getLazyLoadingActionAlias()}",
					resource: "{$this->getPageId()}",
					element: "{$widget->getId()}",
					object: "{$widget->getMetaObject()->getId()}"
				};
        		var oModel = oTable.getModel();
                var oData = oModel.getData();
                var oController = this;
                var aSortItems = [];
                var fnCheckPendingData;

                if(!{$this->buildJsCheckRequiredFilters()}) {
                    {$this->buildJsShowMessageOverlay($widget->getAutoloadDisabledHint())}
                    return Promise.resolve(oModel);
                 }
                
                // Busy icon
                {$this->buildJsBusyIconShow()}
                
        		// Add quick search
                {$quickSearchParam}
                
                // Add configurator params
                {$this->getP13nElement()->buildJsDataLoaderParams('params')}

                // Add custom params for  
                {$this->buildJsDataLoaderParams($oControlEventJsVar, 'params', $keepPagePosJsVar)}
                
                fnCheckPendingData = {$this->buildJsDataLoaderCheckPendingData('oTable', 'params')};
                if (fnCheckPendingData === true) {
                    return Promise.resolve(oModel);
                }
                
                {$this->getServerAdapter()->buildJsServerRequest(
                    $widget->getLazyLoadingAction(),
                    'oModel',
                    'params',
                    $onLoadedJs,
                    $onErrorJs,
                    $onOfflineJs
                )}
                
JS;
    }

    /**
     * Returns a JS code, that yields TRUE if the same read is pending or a JS callback, that should be executed after
     * the request returns from the table.
     * 
     * That callback will check for pending data again, resetting it if it was loaded successfully or returning
     * TRUE once again to trigger an immediate reload if there was a request for other data in the meantime.
     * 
     * This logic prevents concurrent or recursive server request. 
     * 
     * - When a read is requested, we check, if another read is already on its way
     *      - If not, we remember the data requested from the server and continue
     *      - If a request is pending
     *          - If it is the same request - we just ignore the new one (return TRUE)
     *          - If we have a different request now (e.g. other filters), we enqueue it and return a callback to
     *          be executed after the pending request
     * - When the pending request arrives from the server, the callback returned in the previous step is called
     *      - If the currently pending data of the control is the same as that of the request, we are done and
     *      simply continue loading the data
     *      - If the pending data of the control changed (because a different request was made and was stopped
     *      by another instance of this check), we do not continue loading, but make a new server request immediately
     * 
     * This ensures, that we have at most one server request at a time. If any read requests happen in the meantime
     * they are either ignored or enqueued. Of the enqueued requests only the very last one will be executed. That
     * will be done immediately after the pending request. Thus, the user will not see any flickering, but rather just
     * a longer load time. 
     * 
     * @param string $oCtrlJs
     * @param string $oRequestParamsJs
     * @return string
     */
    public function buildJsDataLoaderCheckPendingData(string $oCtrlJs, string $oRequestParamsJs) : string
    {
        return <<<JS

                (function(oTable, oParams){
                    // Current hash - it exists here and in the returned callback
                    var sCurrentRequestData = JSON.stringify(oParams.data);
    
                    if (oTable._exfPendingData !== undefined) {
                        // Skip server request if still waiting for a response
                        if (oTable._exfPendingData !== sCurrentRequestData) {
                            // Update pending data if current request has other data to make sure
                            // an auto-refresh is done afterwards to show latest information
                            oTable._exfPendingData = sCurrentRequestData;
                        }
                        return true;
                    } else {
                        // Remember current data to make it possible to skip concurrent requests
                        // via the above if()
                        oTable._exfPendingData = sCurrentRequestData;
                    }
                    return function() {
                        if (oTable._exfPendingData !== undefined && oTable._exfPendingData !== sCurrentRequestData) {
                            delete oTable._exfPendingData;
                            return true;
                        } else {
                            delete oTable._exfPendingData;
                        }
                    };
                })($oCtrlJs, $oRequestParamsJs)
JS;
    }

    /**
     * Implement this method to restore the selection when switching pages if neccessary
     * 
     * 
     * @see \exface\UI5Facade\Facades\Elements\UI5DataTable::buildJsDataLoaderOnLoadedRestoreSelection() for an example
     * @param string $oTableJs
     * @return string
     */
    protected function buildJsDataLoaderOnLoadedRestoreSelection(string $oTableJs) : string
    {
        return '';
    }

              
    /**
     * Returns a JS snippet to show a message instead of data: e.g. "Please set filters first"
     * or the autoload_disabled_hint of the data widget.
     * 
     * NOTE: by default, this methos simply empties the control using the data resetter. If you want
     * a message to be shown, override this mehtod!
     * 
     * @return string
     */
    protected function buildJsShowMessageOverlay(string $message) : string
    {
        return $this->buildJsDataResetter();
    }
       
    /**
     * Returns control-specific parameters for the data loader AJAX request.
     * 
     * @param string $oControlEventJsVar
     * @param string $oParamsJs
     * @return string
     */
    protected function buildJsDataLoaderParams(string $oControlEventJsVar = 'oControlEvent', string $oParamsJs = 'params', $keepPagePosJsVar = 'bKeepPagingPos') : string
    {
        $js = '';
        
        if ($this->hasPaginator()) {
            $js = $this->buildJsDataLoaderParamsPaging($oParamsJs, $keepPagePosJsVar);
        }
        
        return $js;
    }
    
    /**
     * Adds pagination parameters to the JS object $oParamsJs holding the AJAX request parameters.
     * 
     * @param string $oParamsJs
     * @param string $keepPagePosJsVar
     * @return string
     */
    protected function buildJsDataLoaderParamsPaging(string $oParamsJs = 'params', $keepPagePosJsVar = 'bKeepPagingPos') : string
    {
        $paginationSwitch = $this->getDataWidget()->isPaged() ? 'true' : 'false';
        
        return <<<JS
        
        		// Add pagination
                if ({$paginationSwitch}) {
                    var paginator = {$this->getPaginatorElement()->buildJsGetPaginator('oController')};
                    if (typeof {$keepPagePosJsVar} === 'undefined' || ! {$keepPagePosJsVar}) {
                        paginator.resetAll();
                    }
                    {$oParamsJs}.start = paginator.start;
                    {$oParamsJs}.length = paginator.pageSize;
                }
                
JS;
    }
    
    /**
     * 
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsDataLoaderOnLoaded(string $oModelJs = 'oModel') : string
    {
        $widget = $this->getWidget();
        if ($this->isWrappedInDynamicPage()) {
            if ($this->getDynamicPageHeaderCollapsed() === null) {
                $dynamicPageFixes = <<<JS
                
                            if (sap.ui.Device.system.phone) {
                                sap.ui.getCore().byId('{$this->getIdOfDynamicPage()}').setHeaderExpanded(false);
                            }
JS;
            } else {
               $dynamicPageFixes = $this->getDynamicPageHeaderCollapsed() === true ? "sap.ui.getCore().byId('{$this->getIdOfDynamicPage()}').setHeaderExpanded(false);" : '';
            }
            $dynamicPageFixes .= <<<JS

                            // Redraw the table to make it fit the page height agian. Otherwise it would be
                            // of default height after dialogs close, etc.
                            sap.ui.getCore().byId('{$this->getId()}').invalidate();

JS;
        }
        
        if ($widget instanceof iShowData && $widget->isEditable()) {
            // Enable watching changes for editable columns from now on
            $editableTableWatchChanges = <<<JS
            
            oTable.getModel("{$this->getModelNameForDataLastLoaded()}").setData(JSON.parse(JSON.stringify($oModelJs.getData())));
            {$this->buildJsEditableChangesApplyToModel($oModelJs)} 
            {$this->buildJsEditableChangesWatcherEnable()}

JS;
        }
        
        return <<<JS

            oTable.getModel("{$this->getModelNameForConfigurator()}").setProperty('/filterDescription', {$this->getController()->buildJsMethodCallFromController('onUpdateFilterSummary', $this, '', 'oController')});
            {$dynamicPageFixes}
            {$this->buildJsDataLoaderOnLoadedHandleWidgetLinks($oModelJs)}
            {$editableTableWatchChanges}          
            {$this->buildJsMarkRowsAsDirty($oModelJs)}
            setTimeout(function(){
                {$this->getController()->buildJsEventHandler($this, UI5AbstractElement::EVENT_NAME_REFRESH, false)}
            }, 0);
		
JS;
    }
    
    /**
     * Returns the JS code to add values from widget links to the given UI5 model.
     * 
     * While formulas and other expressions are evaluated in the backend, current values
     * of linked widgets are only known in the front-end, so they need to be added
     * here via JS.
     * 
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsDataLoaderOnLoadedHandleWidgetLinks(string $oModelJs) : string
    {
        $addLocalValuesJs = '';
        $linkedEls = [];
        foreach ($this->getDataWidget()->getColumns() as $col) {
            $cellWidget = $col->getCellWidget();
            if ($cellWidget->hasValue() === false) {
                continue;
            }
            $valueExpr = $cellWidget->getValueExpression();
            if ($valueExpr->isReference() === true) {
                $link = $valueExpr->getWidgetLink($cellWidget);
                $linkedEl = $this->getFacade()->getElement($link->getTargetWidget());
                $linkedEls[] = $linkedEl;
                $addLocalValuesJs .= <<<JS
                
                                oRow["{$col->getDataColumnName()}"] = {$linkedEl->buildJsValueGetter($link->getTargetColumnId())};
JS;
            } elseif ($valueExpr->isConstant()) {
                if ($valueExpr->isString()) {
                    $value = "'{$valueExpr->evaluate()}'";
                } else {
                    $value = $valueExpr->evaluate();
                }
                $addLocalValuesJs .= <<<JS
                
                                oRow["{$col->getDataColumnName()}"] = {$value};
JS;
            }
        }
        if ($addLocalValuesJs) {
            $addLocalValuesJs = <<<JS
            
                            // Add widget link values
                            ($oModelJs.getData().rows || []).forEach(function(oRow){
                                {$addLocalValuesJs}
                            });
                            $oModelJs.updateBindings();
JS;
            $addLocalValuesOnChange = <<<JS
                            
                            var $oModelJs = sap.ui.getCore().byId("{$this->getId()}").getModel();
                            {$addLocalValuesJs}
JS;
            foreach ($linkedEls as $linkedEl) {
                $linkedEl->addOnChangeScript($addLocalValuesOnChange);
            }
        }
        return $addLocalValuesJs;
    }
            
    protected function getModelNameForConfigurator() : string
    {
        return $this->getConfiguratorElement()->getModelNameForConfig();
    }
    
    /**
     *
     * @return UI5DataConfigurator
     */
    protected function getP13nElement()
    {
        return $this->getConfiguratorElement();
    }
    
    protected function getIdOfDynamicPage() : string
    {
        return $this->getId() . "_DynamicPageWrapper";
    }
    
    protected function buildJsDataLoaderPrepare() : string
    {
        return '';
    }
    
    protected function buildJsOfflineHint(string $oTableJs = 'oTable') : string
    {
        return '';
    }
    
    /**
     * Returns TRUE if the table will be wrapped in a sap.f.DynamicPage to create a Fiori ListReport
     *
     * @return boolean
     */
    public function isWrappedInDynamicPage() : bool
    {
        $widget = $this->getWidget();
        if ($widget->getHideHeader() === null) {
            return $widget->hasParent() === false || ($widget->getParent() instanceof Dialog && $widget->getParent()->isFilledBySingleWidget());
        } else {
            return $widget->getHideHeader() === false;
        }
    }
    
    /**
     * Returns if the control uses buildJsPanelWrapper() to generate a standard data widget panel with toolbars, etc.
     * 
     * This is only the case for non-UI5 controls or those, that do not have own toolbars.
     * 
     * @return bool
     */
    protected function isWrappedInPanel() : bool
    {
        return false;
    }
    
    /**
     * Returns TRUE if this table uses a remote data source and FALSE otherwise.
     *
     * @return boolean
     */
    protected function isLazyLoading()
    {
        $dataWidget = $this->getDataWidget();
        return $dataWidget->getLazyLoading($dataWidget->getMetaObject()->isReadable());
    }
    
    protected abstract function isEditable();
    
    /**
     * Wraps the given content in a constructor for the sap.f.DynamicPage used to create the Fiori list report floorplan.
     *
     * @param string $content
     * @return string
     */
    protected function buildJsPage(string $content, string $oControllerJs) : string
    {
        $widget = $this->getWidget();
        // If the data widget is the root of the page, prefill data from the URL can be used
        // to prefill filters. The default prefill-logic of the view will not work, however,
        // because it will load data into the view's default model and this will not have any
        // effect on the table because it's default model is a different one. Thus, we need
        // to do the prefill manually at this point. 
        // If the widget is not the root, the URL prefill will be applied to the view normally
        // and it will work fine. 
        if ($widget->hasParent() === false) {
            $this->getController()->addOnInitScript($this->buildJsPrefillFiltersFromRouteParams());
        }
        
        $top_buttons = $this->buildJsTourGuideDropdown($widget, $this->getController());
        
        // Add the search-button
        if (null !== $searchButtonGrp = $widget->getToolbarMain()->getButtonGroupForSearchActions()) {
            $searchButtons = $searchButtonGrp->getButtons();
            $searchButtons = array_reverse($searchButtons);
            foreach ($searchButtons as $btn) {
                if ($btn->getAction() && $btn->getAction()->isExactly('exface.Core.RefreshWidget')) {
                    $btn->setShowIcon(false);
                    $btn->setHint($btn->getCaption());
                    $btn->setCaption($this->translate('WIDGET.DATATABLE.GO_BUTTON_TEXT'));
                    $btn->setVisibility(WidgetVisibilityDataType::PROMOTED);
                }

                if ($btn->getAction() && $btn->getAction()->isExactly('exface.Core.ResetWidget')) {
                    $btn->setShowIcon(false);
                    $btn->setHint($btn->getCaption());
                    $btn->setCaption($this->translate('WIDGET.DATATABLE.RESET_BUTTON_TEXT'));
                    $this->getFacade()->getElement($btn)->setUI5ButtonType('Transparent');
                }
                $top_buttons .= $this->getFacade()->getElement($btn)->buildJsConstructor() . ',';
            }
        }
        $top_buttons .= $this->buildJsSidebarToggleButton(true);
        
        // Add a title. If the dynamic page is actually the view, the title should be the name
        // of the page, the view represents - otherwise it's the caption of the table widget.
        // Since the back-button is also only shown when the dynamic page is the view itself,
        // we can use the corresponding getter here.
        $caption = $this->getDynamicPageShowBackButton() ? $this->getWidget()->getPage()->getName() : $this->getCaption();
        $title = <<<JS
        
                            new sap.m.Title({
                                text: "{$this->escapeJsTextValue($caption)}"
                            }),
                            
JS;
        
        // Place the back-button next to the title if we need one
        $backButton = <<<JS
                                    new sap.m.Button({
                                        icon: "sap-icon://nav-back",
                                        press: [oController.navBack, oController],
                                        type: sap.m.ButtonType.Transparent
                                    }).addStyleClass('exf-page-heading-btn'),
JS;
        if ($this->getWidget()->getHideCaption() === true) {
            $title = '';
        }
        if ($this->getDynamicPageShowBackButton() === false) {
            $backButton = '';
        }
        $titleExpanded = $this->buildJsTitleHeading($title, $backButton);
        $textSnapped = <<<JS
                                    new sap.m.Text({
                                        text: "{{$this->getModelNameForConfigurator()}>/filterDescription}"
                                    }),
JS;
        $titleSnapped = $this->buildJsTitleHeading($textSnapped, $backButton);
        
        
        
        // Build the top toolbar with title, actions, etc.
        $titleAreaShrinkRatio = '';
        if ($this->getDynamicPageShowToolbar() === true) {
            if ($qsEl = $this->getQuickSearchElement()) {
                $qsEl->setWidthCollapsed('160px');
            }
            $toolbar = $this->buildJsToolbar($oControllerJs, $this->buildJsQuickSearchConstructor($oControllerJs), $top_buttons);

            // due to the SearchField being right aligned, set the shrinkfactor so that the right side shrink the least
            $titleAreaShrinkRatio = 'areaShrinkRatio: "1.6:1.6:1"';
        } else {
            $toolbar = $top_buttons;
        }
        
        // Make sure, the filters in the header of the page use the same model as the filters
        // in the configurator's P13nDialog would do. Otherwise the prefill of tables with
        // page-wrappers would not work properly, as the filter's model would be the one with
        // table rows and not the default model of the view.
        $useConfiguratorModelForHeaderFiltersJs = <<<JS

        (function(){
            var oPage = sap.ui.getCore().byId("{$this->getIdOfDynamicPage()}");
            var oDataCtrl = sap.ui.getCore().byId("{$this->getId()}");
            oPage.getHeader().setModel(oDataCtrl.getModel('{$this->getModelNameForConfigurator()}'));
        })();
JS;
        $this->getController()->addOnInitScript($useConfiguratorModelForHeaderFiltersJs);
        
        // Now build the page's code for the view
        return <<<JS
        
        new sap.f.DynamicPage("{$this->getIdOfDynamicPage()}", {
            {$this->buildJsPropertyVisibile()}
            fitContent: true,
            preserveHeaderStateOnScroll: true,
            headerExpanded: (sap.ui.Device.system.phone === false),
            title: new sap.f.DynamicPageTitle({
				expandedHeading: [
                    {$titleExpanded}
				],
                snappedHeading: [
                    {$titleSnapped}
				],
				actions: [
				    {$toolbar}
				],
                {$titleAreaShrinkRatio}
            }),
            
			header: new sap.f.DynamicPageHeader({
                pinnable: true,
				content: [
                    {$this->getConfiguratorElement()->buildJsMessages($oControllerJs)}
                    new sap.ui.layout.Grid({
                        defaultSpan: "XL2 L3 M4 S12",
                        containerQuery: true,
                        content: [
							{$this->getConfiguratorElement()->buildJsFilters()}
						]
                    })
				]
			}),
			
            content: [
                {$content}
            ]
        }).addStyleClass('{$this->buildCssDynamicPageClasses()}')
JS;
    }
    
    protected function buildCssDynamicPageClasses() : string
    {
        return '';
    }
    
    protected function buildJsTitleHeading(string $title, string $backButton) : string
    {
        return <<<JS
                            new sap.m.HBox({
                                height: "1.625rem",
                                renderType: 'Bare',
                                alignItems: 'Center',
                                items: [
                                    {$backButton}
                                    {$title}
                                ]
                            })
JS;
    }
    
    /**
     * Returns the JS code to give filters default values if there is prefill data
     * @return string
     */
    protected function buildJsPrefillFiltersFromRouteParams() : string
    {
        $filters = $this->getWidget()->getConfiguratorWidget()->getFilters();
        foreach ($filters as $filter) {
            $alias = $filter->getAttributeAlias();
            $setFilterValues .= <<<JS
                
                                var alias = '{$alias}';
                                if (cond.expression === alias) {
                                    var condVal = cond.value;
                                    {$this->getFacade()->getElement($filter)->buildJsValueSetter('condVal')}
                                }
                                
JS;
        }
            
        return <<<JS

                setTimeout(function(){
                    var oViewModel = sap.ui.getCore().byId("{$this->getId()}").getModel("view");
                    var fnPrefillFilters = function() {
                        var oRouteData = oViewModel.getProperty('/_route');
                        if (oRouteData === undefined) return;
                        if (oRouteData.params === undefined) return;
                        
                        var oPrefillData = oRouteData.params.prefill;
                        if (oPrefillData === undefined) return;

                        if (oPrefillData.oId !== undefined && oPrefillData.filters !== undefined) {
                            var oId = oPrefillData.oId;
                            var routeFilters = oPrefillData.filters;
                            if (oId === '{$this->getWidget()->getMetaObject()->getId()}') {
                                if (Array.isArray(routeFilters.conditions)) {
                                    routeFilters.conditions.forEach(function (cond) {
                                        {$setFilterValues}
                                    })
                                }
                            }
                        }
                    };
                    var sPendingPropery = "/_prefill/pending";
                    if (oViewModel.getProperty(sPendingPropery) === true) {
                        var oPrefillBinding = new sap.ui.model.Binding(oViewModel, sPendingPropery, oViewModel.getContext(sPendingPropery));
                        var fnPrefillHandler = function(oEvent) {
                            oPrefillBinding.detachChange(fnPrefillHandler);
                            setTimeout(function() {
                                fnPrefillFilters();
                            }, 0);
                        };
                        oPrefillBinding.attachChange(fnPrefillHandler);
                        return;
                    } else {
                        fnPrefillFilters();
                    }
                }, 0);
                
JS;
    }
    
    /**
     * Returns the JS code to update the filter summary of the data widget
     *
     * Only really does something if there is a configurator with interactive filters!
     *
     * @return string
     */
    protected function buildJsFilterSummaryUpdater()
    {
        if (! $this->hasConfigurator()) {
            return '';
        }
        $filter_checks = '';
        foreach ($this->getDataWidget()->getFilters() as $fltr) {
            $elem = $this->getFacade()->getElement($fltr);
            $filterName = $this->escapeJsTextValue($elem->getCaption());
            $filter_checks .= "if({$elem->buildJsValueGetter()}) {filtersCount++; filtersList += (filtersList == '' ? '' : ', ') + \"{$filterName}\";} \n";
        }
        return <<<JS
                var filtersCount = 0;
                var filtersList = '';
                {$filter_checks}
                if (filtersCount > 0) {
                    return '{$this->translate('WIDGET.DATATABLE.FILTERED_BY')} (' + filtersCount + '): ' + filtersList;
                } else {
                    return '{$this->translate('WIDGET.DATATABLE.FILTERED_BY')}: {$this->translate('WIDGET.DATATABLE.FILTERED_BY_NONE')}';
                }
JS;
    }
    
    /**
     *
     * @return string
     */
    protected function buildJsFilterSummaryFunctionName() 
    {
        return "{$this->buildJsFunctionPrefix()}CountFilters";
    }
    
    /**
     * Returns the constructor for the sap.m.SearchField for toolbar quick search.
     *
     * Must end with a comma unless it is an empty string!
     *
     * @return string
     */
    protected function buildJsQuickSearchConstructor($oControllerJs = 'oController') : string
    {
        if ($this->hasQuickSearch() === false) {
            return <<<JS

                    new sap.m.OverflowToolbarButton({
                        icon: "sap-icon://refresh",
                        text: '{$this->getWorkbench()->getCoreApp()->getTranslator()->translate('ACTION.REFRESHWIDGET.NAME')}',
                        tooltip: '{$this->getWorkbench()->getCoreApp()->getTranslator()->translate('ACTION.REFRESHWIDGET.NAME')}',
                        press: function(oEvent){
                            {$this->buildJsRefresh()}
                        }
                    })

JS;
        }
        
        $qsElement = $this->getQuickSearchElement();
        if ($qsElement instanceof UI5SearchField) {
            $qsElement->setSearchCallbackJs("function(oEvent){ {$this->buildJsRefresh()} }");
        }
        return $qsElement->buildJsConstructorForMainControl($oControllerJs);
    }
        
    /**
     * Returns the data widget.
     * 
     * Override this method to use the trait to render iUseData widgets (like Chart),
     * because their getWidget() method would not return Data, but the visualizer
     * widget.
     * 
     * @return Data
     */
    protected function getDataWidget() : Data
    {
        return $this->getWidget();
    }
    
    /**
     * 
     * @return UI5AbstractElement|NULL
     */
    protected function getQuickSearchElement() : ?UI5AbstractElement
    {
        if ($this->hasQuickSearch()) {
            // The quick search element is instantiated in the init() method above, because we need
            // to make sure, it is created before anyone can attempt to get it via $facade->getElement()
            return $this->quickSearchElement;
        }
        return null;
    }
    
    protected function getConfiguratorElement() : UI5DataConfigurator
    {
        return $this->getFacade()->getElement($this->getWidget()->getConfiguratorWidget());
    }
    
    /**
     * Returns whether the dynamic page header should be collapsed or not, or if this has not been defined for this object.
     * 
     * @return bool|NULL
     */
    protected function getDynamicPageHeaderCollapsed() : ?bool
    {
        return $this->dynamicPageHeaderCollapsed;
    }
    
    /**
     * Set whether the dynamic page header of this widget should be collapsed or not.
     * 
     * @param bool $value
     * @return self
     */
    public function setDynamicPageHeaderCollapsed(bool $value) : AjaxFacadeElementInterface
    {
        $this->dynamicPageHeaderCollapsed = $value;
        return $this;
    }
    
    /**
     * Getter for whether the back button of this page should be instanciated or not, or if this has not been defined.
     * 
     * @return bool
     */
    protected function getDynamicPageShowBackButton() : bool
    {
        // No back-button if we are on the root view (there is nowhere to go back)
        if ($this->getView()->isWebAppRoot()) {
            return false;
        }
        
        // Show back-button if the table is the view root (and the view is not app root - see above)
        $viewRootEl = $this->getView()->getRootElement();
        if ($viewRootEl === $this) {
            return true;
        }
        
        // In all other cases see if any parent already has a back-button
        $parent = $this->getWidget()->getParent();
        while ($parent) {
            $parentEl = $this->getFacade()->getElement($parent);
            // If back-button found - don't show another one
            if ($parentEl->hasButtonBack() === true) {
                return false;
            }
            // If we reached the view root, stop looking (otherwise we will get a controller
            // not initialized exception!)
            if ($parentEl === $viewRootEl) {
                break;
            }
            // Next parent
            $parent = $parent->getParent();
        }
        
        // If no parent has a back-button, place one here
        return true;
    }
    
    /**
     * Setter for whether the toolbar for this page should be displayed or not.
     * 
     * @param bool $trueOrFalse
     * @return self
     */
    public function setDynamicPageShowToolbar(bool $trueOrFalse) : AjaxFacadeElementInterface
    {
        $this->dynamicPageShowToolbar = $trueOrFalse;
        return $this;
    }
    
    /**
     * Getter for whether the toolbar for this page should be displayed or not.
     * 
     * @return bool
     */
    protected function getDynamicPageShowToolbar() : bool
    {
        return $this->dynamicPageShowToolbar;
    }
    
    protected function getModelNameForChanges() : string
    {
        return 'data_changes';
    }
    
    protected function getModelNameForDataLastLoaded() : string
    {
        return 'data_last_loaded';
    }
    
    /**
     * Returns the name of the UI5 model that keeps currently selected rows.
     * 
     * This model use used to synchronize selections between the main control (e.g. table), the selection 
     * indicator and possible other controls.
     * 
     * @see buildJsToolbarSelectionCounter()
     * @see buildJsDataLoaderOnLoadedRestoreSelection()
     * 
     * @return string
     */
    public function getModelNameForSelections() : string
    {
        return 'selections';
    }
    
    protected function getEditableColumnNamesJson() : string
    {
        $editabelColNames = [];
        foreach ($this->getWidget()->getColumns() as $col) {
            if ($col->isEditable()) {
                $editabelColNames[] = $col->getDataColumnName();
            }
        }
        return json_encode($editabelColNames);
    }
    
    protected function buildJsEditableChangesWatcherUpdateMethod(string $changedDataJs) : string
    {
        if ($this->getWidget()->hasUidColumn() === false) {
            return '';
        }
        
        $uidColName = $this->getWidget()->getUidColumn()->getDataColumnName();
        
        return <<<JS

            var oTable = sap.ui.getCore().byId('{$this->getId()}');
            var oChangesModel = oTable.getModel('{$this->getModelNameForChanges()}');
            
            if (oChangesModel.getProperty('/watching') !== true) return;
            
            var oDataLastLoaded = oTable.getModel('{$this->getModelNameForDataLastLoaded()}').getData();
            var oDataChanged = $changedDataJs;
            var oChanges = oChangesModel.getProperty('/changes');
            var aEditableColNames = {$this->getEditableColumnNamesJson()};        

            if (oDataChanged.rows === undefined || oDataChanged.rows.length === 0) return;

            oDataChanged.rows.forEach(function(oRowChanged) {
                var oRowLast;
                var sUid = oRowChanged['$uidColName'];
                for (var i in oDataLastLoaded.rows) {
                    if (oDataLastLoaded.rows[i]['$uidColName'] === sUid) {
                        oRowLast = oDataLastLoaded.rows[i];
                        break;
                    }
                }
                if (oRowLast) {
                    aEditableColNames.forEach(function(sFld){
                        if (oRowChanged[sFld] == '' && oRowLast[sFld] == undefined) {
                            delete oChanges[sUid][sFld];
                        } else if (oRowChanged[sFld] != oRowLast[sFld] ) {
                            if (oChanges[sUid] === undefined) {
                                oChanges[sUid] = {};
                            }
                            oChanges[sUid][sFld] = oRowChanged[sFld];
                        } else {
                            if (oChanges[sUid] && oChanges[sUid][sFld]) {
                                delete oChanges[sUid][sFld];                                
                            }
                        }
                        if (oChanges[sUid] && Object.keys(oChanges[sUid]).length === 0) {
                            delete oChanges[sUid];
                        }
                    });
                }
            });

            oChangesModel.setProperty('/changes', oChanges);

JS;
    }
    
    protected function buildJsEditableChangesWatcherDisable(string $oTableJs = null) : string
    {
        return $this->buildJsEditableChangesModelGetter($oTableJs) . ".setProperty('/watching', false);";
    }
    
    protected function buildJsEditableChangesWatcherEnable(string $oTableJs = null) : string
    {
        return $this->buildJsEditableChangesModelGetter($oTableJs) . ".setProperty('/watching', true);";
    }
    
    protected function buildJsEditableChangesWatcherReset(string $oTableJs = null) : string
    {
        return $this->buildJsEditableChangesModelGetter($oTableJs) . ".setData({changes: {}, watching: false});";
    }
    
    protected function buildJsEditableChangesGetter(string $oTableJs = null) : string
    {
        return $this->buildJsEditableChangesModelGetter($oTableJs) . "?.getProperty('/changes')";
    }
    
    /**
     * Returns a JS snippet that resolves to TURE if the data was edited (changed) and FALSE otherwise.
     * 
     * @param string $oTableJs
     * @return string
     */
    public function buildJsEditableChangesChecker(string $oTableJs = null) : string
    {
        return "(Object.keys({$this->buildJsEditableChangesModelGetter($oTableJs)}.getProperty('/changes')).length > 0)";
    }
    
    protected function buildJsEditableChangesModelGetter(string $oTableJs = null) : string
    {
        return ($oTableJs ?? "sap.ui.getCore().byId('{$this->getId()}')") . ".getModel('{$this->getModelNameForChanges()}')";
    }
    
    protected function buildJsEditableChangesApplyToModel(string $oModelJs) : string
    {
        $widget = $this->getWidget();
        if ($widget->hasUidColumn() === false || $widget->getEditableChangesResetOnRefresh()) {
            return '';
        }
        $uidColName = $widget->getUidColumn()->getDataColumnName();
        
        return <<<JS
        
            // Keep previous values of all editable column in case the had changed
            (function(){
                var aEditableColNames = {$this->getEditableColumnNamesJson()};
                var oData = $oModelJs.getData();
                var aRows = oData.rows;
				if (aRows === undefined || aRows.length === 0) return;
                
                var bDataUpdated = false;
                var oChanges = {$this->buildJsEditableChangesGetter()};
                
                for (var iRow in aRows) {
                    var sUid = aRows[iRow]['$uidColName'];
                    if (oChanges[sUid]) {
                        for (var sFld in oChanges[sUid]) {
                            aRows[iRow][sFld] = oChanges[sUid][sFld];
                            bDataUpdated = true;
                        }
                    }
                }
                
                if (bDataUpdated) {
                    oData.rows = aRows;
                    $oModelJs.setData(oData);
                }
            })();
            
JS;
    }
    
    /**
     * 
     * @param string $oModelJs
     * @return string
     */
    protected function buildJsMarkRowsAsDirty(string $oModelJs) : string
    {
        if (! $this->hasDirtyColumn()) {
            return '';
        }
        
        $widget = $this->getWidget();
        $uidAttributeAlias = $widget->getMetaObject()->getUidAttributeAlias();
        return <<<JS

        (function(){
            var oData = $oModelJs.getData();
            var aRows = oData.rows;
            var bRowsDirty = false;
            exfPWA.actionQueue.getEffects('{$widget->getMetaObject()->getAliasWithNamespace()}')
            .then(function(aEffects) {
                var oDirtyColumn = sap.ui.getCore().byId('{$this->getDirtyFlagAlias()}');
                aEffects.forEach(function(oEffect){
                    for (var j = 0; j < aRows.length; j++) {
                        if (oEffect.key_values.indexOf(aRows[j]['{$uidAttributeAlias}']) > -1
                        || (aRows[j]._actionQueueIds && aRows[j]._actionQueueIds.includes(oEffect.offline_queue_item.id))) {
                            aRows[j]['{$this->getDirtyFlagAlias()}'] = true;
                            bRowsDirty = true;
                            break;
                        }
                    }
                });
                if (oDirtyColumn) {
                    oDirtyColumn.setVisible(bRowsDirty);
                }

                if (bRowsDirty) {
                    oData._dirty = true;
                }

                oData.rows = aRows;
                $oModelJs.setData(oData);                
            })
        })();

JS;
        
    }
    
    /**
     * 
     * @return string
     */
    protected function getDirtyFlagAlias() : string
    {
        return "{$this->getId()}" . "DirtyFlag";
    }
    
    /**
     * 
     * @return bool
     */
    protected function hasDirtyColumn() : bool
    {
        $adapter = $this->getServerAdapter();
        if (! ($adapter instanceof UI5FacadeServerAdapter || $adapter instanceof OfflineServerAdapter)) {
            return false;
        }
        return $this->getDataWidget()->hasUidColumn();
    }
    
    /**
     *
     * @return bool
     */
    protected function hasPaginator() : bool
    {
        return ($this->getDataWidget() instanceof Data);
    }
    
    /**
     *
     * @return UI5DataPaginator
     */
    protected function getPaginatorElement() : UI5DataPaginator
    {
        return $this->getFacade()->getElement($this->getDataWidget()->getPaginator());
    }
    
    
    
    protected function buildJsContextMenuTrigger($eventJsVar = 'oEvent') {
        if ($eventJsVar === '') {
            $eventJsVar = 'null';
        }
        return <<<JS
                var domTarget = $eventJsVar !== undefined ? $eventJsVar.target : null;
                var oMenu = {$this->buildJsContextMenu($this->getWidget()->getButtons(), 'domTarget', true)};
                var eFocused = $(':focus');
                var eDock = sap.ui.core.Popup.Dock;
                oMenu.open(true, eFocused, eDock.CenterCenter, eDock.CenterBottom,  {$eventJsVar}.target);         
JS;
    }
    
    /**
     * Returns a chainable method call to attach left/right/double click handlers to the control.
     * 
     * This method should be called on the control constructor when it is bein initialized. The
     * result will look like this:
     * 
     * ```
     * new sap.m.Table()
     * .attachLeftClick(function(){})
     * .attachRightClick(function(){})
     * .attachDoubleClick(function(){})
     * 
     * ```
     * 
     * To override click handlers for a specific click event, override the corresponding methods
     * in the class, that uses the trait. See UI5DataTable or UI5Scheduler for examples.
     * 
     * @see buildJsClickHandlerDoubleClick()
     * @see buildJsClickHandlerRightClick()
     * @see buildJsClickHandlerSingleClick()
     * 
     * @param string $oControllerJsVar
     * @return string
     */
    protected function buildJsClickHandlers($oControllerJsVar = 'oController') : string
    {
        return $this->buildJsClickHandlerDoubleClick($oControllerJsVar)
        . $this->buildJsClickHandlerRightClick($oControllerJsVar)
        . $this->buildJsClickHandlerLeftClick($oControllerJsVar);
    }
    
    /**
     * 
     * @param string $oControllerJsVar
     * @return string
     */
    protected function buildJsClickHandlerDoubleClick($oControllerJsVar = 'oController') : string
    {
        $dblclick_buttons = $this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_DOUBLE_CLICK);
        if (empty($dblclick_buttons)) {
            return '';
        }

        $js = '';
        // If there are multiple buttons bound to double-click, "click" the first and see if
        // the function returns `false`. If so, the action was not performed and, thus, we
        // can continue with the next action - and so on.
        foreach ($dblclick_buttons as $i => $btn) {
            $btnEl = $this->getFacade()->getElement($btn);
            $js .= <<<JS

                        bResult = {$btnEl->buildJsClickEventHandlerCall($oControllerJsVar)};
                        if (bResult !== false) {
                            return;
                        }
JS;
        }

        return <<<JS
            
            .attachBrowserEvent("dblclick", function(oEvent) {
                var oTargetDom = oEvent.target;
                var iRowIdx = -1;
                var bResult = false;
                if(! ({$this->buildJsClickIsTargetRowCheck('oTargetDom')})) return;
                
        		iRowIdx = {$this->buildJsClickGetRowIndex('oTargetDom')};
                if (iRowIdx !== -1) {
                    {$this->buildJsSelectRowByIndex("sap.ui.getCore().byId('{$this->getId()}')", 'iRowIdx', false, 'false')}
                }
                
                {$js};
            })
JS;
    }
    
    /**
     * 
     * @param string $oControllerJsVar
     * @return string
     */
    protected function buildJsClickHandlerRightClick($oControllerJsVar = 'oController') : string
    {
        // Double click. Currently only supports one double click action - the first one in the list of buttons
        $rightclick_script = '';
        if ($rightclick_button = $this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_RIGHT_CLICK)[0]) {
            $rightclick_script = $this->getFacade()->getElement($rightclick_button)->buildJsClickEventHandlerCall($oControllerJsVar);
        } else {
            $rightclick_script = $this->buildJsContextMenuTrigger();
        }
        
        if ($rightclick_script) {
            return <<<JS
            
            .attachBrowserEvent("contextmenu", function(oEvent) {
                var oTargetDom = oEvent.target;
                var iRowIdx = -1;
                if(! ({$this->buildJsClickIsTargetRowCheck('oTargetDom')})) return;
                
                oEvent.preventDefault();

                iRowIdx = {$this->buildJsClickGetRowIndex('oTargetDom')};
                if (iRowIdx !== -1) {
                    {$this->buildJsSelectRowByIndex("sap.ui.getCore().byId('{$this->getId()}')", 'iRowIdx', false, 'false')}
                }


                {$rightclick_script}
        	})
        	
JS;
        }
        return '';
    }
    
    public function buildJsSelectRowByIndex(string $oTableJs = 'oTable', string $iRowIdxJs = 'iRowIdx', bool $deSelect = false, string $bScrollToJs = 'true') : string
    {
        return '';
    }
    
    protected function buildJsClickGetRowIndex(string $oDomElementClickedJs) : string
    {
        return "$({$oDomElementClickedJs}).parents('tr').index()";
    }

    /**
     * Returns the JS script to reset to reset the model holding selected rows and the corresponding counter
     * @return string
     */
    protected function buildJsSelectionModelReset() : string
    {
        return "sap.ui.getCore().byId('{$this->getId()}').getModel('{$this->getModelNameForSelections()}').setData({rows: []});";
    }
    
    /**
     * 
     * @return string
     */
    protected function buildJsClickGetColumnAttributeAlias(string $oDomElementClickedJs) : string
    {
        return "null";
    }
    
    /**
     * 
     * @param string $oControllerJsVar
     * @return string
     */
    protected function buildJsClickHandlerLeftClick($oControllerJsVar = 'oController') : string
    {
        $onClickJs = $this->getController()->buildJsEventHandler($this, 'select', false);
        // Single click. Currently only supports one click action - the first one in the list of buttons
        if ($onClickJs || $leftclick_button = $this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_LEFT_CLICK)[0]) {
            $btnJs = $leftclick_button ? $this->getFacade()->getElement($leftclick_button)->buildJsClickEventHandlerCall($oControllerJsVar) : '';
            return <<<JS
            
            .attachBrowserEvent("click", function(oEvent) {
        		var oTargetDom = oEvent.target;
                if(! ({$this->buildJsClickIsTargetRowCheck('oTargetDom')})) return;
                {$onClickJs}
                {$btnJs};
            })
JS;
        }
        return '';
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
        return "{$oTargetDomJs} !== undefined";
    }
    
    /**
     * Returns the JS constructor for a sap.ui.unified.Menu to be used on right-click on a data item.
     * 
     * The standard menu includes:
     * - a copy-to-clipboard item if the user clicks on a non-emtpy value
     * - a submenu to filter over the clicked value - unless the clicked value is empty or the data column could
     * not be determined from the click event
     * - items for every Button in the data widget
     * - submenus for every MenuButton in the data widget
     * 
     * IDEA move context-menu-related methods to separate element UI5ContextMenu
     * 
     * @param Button[]
     * @return string
     */
    protected function buildJsContextMenu(array $buttons, string $domTargetJs = "null", bool $isRootMenu = false)
    {
        $coreTltr = $this->getWorkbench()->getCoreApp()->getTranslator();
        
        $filterableAliases = [];
        foreach ($this->getDataWidget()->getColumns() as $col) {
            if ($col->isFilterable()) {
                $filterableAliases[] = $col->getAttributeAlias();
            }
        }
        $filterableAliasesJs = json_encode($filterableAliases);
        return <<<JS
        
                new sap.ui.unified.Menu({
                    items: [
                        new sap.ui.unified.MenuItem({
                            icon: "sap-icon://paste",
                            text: "{$coreTltr->translate('WIDGET.UXONEDITOR.CONTEXT_MENU.CLIPBOARD.COPY_VALUE')}",
                            tooltip: "{$coreTltr->translate('WIDGET.UXONEDITOR.CONTEXT_MENU.CLIPBOARD.COPY_HINT')}",
                            enabled: (function(){
                                if (! $domTargetJs) return false;
                                var mCellValue = $(domTarget).text();
                                if (mCellValue === null || mCellValue === undefined || mCellValue === '') {
                                    return false;
                                } else {
                                    return true;
                                }
                            })($domTargetJs),
                            select: function(oEvent) {
                                if (! $domTargetJs) return;
                                var mCellValue = $(domTarget).text();
                                navigator.clipboard.writeText(mCellValue);
                            }
                        }),
                        (function(domClicked){
                            if (! domClicked) {
                                return new sap.ui.unified.MenuItem({visible: false});
                            }
                            var aFilterableAliases = $filterableAliasesJs;
                            var oSearchPanel = sap.ui.getCore().byId('{$this->getConfiguratorElement()->getIdOfSearchPanel()}');
                            var aFilterItems = oSearchPanel ? oSearchPanel.getFilterItems() : [];
                            var sAttrAlias = {$this->buildJsClickGetColumnAttributeAlias('domClicked')};
                            var mCellValue = $(domClicked).text();
                            var bIsAttribute = (sAttrAlias !== undefined && sAttrAlias !== null && sAttrAlias !== '');
                            var bFilterable = bIsAttribute && aFilterableAliases.includes(sAttrAlias) && (mCellValue !== undefined && mCellValue !== '' && mCellValue !== null);
                            var sValueTrunc = bFilterable ? mCellValue.toString() : '';
                            var oFilterItem;

                            if (! oSearchPanel) {
                                return new sap.ui.unified.MenuItem({visible: false});
                            }

                            aFilterItems.forEach(function(oItem){
                                if (oItem.getColumnKey() === sAttrAlias) {
                                    oFilterItem = oItem;
                                }
                            });   
  
                            var sValueTrunc = mCellValue.toString();
                            if (sValueTrunc.length > 30) {
                                sValueTrunc = sValueTrunc.substring(0, 30) + '...';
                            }
                            return new sap.ui.unified.MenuItem({
                                icon: "sap-icon://filter",
                                text: {$this->escapeString($this->translate('WIDGET.DATATABLE.FILTER_BY_VALUE'))},
                                tooltip: {$this->escapeString($this->translate('WIDGET.DATATABLE.FILTER_BY_VALUE_HINT'))},
                                visible: (bIsAttribute && bFilterable),
                                submenu: new sap.ui.unified.Menu({
                                    items: [
                                        new sap.ui.unified.MenuItem({
                                            icon: "sap-icon://clear-filter",
                                            text: {$this->escapeString($this->translate('WIDGET.DATATABLE.FILTER_BY_VALUE_CLEAR'))},
                                            visible: (oFilterItem ? true : false),
                                            select: function(oEvent) {
                                                oSearchPanel.removeFilterItem(oFilterItem);
                                                {$this->getController()->buildJsMethodCallFromController('onLoadData', $this, '')}
                                            }
                                        }),
                                        new sap.ui.unified.MenuItem({
                                            icon: "sap-icon://overlay",
                                            text: {$this->escapeString($this->translate('WIDGET.DATATABLE.FILTER_BY_VALUE_INCLUDE'))} + ' ' + JSON.stringify(sValueTrunc),
                                            visible: (oFilterItem ? false : true),
                                            select: function(oEvent) {
                                                var oFilterItem;
                                                oSearchPanel.addFilterItem(new sap.m.P13nFilterItem({
                                                    columnKey: sAttrAlias,
                                                    exclude: false,
                                                    operation: 'EQ',
                                                    value1: mCellValue
                                                }));
                                                {$this->getController()->buildJsMethodCallFromController('onLoadData', $this, '')}
                                            }
                                        }),
                                        new sap.ui.unified.MenuItem({
                                            icon: "sap-icon://sys-minus",
                                            text: {$this->escapeString($this->translate('WIDGET.DATATABLE.FILTER_BY_VALUE_EXCLUDE'))} + ' ' + JSON.stringify(sValueTrunc),
                                            select: function(oEvent) {
                                                var oFilterItem;
                                                oSearchPanel.addFilterItem(new sap.m.P13nFilterItem({
                                                    columnKey: sAttrAlias,
                                                    exclude: true,
                                                    operation: 'EQ',
                                                    value1: mCellValue
                                                }));
                                                {$this->getController()->buildJsMethodCallFromController('onLoadData', $this, '')}
                                            }
                                        })
                                    ]
                                })
                            });
                        })($domTargetJs),
                        {$this->buildJsContextMenuItemOpenInNewTab($isRootMenu)}
                        {$this->buildJsContextMenuButtons($buttons, true)}
                    ],
                    itemSelect: function(oEvent) {
                        var oMenu = oEvent.getSource();
                        var oItem = oEvent.getParameters().item;
                        if (! oItem.getSubmenu()) {
                            oMenu.destroy();
                        }
                    }
                })
JS;
    }
    
    /**
     * Returns the JS constructor for the context menu item to open the default action of a row in a new tab.
     * 
     * The item is only rendered if `WIDGET.DATA.SHOW_BUTTON_OPEN_IN_NEW_TAB` is enabled in the facade
     * config. The default action is the one bound to double-click on a row. Only maximized dialogs can
     * be opened this way because only they have their own route in the UI5 app.
     * 
     * @return string
     */
    protected function buildJsContextMenuItemOpenInNewTab(bool $isRootMenu) : string
    {
        if ($isRootMenu === false) {
            return '';
        }
        if (! $this->getFacade()->getConfig()->getOption('WIDGET.DATA.SHOW_BUTTON_OPEN_IN_NEW_TAB')) {
            return '';
        }
        $btnEl = null;

        // get the first (non-disabled) button bound to double-click that opens a dialog page
        // disabled/hidden_ifs should be evaluated at runtime by checking whether to original button is visible/enabled
        foreach ($this->getWidget()->getButtonsBoundToMouseAction(EXF_MOUSE_ACTION_DOUBLE_CLICK) as $btn) {
            if ($btn->isHidden() || $btn->isDisabled() === true) {
                continue;
            }
            $el = $this->getFacade()->getElement($btn);
            if (($el instanceof UI5Button) && $el->opensDialogPage()) {
                $btnEl = $el;
                break;
            }
        }
        if ($btnEl === null) {
            return '';
        }
        
        return <<<JS

                        new sap.ui.unified.MenuItem({
                            icon: "sap-icon://positive",
                            text: {$this->escapeString($this->translate('WIDGET.DATATABLE.OPEN_IN_NEW_TAB'))},
                            enabled: function(){
                                var oBtn = sap.ui.getCore().byId('{$btnEl->getId()}');
                                return oBtn ? oBtn.getEnabled() : false;
                            }(),
                            visible: function(){
                                var oBtn = sap.ui.getCore().byId('{$btnEl->getId()}');
                                return oBtn ? oBtn.getVisible() : false;
                            }(),
                            startsSection: true,
                            select: function(oEvent) {
                                {$btnEl->buildJsOpenDialogInNewTab()};
                            }
                        }),
JS;
    }
    
    /**
     * IDEA move context-menu-related methods to separate element UI5ContextMenu
     * 
     * @param Button[] $buttons
     * @return string
     */
    protected function buildJsContextMenuButtons(array $buttons, bool $startSection = false)
    {
        $context_menu_js = '';
        
        $last_parent = null;
        $startSectionOnFirstButton = $startSection;
        foreach ($buttons as $button) {
            if ($button->isHidden()) {
                continue;
            }
            if ($button->getParent() == $this->getWidget()->getToolbarMain()->getButtonGroupForSearchActions()) {
                continue;
            }
            if ($startSectionOnFirstButton === false) {
                $startSection = ! is_null($last_parent) && $button->getParent() !== $last_parent;
            } else {
                $startSection = true;
                $startSectionOnFirstButton = false;
            }
            $last_parent = $button->getParent();
            
            $context_menu_js .= ($context_menu_js ? ',' : '') . $this->buildJsContextMenuItem($button, $startSection);
        }
        
        return $context_menu_js;
    }
    
    /**
     *
     * IDEA move context-menu-related methods to separate element UI5ContextMenu
     * 
     * @param Button $button
     * @param boolean $startSection
     * @return string
     */
    protected function buildJsContextMenuItem(Button $button, $startSection = false)
    {
        $menu_item = '';
        
        $startsSectionProperty = $startSection ? 'startsSection: true,' : '';
        
        /* @var $btn_element \exface\UI5Facade\Facades\Elements\UI5Button */
        $btn_element = $this->getFacade()->getElement($button);
        
        if ($button instanceof MenuButton){
            if ($button->getParent() instanceof ButtonGroup && $button === $this->getFacade()->getElement($button->getParent())->getMoreButtonsMenu()){
                $caption = $button->getCaption() ? $button->getCaption() : '...';
            } else {
                $caption = $button->getCaption();
            }
            $menu_item = <<<JS
            
                        new sap.ui.unified.MenuItem({
                            icon: "{$btn_element->buildCssIconClass($button->getIcon())}",
                            text: "{$caption}",
                            {$startsSectionProperty}
                            submenu: {$this->buildJsContextMenu($button->getButtons())}
                        })
JS;
        } else {
            $handler = $btn_element->buildJsClickViewEventHandlerCall();
            $select = $handler !== '' ? 'select: ' . $handler . ',' : '';
            $menu_item = <<<JS
            
                        new sap.ui.unified.MenuItem({
                            icon: "{$btn_element->buildCssIconClass($button->getIcon())}",
                            text: "{$button->getCaption()}",
                            enabled: function(){
                                var oBtn = sap.ui.getCore().byId('{$btn_element->getId()}');
                                return oBtn ? oBtn.getEnabled() : false;
                            }(),
                            visible: function(){
                                var oBtn = sap.ui.getCore().byId('{$btn_element->getId()}');
                                return oBtn ? oBtn.getVisible() : false;
                            }(),
                            {$select}
                            {$startsSectionProperty}
                        })
JS;
        }
        return $menu_item;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::registerExternalModules()
     */
    public function registerExternalModules(UI5ControllerInterface $controller) : UI5AbstractElement
    {
        $f = $this->getFacade();
        
        foreach ($this->getDataWidget()->getColumns() as $col) {
            $f->getElement($col)->registerExternalModules($controller);
        }
        
        return $this;
    }
    
    /**
     * 
     * @param string $js
     * @return UI5AbstractElement
     */
    public function addOnSelectScript(string $js) : UI5AbstractElement
    {
        $this->getController()->addOnEventScript($this, 'select', $js);
        return $this;
    }
    
    public function addOnRefreshScript(string $js) : UI5AbstractElement
    {
        $this->getController()->addOnEventScript($this, UI5AbstractElement::EVENT_NAME_REFRESH, $js);
        return $this;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetterMethod()
     */
    public function buildJsValueGetterMethod()
    {
        throw new FacadeLogicError('Cannot call buildJsValueGetterMethod() on a UI5 data element: use buildJsValueGetter() instead!');
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueSetterMethod()
     */
    public function buildJsValueSetterMethod($valueJs)
    {
        throw new FacadeLogicError('Cannot call buildJsValueSetterMethod() on a UI5 data element: use buildJsValueSetter() instead!');
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsValueGetter()
     */
    public function buildJsValueGetter($dataColumnName = null, $rowNr = null)
    {
        $widget = $this->getDataWidget();
        
        /* @var $col \exface\Core\Widgets\DataColumn */
        if ($dataColumnName !== null && ! $col = $widget->getColumnByDataColumnName($dataColumnName)) {
            if ($col = $widget->getColumnByAttributeAlias($dataColumnName)) {
                $dataColumnName = $col->getDataColumnName();
            }
        }
        
        switch (true) {
            // If we are looking for a specific row, get all data and select that row
            case is_int($rowNr):
                return "(function(aAllRows){ return ! aAllRows || aAllRows.length === 0 ? '' : (aAllRows[0]['{$dataColumnName}'] || '') })({$this->buildJsDataGetter(null)}.rows)";
            // IDEA allow to explicitly request all values of a column as a list
            //case $rowNr !== null && strcasecmp($rowNr, 'list') === 0:
            //    $rows = $this->buildJsGetRowsAll('oTable');
            //    break;
            
            // Otherwise get the selected rows and proceed
            case $rowNr === null:
                $rows = $this->buildJsGetRowsSelected('oTable');
                break;
            default:
                throw new FacadeRuntimeError('Data row reference "' . $rowNr . '" not supported in UI5 facades!');
        } 
        
        if ($dataColumnName !== null) {
            if (mb_strtolower($dataColumnName) === '~rowcount') {
                return "(sap.ui.getCore().byId('{$this->getId()}').getModel().getData().rows || []).length";
            }
            if (! $col && ! ($widget->getMetaObject()->getUidAttributeAlias() === $dataColumnName)) {
                throw new WidgetConfigurationError($this->getWidget(), 'Cannot build live value getter for ' . $this->getWidget()->getWidgetType() . ': column "' . $dataColumnName . '" not found!');
            }
            $delim = $col && $col->isBoundToAttribute() ? $col->getAttribute()->getValueListDelimiter() : EXF_LIST_SEPARATOR;
            $colMapper = '.map(function(value,index) { return value === undefined ? "" : value["' . $dataColumnName . '"];}).join("' . $delim . '")';
        } else {
            $colMapper = '';
        }
        
        return <<<JS
        
(function(){
    var oTable = sap.ui.getCore().byId('{$this->getId()}');
    return {$rows}{$colMapper};
}() || '')

JS;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::addOnChangeScript()
     */
    public function addOnChangeScript($js)
    {
        if (strpos($js, $this->buildJsValueGetter('~rowcount')) !== false) {
            $this->addOnRefreshScript($js);
            return $this;
        }
        return parent::addOnChangeScript($js);
    }
    
    /**
     * Returns an inline-snippet (without `;`) to get an array of the currently selected data rows.
     * 
     * If no data is selected, the JS snippet must resolve to an empty array.
     * 
     * Each item of the array must be a JS object with the same structure as the rows in
     * the main model of the data widget (in other words, it should contain a selection of
     * those rows).
     * 
     * For single-select widget, this method must return an array with one or zero elements.
     * 
     * If pagination is used, this method only returns the selections from the current page!
     * Keeping selections on other pages (depending on `multi_select_saved_on_navigation`) is
     * handled by syncing via selections model - see `buildJsDataLoaderOnLoadedRestoreSelection()` 
     * and `buildJsToolbarSelectionCounter()` for more details.
     * 
     * @see buildJsDataLoaderOnLoadedRestoreSelection()
     * 
     * @param string $oControlJs
     * @return string
     */
    protected abstract function buildJsGetRowsSelected(string $oControlJs) : string;
    
    /**
     * 
     * @param string $oControlJs
     * @return string
     */
    protected function buildJsGetRowsAll(string $oControlJs) : string
    {
        return "({$oControlJs}.getModel().getData().rows || [])";
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractAjaxFacade\Elements\AbstractJqueryElement::buildJsDataGetter()
     */
    public function buildJsDataGetter(ActionInterface $action = null)
    {
        if ($action !== null && $action->isDefinedInWidget() && $action->getWidgetDefinedIn() instanceof DataButton) {
            $customMode = $action->getWidgetDefinedIn()->getInputRows();
        } else {
            $customMode = null;
        }
        
        switch (true) {
            case $customMode === DataButton::INPUT_ROWS_ALL:
            case $action === null:
                $getRows = "var rows = {$this->buildJsGetRowsAll('oControl')};";
                break;
            
            // If the button requires none of the rows explicitly
            case $customMode === DataButton::INPUT_ROWS_NONE:
                return '{}';
            
            // If we are reading, than we need the special data from the configurator
            // widget: filters, sorters, etc.
            case $action instanceof iReadData:
                return $this->getConfiguratorElement()->buildJsDataGetter($action);
            
            default:
                $getRows = "var rows = {$this->buildJsGetRowsSelected('oControl', false)};";
        }
        
        // Determine the columns we need in the actions data
        $colNamesList = implode(',', $this->getDataWidget()->getActionDataColumnNames());
        
        return <<<JS
    function() {
        var oControl = sap.ui.getCore().byId('{$this->getId()}');
        {$getRows}
        rows = rows || [];        
        // Remove any keys, that are not in the columns of the widget
        rows = rows.map(({ $colNamesList }) => ({ $colNamesList }));

        return {
            oId: '{$this->getWidget()->getMetaObject()->getId()}',
            rows: rows
        };
    }()
JS;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsOnEventScript()
     */
    public function buildJsOnEventScript(string $eventName, string $scriptJs, string $oEventJs) : string
    {
        $parentResult = parent::buildJsOnEventScript($eventName, $scriptJs, $oEventJs);
        switch ($eventName) {
            // Before the change event is fired, check if the selection actually did change by
            // comparing it to a saved copy of the previous selection.
            case self::EVENT_NAME_CHANGE:
                return <<<JS
                
            // Perform the on-select scripts in any case
            {$this->getController()->buildJsEventHandler($this, 'select', false)}
            
            // Check, if selection actually changed. Return here if not.
            if (
                (function(){
                    var oControl = sap.ui.getCore().byId('{$this->getId()}');
                    var aNewSelection = {$this->buildJsGetRowsSelected('oControl')};
                    var aOldSelection = oControl.data('exfPreviousSelection') || [];
                    var bNoChange = exfTools.data.compareData(aOldSelection, aNewSelection);
                    oControl.data('exfPreviousSelection', aNewSelection);
                    return bNoChange;
                })()
            ) {
                return;
            }
            {$parentResult}
            
JS;
            default:
                return $parentResult;
        }
    }
    
    public function hasButtonBack() : bool
    {
        return $this->isWrappedInDynamicPage() && $this->getDynamicPageShowBackButton();
    }
    
    protected function buildCssHeightDefaultValue()
    {
        return '100%';
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsResetter()
     */
    public function buildJsResetter() : string
    {
        $resetConfiguratorJs = $this->hasConfigurator() ? $this->getConfiguratorElement()->buildJsResetter() : '';
        $resetEditableCellsJs = $this->isEditable() ? $this->buildJsEditableChangesWatcherReset() : '';
        $resetQuickSearch = $this->hasQuickSearch() ? $this->getQuickSearchElement()->buildJsResetter() : '';
        return $this->buildJsSelectionModelReset() . $resetQuickSearch . ';' . $this->buildJsDataResetter() . ';' . $resetEditableCellsJs . ';' . $resetConfiguratorJs;
    }
    
    /**
     * 
     * @return string
     */
    public function buildJsChangesGetter(bool $onlyVisible = false) : string
    {
        return <<<JS
(function(oTable){
                var oDataChanges = {$this->buildJsEditableChangesGetter('oTable')};
                if (oDataChanges === undefined || oDataChanges.length === 0) {
                    return [];
                }
                return [
                    {
                        elementId: '{$this->getId()}',
                        caption: {$this->escapeString($this->getCaption())}
                    }
                ];
            })(sap.ui.getCore().byId('{$this->getId()}'))
JS;
    }
    
    /**
     * Returns JS code to select the first row in a table, that has the given value in the specified column.
     * If the parameter '$deSelect' is true, it will deselect the row instead.
     *
     * The generated code will search the current values of the $column for an exact match
     * for the value of $valuesJs JS variable, mark the first matching row as selected and
     * scroll to it to ensure it is visible to the user.
     *
     * The row index (starting with 0) is saved to the JS variable specified in $rowIdxJs.
     *
     * If the $valuesJs is not found, $onNotFoundJs will be executed and $rowIdxJs will be
     * set to -1.
     * 
     * @param string $valuesJs
     * @param mixed $columnName
     * @param bool $deSelect
     * @param string $onNotFoundJs
     * 
     * @throws \exface\Core\Exceptions\Facades\FacadeRuntimeError
     * 
     * @return string
     */
    public function buildJsSelectRowByValue(string $valuesJs, ?string $columnName = null, bool $deSelect = false, string $onNotFoundJs = '') : string
    {
        if ($columnName === null) {
            if ($this->getDataWidget()->hasUidColumn()) {
                $columnName = $this->getDataWidget()->getUidColumn();
            } else {
                throw new FacadeRuntimeError('Cannot select data row by value!');
            }
        }
        $col = $this->getDataWidget()->getColumnByDataColumnName($columnName);
        switch (true) {
            case $col->isBoundToAttribute():
                $delim = $col->getAttribute()->getValueListDelimiter();
                break;
            default:
                $delim = EXF_LIST_SEPARATOR;
                break;
        }
        return <<<JS
        
(function(mVals) {
    var oTable = sap.ui.getCore().byId("{$this->getId()}");
    var aRows = oTable.getModel().getData().rows;
    var oModelSelected = oTable.getModel('{$this->getModelNameForSelections()}');
    var aRowIdxs = [];
    var aVals = [];
    var sValDelimiter = {$this->escapeString($delim)};
    if (mVals === null || mVals === undefined || mVals === '') {
        return aRowIdxs;
    }
    aVals = Array.isArray(mVals) ? mVals : mVals.toString().split(sValDelimiter);
    aVals.forEach(function(mVal){
        var aValIdxs = [];
        for (var i in aRows) {
            if (aRows[i]['{$columnName}'] == mVal) {
                aValIdxs.push(i);
            }
        }
        // Remove item from table's selected objects
        if ({$this->escapeBool($deSelect)}) {
            var aSelectedRows = oModelSelected.getProperty('/rows');
            const selectedObjectsIndex = aSelectedRows.findIndex(selectedObject => selectedObject['{$columnName}'] == mVal);
            if (selectedObjectsIndex !== -1) {
                aSelectedRows.splice(selectedObjectsIndex, 1);
            }
        } 

        if (aValIdxs.length === 0){
            {$onNotFoundJs};
        } else {
            aRowIdxs = aRowIdxs.concat(aValIdxs);
        }
    });
    aRowIdxs.forEach(function(i){
        {$this->buildJsSelectRowByIndex('oTable', 'i', $deSelect)}
    });
    oModelSelected.refresh(true);
    return aRowIdxs;
})($valuesJs);
JS;
    }

    /**
     * 
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsCallFunction()
     */
    public function buildJsCallFunction(string $functionName = null, array $parameters = [], ?string $jsRequestData = null) : string
    {
        switch (true) {
            case $functionName === Data::FUNCTION_SELECT:
            case $functionName === Data::FUNCTION_UNSELECT:
                list($vals, $colName, $scrollTo) = $parameters;
                if (empty($vals)) {
                    throw new WidgetFunctionArgumentError($this, 'No values passed to widget function select of ' . $this->getWidget()->getWidgetType());
                }
                return $this->buildJsSelectRowByValue($vals, $colName, $functionName === Data::FUNCTION_UNSELECT);
        }
        return parent::buildJsCallFunction($functionName, $parameters, $jsRequestData);
    }

    /**
     * Returns TRUE if this data widget needs a configurator (cog icon)
     * @return bool
     */
    protected function hasConfigurator() : bool
    {
        return ! $this->getWidget()->getConfiguratorWidget()->isDisabled();
    }

    /**
     * Returns inline JS code resolving to TRUE if the given cell or column widget is editable and required and FALSE otherwise
     * 
     * This is basically the same as UI5Input::buildJsRequiredGetter(), but works for table columns. The regular
     * JS required checker needs a real instantiated JS facade element, which does not work in tables - here the
     * input element is just a template for the column and neither has an id nor a real instance. It gets even more
     * complicated with required_if linking other columns of the same table - in this case, we even need the specific
     * row number to determine if the cell is required or not.
     * 
     * Thus, UI5Input::buildJsRequiredGetter() and derivatives will not use their regular logic for in-table widgets,
     * but forward to this method here. 
     * 
     * This method should be overridden by specific implementations of data widgets like UI5DataSpreadSheet and
     * similar.
     * 
     * @param WidgetInterface $cell
     * @return string
     */
    public function buildJsIsCellRequired(WidgetInterface $cell) : string
    {
        if ($cell instanceof DataColumn) {
            $cell = $cell->getCellWidget();
        }
        if ($cell instanceof iCanBeRequired) {
            return $cell->isRequired() ? 'true' : 'false';
        }
        // TODO how to determine, if a column is required?
        return 'false';
    }
    
    public function buildJsDataSetter(string $jsData) : string
    {
        if (! $this->isLazyLoading()) {
            $setNonLazyDataJs = 'oCtrl._exfServerData = ' . $jsData . ';';
        } else {
            $setNonLazyDataJs = '';
        }
        return <<<JS

        (function(oCtrl){
            oCtrl.getModel().setData({$jsData});
            $setNonLazyDataJs;
        })(sap.ui.getCore().byId('{$this->getId()}'));
JS;

    }
}