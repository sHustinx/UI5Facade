<?php
namespace exface\UI5Facade\Facades\Elements;

use exface\Core\CommonLogic\Model\UiPageTreeNode;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Model\UiPageTreeNodeInterface;

/**
 *
 * @method \exface\Core\Widgets\NavMenu getWidget()
 * @method UI5ControllerInterface getController()
 *
 * @author Ralf Mulansky
 *
 */
class UI5NavMenu extends UI5AbstractElement
{

    /**
     *
     * {@inheritDoc}
     * @see \exface\UI5Facade\Facades\Elements\UI5AbstractElement::buildJsConstructor()
     */
    public function buildJsConstructor($oControllerJs = 'oController') : string
    {

    // TODO: fiori3!! mobile??
    // Wenn Suche bleibt, dann translation

        $menu = $this->getWidget()->getMenu();
        $output = <<<JS

new sap.tnt.SideNavigation("{$this->getId()}_scrollContainer", {
    expanded: false,
    item: new sap.tnt.NavigationList("{$this->getId()}",{
        items: [{$this->buildNavigationListItems($menu)}]
    }),
    fixedItem: new sap.tnt.NavigationList({
        items: [
            new sap.tnt.NavigationListItem({
                icon: "sap-icon://search",
                text: "Search",
                design: "Action",
                select: function(oEvent) {
                    var oSideNav = sap.ui.getCore().byId("{$this->getId()}_scrollContainer");
                    var oItem = oEvent.getSource();
                    if (!oSideNav._searchPopover) {
                        oSideNav._searchField = new sap.m.SearchField({
                            liveChange: function(oEvent) {
                                var sQuery = oEvent.getParameter("newValue").toLowerCase();
                                var oNavList = sap.ui.getCore().byId("{$this->getId()}");
                                if (!oNavList) return;

                                // set items invisible if they dont match query
                                // keep parent items visible, if they have visible children or match query 
                                function filterItems(aItems) {
                                    var bAnyVisible = false;
                                    aItems.forEach(function(oItem) {
                                        var sText = oItem.getText().toLowerCase();
                                        var aSubItems = oItem.getItems ? oItem.getItems() : [];
                                        if (sQuery === "") {
                                            oItem.setVisible(true);
                                            oItem.setExpanded(false);
                                            if (aSubItems.length) filterItems(aSubItems);
                                            bAnyVisible = true;
                                        } else {
                                            var bChildVisible = aSubItems.length > 0 ? filterItems(aSubItems) : false;
                                            var bMatch = sText.includes(sQuery) || bChildVisible;
                                            oItem.setVisible(bMatch);
                                            if (bMatch && aSubItems.length > 0) oItem.setExpanded(true);
                                            if (bMatch) bAnyVisible = true;
                                        }
                                    });
                                    return bAnyVisible;
                                }

                                filterItems(oNavList.getItems());
                            }
                        });
                        oSideNav._searchPopover = new sap.m.Popover({
                            //title: "Search",
                            showHeader: false,
                            placement: sap.m.PlacementType.Auto,
                            content: [oSideNav._searchField]
                        });
                    }
                    oSideNav._searchPopover.openBy(oItem.getDomRef() || oItem);
                }
            })
        ]
    })
});

console.log('sidenav html');


JS;
        
        return $output;
    }
    
    /**
     * 
     * @param UiPageTreeNodeInterface[] $menu
     * @return string
     */
    protected function buildNavigationListItems(array $menu, int $level = 1) : string
    {
        $output = '';
        foreach ($menu as $node) {
            $url = $this->getFacade()->buildUrlToPage($node->getPageAlias());
            if ($level === 1) {
                // TODO why do font-awesome icons like `sap-icon://font-awesome/bug` not work here???
                // seems to be some rendering/timing issue? 
                // setting them visible/invisible makes them appear
                $icon = ($node->getIcon() && ! Icons::isIconSetSVG($node->getIconSet())) ? $this->getIconSrc($node->getIcon()) : "folder-blank";
            } else {
                $icon = '';
                //$icon = ($node->getIcon() && ! Icons::isIconSetSVG($node->getIconSet())) ? $this->getIconSrc($node->getIcon()) : "";
            }
            if ($node->hasChildNodes() === true) {
                $icon = $icon === "folder-blank" ? "open-folder" : '';
                $output .= <<<JS
            
        new sap.tnt.NavigationListItem({
            icon: "{$icon}",
            text: "{$node->getName()}",
            items: [
                // BOF {$node->getName()} SubMenu
                
                {$this->buildNavigationListItems($node->getChildNodes(), $level + 1)}
                
                // EOF {$node->getName()} SubMenu
                ],
            select: function(){sap.ui.core.BusyIndicator.show(0); window.location.href = '{$url}';}
        }),

JS;
            } else {
                $output .= <<<JS

        new sap.tnt.NavigationListItem({
            icon: "{$icon}", 
            text: "{$node->getName()}", 
            select: function(){sap.ui.core.BusyIndicator.show(0); window.location.href = '{$url}';} 
        }),

JS;
            }
        }
        return $output;
    }
}
