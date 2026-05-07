// store original console 
if (!window.__originalConsole) {
	window.__originalConsole = window.console;
}

// Array to store captured errors
let capturedErrors = [];

// Regex patterns for errors to ignore
const ignoredErrorPatterns = [
	/Assertion failed: could not find any translatable text for key/i,
	/The target you tried to get .* does not exist!/i,
	/EventProvider sap\.m\.routing\.Targets/i,
	/Modules that use an anonymous define\(\) call must be loaded with a require\(\) call.*/i
];

// Toggle online/offlie icon
window.addEventListener('online', function () {
	if (exfLauncher.isOnline()) {
		exfLauncher.toggleOnlineIndicator();
	}
	exfLauncher.contextBar.getComponent().getPWA().updateErrorCount();
	if (!navigator.serviceWorker) {
		syncOfflineItems();
	}
});
window.addEventListener('offline', function () {
	exfLauncher.toggleOnlineIndicator();
});

window.addEventListener('load', function () {
	exfLauncher.initPoorNetworkPoller();
	
	//ServiceWorker automatic update
	checkForServiceWorkerUpdate();
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.getRegistration().then(reg => {
			if (reg && reg.waiting) {
				reg.waiting.postMessage({ type: 'SKIP_WAITING' });
			}
		});
	}
});

if (navigator.serviceWorker) {
	navigator.serviceWorker.addEventListener('message', function (event) {
		exfLauncher.contextBar.getComponent().getPWA().updateQueueCount()
			.then(function () {
				exfLauncher.contextBar.getComponent().getPWA().updateErrorCount();
				exfLauncher.showMessageToast(event.data);
			})
	});
}

function syncOfflineItems() {
	if (exfLauncher.isOfflineVirtually()) return;

	exfPWA.actionQueue.getIds('offline')
		.then(function (ids) {
			var count = ids.length;
			if (count > 0) {
				var shell = exfLauncher.getShell();
				shell.setBusy(true);
				exfPWA.actionQueue.syncIds(ids)
					.then(function () {
						exfLauncher.contextBar.getComponent().getPWA().updateQueueCount();
						exfLauncher.contextBar.getComponent().getPWA().updateErrorCount();
						return;
					})
					.then(function () {
						shell.setBusy(false);
						var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.SYNC_ACTIONS_COMPLETE");
						exfLauncher.showMessageToast(text);
						return;
					})
			}
		})
		.catch(function (error) {
			shell.setBusy(false);
			exfLauncher.showMessageToast("Cannot synchronize offline actions: " + error);
		})
};

const exfLauncher = {};
(function () {

	exfPWA.actionQueue.setTopics(['offline', 'ui5']);

	const SPEED_HISTORY_ARRAY_LENGTH = 10 * 60; // seconds for 10 minutes
	const NETWORK_STATUS_ONLINE = 'online';
	const NETWORK_STATUS_OFFLINE_FORCED = 'offline_forced';
	const NETWORK_STATUS_OFFLINE_BAD_CONNECTION = 'offline_bad_connection';
	const NETWORK_STATUS_OFFLINE = 'offline';

	var _oShell = {};
	var _oLauncher = this;
	var _bBusy = false;
	var _oNetworkSpeedPoller;
	var _oSpeedStatusDialogInterval
	var _bLowSpeed = false;
	var _forceOffline = false;
	var _autoOffline = true;
	const _speedHistory = new Array(SPEED_HISTORY_ARRAY_LENGTH).fill(null);
	var _oConfig = {
		contextBar: {
			refreshWaitSeconds: 5
		}
	};
	
	var _oHistory = {
		_oTitles : {},
		getTitleOfHash : function(sHash) {
			return this._oTitles[sHash];
		},
		setTitleOfHash : function(sHash, sTitle) {
			this._oTitles[sHash] = sTitle;
      this.refreshCrumbs();
		},
		getUI5History : function() {
			return sap.ui.core.routing.History.getInstance();
		},
    /**
     * It fills the page crumbs and cleans the _oTitels.
     */
    refreshCrumbs : function() {
      const oHistory = this.getUI5History();
      let aCrumbs = [];

      const historyPosition = oHistory.iHistoryPosition;
      const aHistory = oHistory.aHistory;

      const oModel = _oShell.getModel();
      const homeTitle = oModel.getProperty('/_app/home_title');
      const homeUrl = oModel.getProperty('/_app/app_url');
      let currentTitle = "";

      for (let i = 0; i <= historyPosition; i++) {
        const iStep = i - historyPosition;
        const hash = aHistory[i];
        const title = this.getTitleOfHash(hash);

        if (i < historyPosition) {
          aCrumbs.push({
            title: title,
            steps: iStep,
            url: null
          });
        } else {
          // It sets the last element as the title because it is the current page.
          currentTitle = title;
        }
      }

      oModel.setProperty("/_breadcrumbs/current_title", currentTitle);

      const firstElementOfTheHistory = this.getTitleOfHash(aHistory[0]);
      // It sets the first element of the breadcrumbs as the home title,
      // unless it is already set. It's necessary if the user opens a page
      // without the home_title in the history.
      if (
          (typeof homeTitle === "string" && homeTitle.trim() !== "")
          && firstElementOfTheHistory !== homeTitle
      ) {
        aCrumbs.unshift(
            {
              title: homeTitle,
              steps: null,
              url: homeUrl
            });
      }

      oModel.setProperty("/_breadcrumbs/crumbs", aCrumbs);

      let aCrumbsAndTitle = [...aCrumbs.map(c => ({ ...c }))];

      if (typeof currentTitle === "string" && currentTitle.trim() !== "") {
        aCrumbsAndTitle.push({ title: currentTitle });
      }

      this.cleanUpTitles(aCrumbsAndTitle);
    },
    /**
     * It takes an array of crumbs, including the page title,
     * and deletes all titles from _oTitles that are not in the given array.
     *
     * @param aCrumbs
     */
    cleanUpTitles: function (aCrumbs) {
      if (!Array.isArray(aCrumbs) || aCrumbs.length === 0) return;

      const validTitles = new Set(aCrumbs.map(crumb => crumb.title));

      for (const sHash in this._oTitles) {
        if (!validTitles.has(this._oTitles[sHash])) {
          delete this._oTitles[sHash];
        }
      }
    }
	};

	// Reload context bar every 30 seconds
	setInterval(function () {
		exfLauncher.contextBar.load();
	}, 30 * 1000);

	var _lastNetworkState = {
		isLowSpeed: false,
		isOnline: true,
		isAutoOffline: false
	};

	this.initPoorNetworkPoller = function () {
		// FIXME #auto-offline
		return;
		clearInterval(_oNetworkSpeedPoller);
		_oNetworkSpeedPoller = setInterval(function () {
			var isNetworkSlow = exfLauncher.isNetworkSlow();
			if (isNetworkSlow) {
				_bLowSpeed = true;
			}

			if (isNetworkSlow && _autoOffline) {
				exfLauncher.updateNetworkState(isNetworkSlow, _autoOffline);
				clearInterval(_oNetworkSpeedPoller);
				exfLauncher.initFastNetworkPoller();
			}
		}, 5 * 1000);
	};

	this.initFastNetworkPoller = function () {
		// FIXME #auto-offline
		return;
		clearInterval(_oNetworkSpeedPoller);
		_oNetworkSpeedPoller = setInterval(function () {
			var isNetworkSlow = exfLauncher.isNetworkSlow();
			exfLauncher.updateNetworkState(isNetworkSlow, _autoOffline);

			if (!isNetworkSlow) {
				_bLowSpeed = false;
			}
			if (!isNetworkSlow || !_autoOffline) {
				clearInterval(_oNetworkSpeedPoller);
				exfLauncher.initPoorNetworkPoller();
			}
		}, 5000);
	};

	// // Function to initialize the network state
	// function initNetworkState() {
	// 	exfPWA.data.getAutoOfflineToggleStatus()
	// 		.then(function (autoOfflineStatus) {
	// 			_autoOffline = autoOfflineStatus;
	// 			updateNetworkState(exfLauncher.isNetworkSlow(), _autoOffline);
	// 			exfLauncher.initPoorNetworkPoller();
	// 		})
	// 		.catch(function (error) {
	// 			console.error("Error initializing network state:", error);
	// 			exfLauncher.initPoorNetworkPoller();
	// 		});
	// };

	/**
	 * TODO does this function return boolean or promise??? Looks like a mixture right now!
	 * @returns {boolean}
	 */
	this.isNetworkSlow = function () {
		// FIXME #auto-offline
		return false;
		// Check if the network speed is slow via browser API (Chrome, Opera, Edge) 
		if (navigator?.connection?.effectiveType) {
			if (['2g', 'slow-2g'].includes(navigator.connection.effectiveType)) {
				// exfPWA.data.saveConnectionStatus(NETWORK_STATUS_OFFLINE_BAD_CONNECTION);
				return true;
			}
			else if (navigator.connection.downlink == 0) {
				exfPWA.data.saveConnectionStatus(NETWORK_STATUS_OFFLINE);
			}
			else {
				// exfPWA.data.saveConnectionStatus(NETWORK_STATUS_ONLINE);
				return false;
			}
		}
		// Check if the network speed is slow via network speed history (iOS, Android, Firefox)
		else {
			return exfPWA.data.getAllNetworkStats()
				.then(stats => {
					// If there are less than 10 data points, get all records; otherwise, get the last 10 records
					const lastStats = stats.length >= 10 ? stats.slice(-10) : stats;

					// Calculate the average speed
					const averageSpeed = lastStats.reduce((sum, stat) => {
						// Ensure stat.speed is a number
						const speed = Number(stat.speed);
						return isNaN(speed) ? sum : sum + speed;
					}, 0) / lastStats.length;

					if (averageSpeed > 0.1) {
						// If the average speed is greater than 0.5
						exfPWA.data.saveConnectionStatus(NETWORK_STATUS_ONLINE, false, _autoOffline, _forceOffline);
						return false; // Network is fast
					} else {
						// If the average speed is 0.5 or less
						exfPWA.data.saveConnectionStatus(NETWORK_STATUS_OFFLINE_BAD_CONNECTION, true, _autoOffline, _forceOffline);
						return true; // Network is slow
					}
				})
				.catch(error => {
					return false; // In case of error, default to fast
				});
		}
	};

	/**
	 * 
	 * @returns {boolean}
	 */
	this.isOfflineVirtually = function () {
		return (_autoOffline && _bLowSpeed) || _forceOffline;
	};


	this.isSemiOffline = async function () {
		return await exfPWA.isOfflineVirtually();
	};

	this.isOnline = function () {
		// Check if we're virtually offline
		if (this.isOfflineVirtually()) {
			return false;
		}

		// Check if we're in semi-offline mode
		const isSemiOffline = this.isOfflineVirtually();
		if (isSemiOffline) {
			return false;
		}

		// If none of the above conditions are true, check the browser's online status
		return navigator.onLine;
	};

	this.getShell = function () {
		return _oShell;
	};

	this.initShell = function () {

		// Save global busy indicator state to be able to determine when the app
		// is busy - e.g. for UI testing.
		sap.ui.core.BusyIndicator.attachOpen(function(Event){
			_bBusy = true;
		});
		sap.ui.core.BusyIndicator.attachClose(function(Event){
			_bBusy = false;
		});

		_oShell = new sap.ui.unified.Shell({
			showPane: false,
			header: [
				new sap.m.OverflowToolbar("exf-toolbar",{
					design: "Transparent",
					content: [
						new sap.m.Button('exf_menu_toggle_btn', {
							icon: "sap-icon://menu2",
							layoutData: new sap.m.OverflowToolbarLayoutData({ priority: "NeverOverflow" }),
							press: function () {
								// toggle the expanded state on the nav sidebar
								var oDomNav = document.querySelector('.sapTntSideNavigation');
								var oNavMenu = oDomNav && sap.ui.getCore().byId(oDomNav.id);
								if (!oNavMenu) return;
								oNavMenu.setExpanded(!oNavMenu.getExpanded());
							}
						}),
						new sap.m.OverflowToolbarButton("exf-home", {
							text: "{i18n>WEBAPP.SHELL.HOME.TITLE}",
							icon: "sap-icon://home",
							press: function (oEvent) {
								var oBtn = oEvent.getSource();
								sap.ui.core.BusyIndicator.show(0);
								window.location.href = oBtn.getModel().getProperty('/_app/home_url');
							}
						}),
						new sap.m.ToolbarSpacer(),
            new sap.m.Breadcrumbs("exf-breadcrumbs", {
              layoutData: new sap.m.OverflowToolbarLayoutData({ priority: "NeverOverflow" }),
              currentLocationText: "{/_breadcrumbs/current_title}"
            }).bindAggregation("links", "/_breadcrumbs/crumbs", function(sId, oContext) {

              return new sap.m.Link(sId, {
                text: oContext.getProperty("title"),
                press: function (oEvent) {
                  const steps = oContext.getProperty("steps")
                  if (steps != null) {
                    const oHistory = sap.ui.core.routing.History.getInstance();
                    // moving the history pointer to our selected location
                    // because "window.history.go(steps);" can only decrease it by 1 at a time:
                    oHistory.iHistoryPosition -= Math.abs(steps) - 1;

                    window.history.go(steps);
                  } else {
                    window.location.href = oContext.getProperty("url");
                  }
                }
              });
            }),
						new sap.m.ToolbarSpacer(),
						new sap.m.Button("exf-network-indicator", {
							icon: function () { return exfLauncher.isOnline() ? "sap-icon://connected" : "sap-icon://disconnected" }(),
							text: "{/_network/queueCnt} / {/_network/syncErrorCnt}",
							layoutData: new sap.m.OverflowToolbarLayoutData({ priority: "NeverOverflow" }),
							press: _oLauncher.showOfflineMenu
						}),
					]
				})
			],
			content: [

			]
		})
			.setModel(new sap.ui.model.json.JSONModel({
				_network: {
					online: navigator.onLine,
					queueCnt: 0,
					syncErrorCnt: 0,
					deviceId: exfPWA.getDeviceId()
				},
        _breadcrumbs: {
          current_title: "",
          crumbs: []
        },
			}));

    // Re-render survey when something happens to the parent - e.g. if the tab is hidden/shown.
    // Survey actually disappeared even if another tab was added before the survey tab!

    sap.ui.core.ResizeHandler.register(sap.ui.getCore().byId("exf-toolbar"), function () {});

		return _oShell;
	};

	/**
	 * Returns TRUE if the global busy indicator is shown and FALSE otherwise
	 * 
	 * @returns {boolean}
	 */
	this.isBusy = function() {
		return _bBusy === true
			|| $('#exf-loader').is(':visible') !== false
			|| (sap.ui.core.BusyIndicator.oDomRef !== null && sap.ui.core.BusyIndicator.oDomRef.classList.length > 0);
	};

	this.setAppMenu = function (oControl) {
		_oAppMenu = oControl;
	};

	this.getHistory = function () {
		return _oHistory;
	};

	this.contextBar = function () {
		var _oComponent = {};
		var _oContextBar = {
			traceJs: false,
			lastContextRefresh: null,
			init: function (oComponent) {
				_oComponent = oComponent;

				// Give the shell the translation model of the component
				_oShell.setModel(oComponent.getModel('i18n'), 'i18n');

				oComponent.getRouter().attachRouteMatched(function (oEvent) {
					_oContextBar.load();
				});

				$(document).ajaxSuccess(function (event, jqXHR, ajaxOptions, data) {
					var extras = {};
					if (jqXHR.responseJSON) {
						extras = jqXHR.responseJSON.extras;
					} else {
						try {
							extras = $.parseJSON(jqXHR.responseText).extras;
						} catch (err) {
							extras = {};
						}
					}
					if (extras && extras.ContextBar) {
						_oContextBar.refresh(extras.ContextBar);
					} else {
						_oContextBar.load();
					}
				});
				oComponent.getPWA().updateQueueCount();
				oComponent.getPWA().updateErrorCount();
				
				$(document).on('debugShowJsTrace', function(oEvent) {
					_oLauncher.showErrorLog();
					oEvent.preventDefault();
				});
			},

			getComponent: function () {
				return _oComponent;
			},

			load: function (delay) {
				if (delay === undefined) delay = 100;

				// Don't refresh if configured wait-time not passed yet
				if (_oContextBar.lastContextRefresh !== null && (Math.abs((new Date()) - _oContextBar.lastContextRefresh)) < _oConfig.contextBar.refreshWaitSeconds * 1000) {
					return;
				}
				_oContextBar.lastContextRefresh = new Date();

				// Do not really refresh if offline or semi-offline
				if (navigator.onLine === false || exfLauncher.isOfflineVirtually()) {
					_oContextBar.refresh({});
					return;
				}
				/* FIXME #performance this caused a memory leak for some installations
				window._oNetworkSpeedPoller = setInterval(function () {
					// IDEA: Measure network speed every 5 seconds 
					listNetworkStats();
				}, 1000 * 5);*/

				setTimeout(function () {
					// IDEA had to disable adding context bar extras to every request due to
					// performance issues. This will be needed for asynchronous contexts like
					// user messaging, external task management, etc. So put the line back in
					// place to fetch context data with every request instead of a dedicated one.
					// if ($.active == 0 && $('#contextBar .context-bar-spinner').length > 0){
					//if ($('#contextBar .context-bar-spinner').length > 0){

					$.ajax({
						type: 'GET',
						url: 'api/ui5/' + _oLauncher.getPageId() + '/context',
						dataType: 'json',
						success: function (data, textStatus, jqXHR) {
							_oContextBar.refresh(data);
						},
						error: function (jqXHR, textStatus, errorThrown) {
							_oContextBar.refresh({});
						}
					});
					/*} else {
						_oContextBar.load(delay*3);
					}*/
				}, delay);
			},

			refresh: function (data) {
				var oToolbar = _oShell.getHeader();
				var aItemsOld = _oShell.getHeader().getContent();
				var iItemsIndex = 5;
				var oControl = {};
				var oCtxtData = {};
				var sColor;

				_oContextBar.data = data;
				oToolbar.removeAllContent();

				for (var i = 0; i < aItemsOld.length; i++) {
					oControl = aItemsOld[i];
					if (i < iItemsIndex || oControl.getId() == 'exf-network-indicator' || oControl.getId() == 'exf-pagetitle' || oControl.getId() == 'exf-user-icon') {
						oToolbar.addContent(oControl);
					} else {
						oControl.destroy();
					}
				}

				for (var id in data) {
					oCtxtData = data[id];
					sColor = oCtxtData.color ? 'background-color:' + oCtxtData.color + ' !important;' : '';
					if (oCtxtData.context_alias === 'exface.Core.PWAContext') {
						_oShell.getModel().setProperty("/_network/syncErrorCnt", parseInt(oCtxtData.indicator));
						continue;
					}
					if (oCtxtData.context_alias === 'exface.Core.NotificationContext') {
						_oContextBar.hideAnnouncement();
						(oCtxtData.announcements || []).forEach(function(oMsg) {
							_oContextBar.showAnnouncement(oMsg.text, oMsg.type, oMsg.icon);
						});
					}
					if (oCtxtData.visibility === 'hide_allways') {
						continue;
					}
					oToolbar.insertContent(
						new sap.m.Button(id, {
							icon: oCtxtData.icon,
							tooltip: oCtxtData.hint,
							text: oCtxtData.indicator,
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								_oContextBar.showMenu(oButton);
							}
						})
						.data('widget', oCtxtData.bar_widget_id, true)
						.data('context', oCtxtData.context_alias, true),
						iItemsIndex
					);

					// Handle JS tracer if it is enabled in the DebugContext
					if (id.endsWith('CoreDebugContext')) {
						_oContextBar._setupTracer(oCtxtData);
					}
				}
				_oLauncher.contextBar.getComponent().getPWA().updateQueueCount();
				_oLauncher.contextBar.getComponent().getPWA().updateErrorCount();
			},

			/**
			 * 
			 * @param {string} sText 
			 * @param {string} sType 
			 * @param {string} sIcon 
			 * @return void
			 */
			showAnnouncement: function(sText, sType, sIcon) {
				var sHeight = '1.75rem';
				var iHeightTotal = '0';
				var sClass = 'sapMMsgStripInformation';
				var sIconCls = sIcon ? (sIcon.startsWith('fa-') ? 'fa ' + sIcon : sIcon) : 'fa fa-info-circle';
				var jqStrip;
				switch (sType.toLowerCase()) {
					case 'warning':
						sClass = 'sapMMsgStripWarning';
						sIconCls = 'fa fa-exclamation-triangle';
						break;
					case 'error':
						sClass = 'sapMMsgStripError';
						sIconCls = 'fa fa-times-circle';
						break;
					case 'success':
						sClass = 'sapMMsgStripSuccess';
						sIconCls = 'fa fa-check-circle-o';
						break;
					case 'hint':
						sClass = 'sapMMsgStripInformation';
						sIconCls = 'fa fa-exclamation-circle';
						break;
					case 'info':
					default:
						break;
				}
				jqStrip = $('<div class="exf-announcement sapMTB-Transparent-CTX ' + sClass + ' style="height: ' + sHeight + '""><div class="sapMLabel" style="line-height: ' + sHeight + '"><i class="' + sIconCls + '"></i> ' + sText + '</div></div>');
				iHeightTotal = $('#exf-announcements').append(jqStrip).outerHeight();
				$('.exf-launcher').css({'height': 'calc(100% - ' + iHeightTotal + 'px)'});
				$('.sapUiUfdShell.sapUiUfdShellCurtainHidden .sapUiUfdShellCurtain').hide();
			},

			/**
			 * @return void
			 */
			hideAnnouncement: function() {
				$('#exf-announcements').empty();
				$('.exf-launcher').css({'height': '100%'});
				$('.sapUiUfdShell.sapUiUfdShellCurtainHidden .sapUiUfdShellCurtain').show();
			},

			_setupTracer: function(oCtxtData) {
				if (oCtxtData.indicator !== 'OFF' && oCtxtData.indicator.includes('F')) {
					if (_oContextBar.traceJs !== true) {
						_oContextBar.traceJs = true;
						exfLauncher.enableJsTracing();
					}
				} else {
					if (_oContextBar.traceJs === true) {
						_oContextBar.traceJs = false;
						exfLauncher.disableJsTracing();
					}
				}
			},

			showMenu: function (oButton) {
				var sPopoverId = oButton.data('widget') + "_popover";
				var iPopoverWidth = sPopoverId === 'ContextBar_UserExfaceCoreNotificationContext' ? "500px" : "350px";
				var iPopoverHeight = "300px";
				var oPopover = sap.ui.getCore().byId(sPopoverId);
				if (oPopover) {
					return;
				} else {
					oPopover = new sap.m.ResponsivePopover(sPopoverId, {
						title: oButton.getTooltip(),
						placement: "Bottom",
						busy: true,
						contentWidth: iPopoverWidth,
						contentHeight: iPopoverHeight,
						horizontalScrolling: false,
						afterClose: function (oEvent) {
							oEvent.getSource().destroy();
						},
						content: [
							new sap.m.NavContainer({
								pages: [
									new sap.m.Page({
										showHeader: false,
										content: [

										]
									})
								]
							})
						],
						endButton: [
							new sap.m.Button({
								icon: 'sap-icon://font-awesome/close',
								text: "{i18n>CONTEXT.BUTTON.CLOSE}",
								press: function () { oPopover.close(); },
							})

						]

					})
						.setModel(oButton.getModel())
						.setModel(oButton.getModel('i18n'), 'i18n')
						.setBusyIndicatorDelay(0);
					oPopover.addStyleClass('exf-context-popup');

					jQuery.sap.delayedCall(0, this, function () {
						oPopover.openBy(oButton);
					});
				}

				$.ajax({
					type: 'GET',
					url: 'api/ui5',
					dataType: 'script',
					data: {
						action: 'exface.Core.ShowContextPopup',
						resource: _oLauncher.getPageId(),
						element: oButton.data('widget')
					},
					success: function (data, textStatus, jqXHR) {
						var viewMatch = data.match(/sap.ui.jsview\("(.*)"/i);
						if (viewMatch !== null) {
							var view = viewMatch[1];
						} else {
							_oComponent.showAjaxErrorDialog(jqXHR);
						}

						var oPopoverPage = oPopover.getContent()[0].getPages()[0];
						var oView = _oComponent.runAsOwner(function () {
							return sap.ui.view({ type: sap.ui.core.mvc.ViewType.JS, viewName: view });
						});
						var oEvent;

						var oNavInfoOpen = {
							from: null,
							fromId: null,
							to: oView || null,
							toId: (oView ? oView.getId() : null),
							firstTime: true,
							isTo: false,
							isBack: false,
							isBackToTop: false,
							isBackToPage: false,
							direction: "initial"
						};

						oPopoverPage.removeAllContent();

						// Before-open events
						oNavInfoOpen.to = oView;
						oNavInfoOpen.toId = oView.getId();

						oEvent = jQuery.Event("BeforeShow", oNavInfoOpen);
						oEvent.srcControl = oPopover.getContent()[0];
						oEvent.data = {};
						oEvent.backData = {};
						oView._handleEvent(oEvent);

						oView.fireBeforeRendering();
						
						// Populate the popover
						oPopoverPage.addContent(oView);

						// After-open events
						oEvent = jQuery.Event("AfterShow", oNavInfoOpen);
						oEvent.srcControl = oPopover.getContent()[0];
						oEvent.data = {};
						oEvent.backData = {};
						oView._handleEvent(oEvent);

						oView.fireAfterRendering();

						// TODO need close-events here?

						oPopover.setBusy(false);

					},
					error: function (jqXHR, textStatus, errorThrown) {
						oButton.setBusy(false);
						_oComponent.showAjaxErrorDialog(jqXHR);
					}
				});
			}
		};
		return _oContextBar;
	}();

	this.getPageId = function () {
		return $("meta[name='page_id']").attr("content");
	};

	this.registerNetworkSpeed = function (speedMbps) {
		const minusOneIndex = _speedHistory.indexOf(null);
		if (minusOneIndex !== -1) {
			_speedHistory[minusOneIndex] = speedMbps;
		} else {
			_speedHistory.shift();
			_speedHistory.push(speedMbps);
		}
	};

	this.calculateSpeedTier = function (speedMbps) {
		let speedClass;
		switch (true) {
			case speedMbps == '-':
			case speedClass == 0:
				speedClass = '-';
				break;
			case speedMbps < 0.3:
				speedClass = '2G';
				break;
			case speedMbps < 5:
				speedClass = '3G';
				break;
			case speedMbps < 50:
				speedClass = '4G';
				break;
			default:
				speedClass = '5G';
				break;
		}
		return speedClass;
	};

	this.toggleOnlineIndicator = function ({ lowSpeed = false } = {}) {
		const isOnline = navigator.onLine && !lowSpeed;

		sap.ui.getCore().byId('exf-network-indicator').setIcon(isOnline ? 'sap-icon://connected' : 'sap-icon://disconnected');
		_oShell.getModel().setProperty("/_network/online", isOnline);
		if (exfLauncher.isOnline()) {
			// TODO Why is exfLauncher.isOnline() true when this method is called with lowSpeed = true?
			// Shouldn't the entire app go offline at this moment?
			if (isOnline && exfLauncher.isOnline()) {
				_oLauncher.contextBar.load();
				if (exfPWA) {
					// TODO when we switch back from forced-offline to regular, it we should probably not resync all offline
					// data because that is likely to happen in potentially low-speed situations. How to detect this?
					exfPWA.actionQueue.syncOffline();
				}
			}
		}
	};

	this.showMessageToast = function (message, duration) {
		// Set default duration to 3000 milliseconds (3 seconds)

		var defaultDuration = 3000;

		// If a duration is provided, use it; otherwise, use the default duration
		var toastDuration = duration || defaultDuration;

		// Show the MessageToast with the customized duration
		sap.m.MessageToast.show(message, {
			duration: toastDuration,
			width: "20em"  // Increase the width of the message (optional)
		});
	};

	this.calculateSpeed = function () {
		const avarageSpeed = navigator?.connection?.downlink ? `${navigator?.connection?.downlink} Mbps` : '-';
		const speedTier = navigator?.connection?.effectiveType ? navigator?.connection?.effectiveType.toUpperCase() : '-';

		let customSpeed;
		if (_speedHistory.indexOf(null) === -1) {
			customSpeed = _speedHistory[_speedHistory.length - 1];
		} else if (_speedHistory.indexOf(null) === 0) {
			customSpeed = 0;
		} else {
			customSpeed = _speedHistory[_speedHistory.indexOf(null) - 1];
		}

		const customSpeedAvarageLabel = customSpeed ? `${customSpeed} Mbps` : '-';
		const customSpeedTier = exfLauncher.calculateSpeedTier(customSpeed);

		return {
			avarageSpeed,
			speedTier,
			customSpeed,
			customSpeedAvarageLabel,
			customSpeedTier
		};
	}

	/**
	 * Shows a dialog with offline storage info (quota, preload data summary, etc.)
	 * 
	 * @return void
	 */
	this.showStorage = async function (oEvent) {

		var dialog = new sap.m.Dialog({
			title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_HEADER}",
			icon: "sap-icon://unwired",
			afterClose: function (oEvent) {
				oEvent.getSource().destroy();
				if (_oSpeedStatusDialogInterval) {
					clearInterval(_oSpeedStatusDialogInterval);
				}
			}
		});
		var oButton = oEvent.getSource();
		var button = new sap.m.Button({
			icon: 'sap-icon://font-awesome/close',
			text: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_CLOSE}",
			press: function () { dialog.close(); },
		});
		dialog.addButton(button);
		let list = new sap.m.List({});
		//check if possible to acces storage (means https connection)
		if (navigator.storage && navigator.storage.estimate) {
			var promise = navigator.storage.estimate()
				.then(function (estimate) {
					list = new sap.m.List({
						items: [
							new sap.m.GroupHeaderListItem({
								title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_OVERVIEW}",
								upperCase: false
							}),
							new sap.m.DisplayListItem({
								label: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_TOTAL}",
								value: Number.parseFloat(estimate.quota / 1024 / 1024).toFixed(2) + ' MB'
							}),
							new sap.m.DisplayListItem({
								label: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_USED}",
								value: Number.parseFloat(estimate.usage / 1024 / 1024).toFixed(2) + ' MB'
							}),
							new sap.m.DisplayListItem({
								label: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_PERCENTAGE}",
								value: Number.parseFloat(100 / estimate.quota * estimate.usage).toFixed(2) + ' %'
							})
						]
					});
					if (estimate.usageDetails) {
						list.addItem(new sap.m.GroupHeaderListItem({
							title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_DETAILS}",
							upperCase: false
						}));
						Object.keys(estimate.usageDetails).forEach(function (key) {
							list.addItem(new sap.m.DisplayListItem({
								label: key,
								value: Number.parseFloat(estimate.usageDetails[key] / 1024 / 1024).toFixed(2) + ' MB'
							})
							);
						});
					}
				})
				.catch(function (error) {
					console.error(error);
					list.addItem(new sap.m.GroupHeaderListItem({
						title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_ERROR}",
						upperCase: false
					}))
				});
			//wait for the promise to resolve
			await promise;
		}


		const {
			avarageSpeed,
			speedTier,
			customSpeedAvarageLabel,
			customSpeedTier
		} = exfLauncher.calculateSpeed();

		/* $("#sparkline").sparkline([10.4,3,6,12,], {
			type: 'line',
			width: '200px',
			height: '100px',
			chartRangeMin: 0,
			drawNormalOnTop: false}); */

		const oBrowserCurrentSpeedTierItem = new sap.m.DisplayListItem('browser_speed_tier_display', {
			label: "{i18n>WEBAPP.SHELL.NETWORK_SPEED_TIER}",
			value: speedTier,
		});

		const oBrowserCurrentSpeedItem = new sap.m.DisplayListItem('browser_speed_display', {
			label: "{i18n>WEBAPP.SHELL.NETWORK_SPEED}",
			value: avarageSpeed,
		});

		const oCustomCurrentSpeedTierItem = new sap.m.DisplayListItem('custom_speed_tier_display', {
			label: "{i18n>WEBAPP.SHELL.NETWORK_SPEED_TIER_CUSTOM}",
			value: customSpeedTier,
		});

		const oCustomCurrentSpeedItem = new sap.m.DisplayListItem('custom_speed_display', {
			label: "{i18n>WEBAPP.SHELL.NETWORK_SPEED_CUSTOM}",
			value: customSpeedAvarageLabel,
		});

		_oSpeedStatusDialogInterval = setInterval(() => {
			const {
				avarageSpeed,
				speedTier,
				customSpeedAvarageLabel,
				customSpeedTier
			} = exfLauncher.calculateSpeed();

			sap.ui.getCore().byId('browser_speed_tier_display').setValue(speedTier);
			sap.ui.getCore().byId('browser_speed_display').setValue(avarageSpeed);
			sap.ui.getCore().byId('custom_speed_tier_display').setValue(customSpeedTier);
			sap.ui.getCore().byId('custom_speed_display').setValue(customSpeedAvarageLabel);
		}, 1000);


		[
			new sap.m.GroupHeaderListItem({
				title: "{i18n>WEBAPP.SHELL.NETWORK_SPEED_TITLE}",
				upperCase: false
			}),
			oBrowserCurrentSpeedTierItem,
			oBrowserCurrentSpeedItem,
			oCustomCurrentSpeedTierItem,
			oCustomCurrentSpeedItem,
			new sap.m.GroupHeaderListItem({
				title: "{i18n>WEBAPP.SHELL.NETWORK_HEALTH}",
				upperCase: false,
			}),
			new sap.m.CustomListItem({
				content: new sap.ui.core.HTML('network_speed_chart_wrapper', {
					content: '<div id="network_speed_chart"></div>',
					afterRendering: function () {
						setInterval(function () {
							$("#network_speed_chart").sparkline(_speedHistory, {
								type: 'line',
								width: '100%',
								height: '100px',
								chartRangeMin: 0,
								chartRangeMax: 10,
								drawNormalOnTop: false,
							});
						}, 1000);
					}
				})
			})

		].forEach(item => list.addItem(item));


		list.addItem(new sap.m.GroupHeaderListItem({
			title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_SYNCED}",
			upperCase: false
		}));

		var oTable = new sap.m.Table({
			autoPopinMode: true,
			fixedLayout: false,
			headerToolbar: [
				new sap.m.OverflowToolbar({
					design: "Transparent",
					content: [
						new sap.m.ToolbarSpacer(),
						new sap.m.Button({
							text: "{i18n>WEBAPP.SHELL.PWA.MENU_SYNC}",
							tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_SYNC_TOOLTIP}",
							icon: "sap-icon://synchronize",
							enabled: "{/_network/online}",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var oTable = oButton.getParent().getParent();
								oTable.setBusy(true);
								_oLauncher.syncOffline(oEvent)
									.then(function () {
										_oLauncher.loadPreloadInfo(oTable);
										oTable.setBusy(false);
									})
									.catch(function () {
										oTable.setBusy(false);
									})
							},
						}),
						new sap.m.Button({
							text: "{i18n>WEBAPP.SHELL.PWA.MENU_RE_SYNC}",
							tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_RE_SYNC_TOOLTIP}",
							icon: "sap-icon://synchronize",
							enabled: "{/_network/online}",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var oTable = oButton.getParent().getParent();
								oTable.setBusy(true);
								_oLauncher.reSyncOffline(oEvent)
									.then(function () {
										_oLauncher.loadPreloadInfo(oTable);
										oTable.setBusy(false);
									})
									.catch(function () {
										oTable.setBusy(false);
									})
							},
						}),
						new sap.m.Button({
							text: "{i18n>WEBAPP.SHELL.PWA.MENU_RESET}",
							tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_RESET_TOOLTIP}",
							icon: "sap-icon://sys-cancel",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var oTable = oButton.getParent().getParent();
								oTable.setBusy(true);
								_oLauncher.clearPreload(oEvent)
									.then(function () {
										_oLauncher.loadPreloadInfo(oTable);
										oTable.setBusy(false);
									})
									.catch(function () {
										oTable.setBusy(false);
									})
							},
						}),
					]
				})
			],
			columns: [
				new sap.m.Column({
					header: new sap.m.Label({
						text: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_OBJECT}"
					}),
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: new sap.m.Label({
						text: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_DATASETS}"
					}),
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				,
				new sap.m.Column({
					header: new sap.m.Label({
						text: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_LAST_SYNC}"
					}),
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				})
			]
		}).setBusyIndicatorDelay(0);
		dialog.addContent(list);
		dialog.addContent(oTable);

		promise = _oLauncher.loadPreloadInfo(oTable)
			.catch(function (error) {
				console.error(error);
				list.addItem(new sap.m.GroupHeaderListItem({
					title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_ERROR}",
					upperCase: false
				}))
				dialog.addContent(list);
			})
		//wait for the promise to resolve
		await promise;
		dialog.setModel(oButton.getModel())
		dialog.setModel(oButton.getModel('i18n'), 'i18n');
		dialog.open();
		return;
	};

	/**
	 * Loads information about the preload data (number of items, sync time) into
	 * the passed sap.m.Table
	 * 
	 * @reutn void
	 */
	this.loadPreloadInfo = function (oTable) {
		return exfPWA.data.getTable().toArray()
			.then(function (dbContent) {
				oTable.removeAllItems();
				dbContent.forEach(function (element) {
					var oRow = new sap.m.ColumnListItem();
					oRow.addCell(new sap.m.Text({ text: element.object_name }));
					if (element.rows) {
						oRow.addCell(new sap.m.Text({ text: element.rows.length }));
						oRow.addCell(new sap.m.Text({ text: new Date(element.last_sync).toLocaleString() }));
					} else {
						oRow.addCell(new sap.m.Text({ text: '0' }));

						oRow.addCell(new sap.m.Text({ text: '{i18n>WEBAPP.SHELL.NETWORK.STORAGE_NOT_SYNCED}' }));
					}
					oTable.addItem(oRow);
				});
			})
	}

	/**
	 * Shows a popover with pending offline actions for a data item
	 * 
	 * @return void
	 */
	this.showOfflineQueuePopoverForItem = function (sObjectAlias, sUidColumn, sUidValue, oTrigger) {
		var oPopover = new sap.m.Popover({
			title: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_WAITING_ACTIONS}",
			placement: "Right",
			afterClose: function (oEvent) {
				oEvent.getSource().destroy();
			},
			content: [
				new sap.m.Table({
					autoPopinMode: true,
					fixedLayout: false,
					columns: [
						new sap.m.Column({
							header: [
								new sap.m.Label({
									text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_ACTION}"
								})
							],
							popinDisplay: sap.m.PopinDisplay.Inline,
							demandPopin: true,
						}),
						new sap.m.Column({
							header: [
								new sap.m.Label({
									text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_TRIGGERED}'
								})
							],
							popinDisplay: sap.m.PopinDisplay.Inline,
							demandPopin: true,
						}),
						new sap.m.Column({
							header: [
								new sap.m.Label({
									text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_STATUS}'
								}),
							],
							popinDisplay: sap.m.PopinDisplay.Inline,
							demandPopin: true,
						}),
						new sap.m.Column({
							header: [
								new sap.m.Label({
									text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_TRIES}'
								}),
							],
							popinDisplay: sap.m.PopinDisplay.Inline,
							demandPopin: true,
						})
					],
					items: {
						path: "queueModel>/rows",
						template: new sap.m.ColumnListItem({
							cells: [new sap.m.Text({
								text: "{queueModel>effect_name}"
							}),
							new sap.m.Text({
								text: "{queueModel>triggered}"
							}),
							new sap.m.Text({
								text: "{queueModel>status}"
							}),
							new sap.m.Text({
								text: "{queueModel>tries}"
							})
							]
						})
					}
				})
			]
		})
			.setModel(oTrigger.getModel())
			.setModel(oTrigger.getModel('i18n'), 'i18n');

		exfPWA.actionQueue.getEffects(sObjectAlias)
			.then(function (aEffects) {
				var oData = {
					rows: []
				};
				aEffects.forEach(function (oEffect) {
					var oRow = oEffect.offline_queue_item;
					// TODO filter over sUidColumn, sUidValue passed to the method here! Otherwise
					// it shows all actions for the object, not only those effecting the row!
					oRow.effect_name = oEffect.name;
					oData.rows.push(oRow);
				});
				oPopover.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'queueModel');
			})
			.catch(function (data) {
				// TODO
			});

		jQuery.sap.delayedCall(0, this, function () {
			oPopover.openBy(oTrigger);
		});

		return;
	};

	/**
	 * Shows a dialog with a table showing currently queued offline actions (not yet sent
	 * to the server).
	 * 
	 * @param {sap.ui.base.Event} [oEvent]
	 * 
	 * @return void
	 */
	this.showOfflineQueue = function (oEvent) {
		var oButton = oEvent.getSource();
		var oTable = new sap.m.Table({
			fixedLayout: false,
			autoPopinMode: true,
			mode: sap.m.ListMode.MultiSelect,
			headerToolbar: [
				new sap.m.OverflowToolbar({
					design: "Transparent",
					content: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_WAITING_ACTIONS}"
						}),
						new sap.m.ToolbarSpacer(),
						new sap.m.Button({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_DELETE}",
							icon: "sap-icon://delete",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var table = oButton.getParent().getParent()
								var selectedItems = table.getSelectedItems();
								if (selectedItems.length === 0) {
									var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.NO_SELECTION");
									_oLauncher.showMessageToast(text);
									return;
								}
								oButton.setBusyIndicatorDelay(0).setBusy(true);
								var selectedIds = [];
								selectedItems.forEach(function (item) {
									var bindingObj = item.getBindingContext('queueModel').getObject()
									selectedIds.push(bindingObj.id);
								})

								var confirmDialog = new sap.m.Dialog({
									title: "{i18n>WEBAPP.SHELL.NETWORK.CONFIRM_HEADER}",
									stretch: false,
									type: sap.m.DialogType.Message,
									content: [
										new sap.m.Text({
											text: '{i18n>WEBAPP.SHELL.NETWORK.CONFIRM_TEXT}'
										})
									],
									beginButton: new sap.m.Button({
										text: "{i18n>WEBAPP.SHELL.NETWORK.CONFIRM_YES}",
										type: sap.m.ButtonType.Emphasized,
										press: function (oEvent) {
											exfPWA.actionQueue.deleteAll(selectedIds)
												.then(function () {
													_oLauncher.contextBar.getComponent().getPWA().updateQueueCount()
												})
												.then(function () {
													confirmDialog.close();
													oButton.setBusy(false);
													var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.ENTRIES_DELETED");
													_oLauncher.showMessageToast(text);
													return exfPWA.actionQueue.get('offline')
												})
												.then(function (data) {
													var oData = {};
													oData.data = data;
													oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'queueModel');
													return;
												})
										}
									}),
									endButton: new sap.m.Button({
										text: "{i18n>WEBAPP.SHELL.NETWORK.CONFIRM_NO}",
										type: sap.m.ButtonType.Default,
										press: function (oEvent) {
											oButton.setBusy(false);
											confirmDialog.close();
										}
									})
								})
									.setModel(oButton.getModel('i18n'), 'i18n');

								confirmDialog.open();
							}
						}),
						new sap.m.Button('exf-queue-sync', {
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_SYNC}",
							icon: "sap-icon://synchronize",
							enabled: "{= ${/_network/online} > 0 ? true : false }",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var table = oButton.getParent().getParent()
								var selectedItems = table.getSelectedItems();
								if (selectedItems.length === 0) {
									var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.NO_SELECTION");
									_oLauncher.showMessageToast(text);
									return;
								}
								oButton.setBusyIndicatorDelay(0).setBusy(true);
								var selectedIds = [];
								selectedItems.forEach(function (item) {
									var bindingObj = item.getBindingContext('queueModel').getObject()
									selectedIds.push(bindingObj.id);
								})
								exfPWA.actionQueue.syncIds(selectedIds)
									.then(function () {
										_oLauncher.contextBar.getComponent().getPWA().updateQueueCount();
										_oLauncher.contextBar.getComponent().getPWA().updateErrorCount();
									})
									.then(function () {
										oButton.setBusy(false);
										var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.SYNC_ACTIONS_COMPLETE");
										_oLauncher.showMessageToast(text);
										return exfPWA.actionQueue.get('offline')
									})
									.then(function (data) {
										var oData = {};
										oData.data = data;
										oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'queueModel');
										return;
									})
									.catch(function (error) {
										console.error('Offline action sync error: ', error);
										_oLauncher.contextBar.getComponent().getPWA().updateQueueCount()
											.then(function () {
												_oLauncher.contextBar.getComponent().getPWA().updateErrorCount();
												oButton.setBusy(false);
												_oLauncher.contextBar.getComponent().showErrorDialog(error, '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_HEADER}');
												return exfPWA.actionQueue.get('offline')
											})
											.then(function (data) {
												var oData = {};
												oData.data = data;
												oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'queueModel');
												return;
											})
										return;
									})
							},
						}),
						new sap.m.Button({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_EXPORT}",
							icon: "sap-icon://download",
							press: function (oEvent) {
								var oButton = oEvent.getSource();
								var table = oButton.getParent().getParent()
								var selectedItems = table.getSelectedItems();
								if (selectedItems.length === 0) {
									var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.NO_SELECTION");
									_oLauncher.showMessageToast(text);
									return;
								}
								oButton.setBusyIndicatorDelay(0).setBusy(true);
								var selectedIds = [];
								selectedItems.forEach(function (item) {
									var bindingObj = item.getBindingContext('queueModel').getObject()
									selectedIds.push(bindingObj.id);
								})
								exfPWA.actionQueue.getByIds(selectedIds)
									.then(function (aQItems) {
										var oData = {
											deviceId: _pwa.getDeviceId(),
											actions: aQItems
										};
										var sJson = JSON.stringify(oData);
										var date = new Date();
										var dateString = date.toISOString();
										dateString = dateString.substr(0, 16);
										dateString = dateString.replace(/-/gi, "");
										dateString = dateString.replace("T", "_");
										dateString = dateString.replace(":", "");
										oButton.setBusyIndicatorDelay(0).setBusy(false);
										exfPWA.download(sJson, 'offlineActions_' + dateString, 'application/json')
										var text = exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.NETWORK.ENTRIES_EXPORTED");
										_oLauncher.showMessageToast(text);
										return;
									})
									.catch(function (error) {
										console.error(error);
										oButton.setBusyIndicatorDelay(0).setBusy(false);
										_oLauncher.contextBar.getComponent().showErrorDialog('{i18n>WEBAPP.SHELL.NETWORK.CONSOLE}', '{i18n>WEBAPP.SHELL.NETWORK.ENTRIES_EXPORTED_FAILED}');
										return;
									})
							}
						})
					]
				})
			],
			footerText: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_DEVICE}: {/_network/deviceId}',
			columns: [
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_OBJECT}"
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_ACTION}"
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_TRIGGERED}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_STATUS}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_TRIES}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_ID}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
			],
			items: {
				path: "queueModel>/data",
				template: new sap.m.ColumnListItem({
					cells: [
						new sap.m.Text({
							text: "{queueModel>object_name}"
						}),
						new sap.m.Text({
							text: "{queueModel>action_name}"
						}),
						new sap.m.Text({
							text: "{queueModel>triggered}"
						}),
						new sap.m.Text({
							text: "{queueModel>status}"
						}),
						new sap.m.Text({
							text: "{queueModel>tries}"
						}),
						new sap.m.Text({
							text: "{queueModel>id}"
						}),
					]
				})
			}
		})
			.setModel(oButton.getModel())
			.setModel(oButton.getModel('i18n'), 'i18n');

		exfPWA.actionQueue.get('offline')
			.then(function (data) {
				var oData = {};
				oData.data = data;
				oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'queueModel');
				_oLauncher.contextBar.getComponent().showDialog('{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_HEADER}', oTable, undefined, undefined, true);
			})
			.catch(function (data) {
				var oData = {};
				oData.data = data;
				oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }());
				_oLauncher.contextBar.getComponent().showDialog('{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_HEADER}', oTable, undefined, undefined, true);
			})
	};

	/**
	 * Shows a dialog with a table with offline actions server errors
	 * 
	 * @param {sap.ui.base.Event} [oEvent]
	 * 
	 * @return void
	 */
	this.showOfflineErrors = function (oEvent) {
		var oButton = oEvent.getSource();
		var oTable = new sap.m.Table({
			autoPopinMode: true,
			fixedLayout: false,
			/*headerToolbar: [
				new sap.m.OverflowToolbar({
					design: "Transparent",
					content: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.ERROR_TABLE_ERRORS}"
						})
					]
				})
			],*/
			footerText: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_DEVICE}: {/_network/deviceId}',
			columns: [
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_ID}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_OBJECT}"
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: "{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_ACTION}"
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.QUEUE_TABLE_TRIGGERED}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.ERROR_TABLE_LOGID}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				}),
				new sap.m.Column({
					header: [
						new sap.m.Label({
							text: '{i18n>WEBAPP.SHELL.NETWORK.ERROR_MESSAGE}'
						})
					],
					popinDisplay: sap.m.PopinDisplay.Inline,
					demandPopin: true,
				})
			],
			items: {
				path: "errorModel>/data",
				template: new sap.m.ColumnListItem({
					cells: [
						new sap.m.Text({
							text: "{errorModel>MESSAGE_ID}"
						}),
						new sap.m.Text({
							text: "{errorModel>OBJECT_ALIAS}"
						}),
						new sap.m.Text({
							text: "{errorModel>ACTION_ALIAS}"
						}),
						new sap.m.Text({
							text: "{errorModel>TASK_ASSIGNED_ON}"
						}),
						new sap.m.Text({
							text: "{errorModel>ERROR_LOGID}"
						}),
						new sap.m.Text({
							text: "{errorModel>ERROR_MESSAGE}"
						})
					]
				})
			}
		})
		.setModel(oButton.getModel())
		.setModel(oButton.getModel('i18n'), 'i18n');

		if (_oLauncher.isOnline()) {
			exfPWA.errors.sync()
			.then(function (data) {
				var oData = {};
				if (data.rows !== undefined) {
					var rows = data.rows;
					for (var i = 0; i < rows.length; i++) {
						if (rows[i].TASK_ASSIGNED_ON !== undefined) {
							rows[i].TASK_ASSIGNED_ON = new Date(rows[i].TASK_ASSIGNED_ON).toLocaleString();
						}
					}
					oData.data = rows;
				}
				oTable.setModel(function () { return new sap.ui.model.json.JSONModel(oData) }(), 'errorModel');
				_oLauncher.contextBar.getComponent().showDialog('{i18n>WEBAPP.SHELL.NETWORK.ERROR_TABLE_ERRORS}', oTable, undefined, undefined, true);
			})
		}
	};

	/**
	 * Loads all preload data from the server since the last increment
	 * 
	 * @param {sap.ui.base.Event} [oEvent]
	 * 
	 * @return Promise
	 */
	this.syncOffline = function (oEvent) {
		oButton = oEvent.getSource();
		oButton.setBusyIndicatorDelay(0).setBusy(true);
		var oI18nModel = oButton.getModel('i18n');
		return exfPWA.syncAll()
			.then(function () {
				oButton.setBusy(false);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.NETWORK.SYNC_COMPLETE'));
			})
			.catch(error => {
				console.error(error);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.NETWORK.SYNC_FAILED'));
				oButton.setBusy(false);
			});
	};

	/**
	 * Loads all preload data from the server
	 *
	 * @param {sap.ui.base.Event} [oEvent]
	 *
	 * @return Promise
	 */
	this.reSyncOffline = function (oEvent) {
		oButton = oEvent.getSource();
		oButton.setBusyIndicatorDelay(0).setBusy(true);
		var oI18nModel = oButton.getModel('i18n');
		return exfPWA.syncAll({ doReSync: true })
			.then(function () {
				oButton.setBusy(false);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.NETWORK.SYNC_COMPLETE'));
			})
			.catch(error => {
				console.error(error);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.NETWORK.SYNC_FAILED'));
				oButton.setBusy(false);
			});
	};

	/**
	 * Removes all preload data
	 * 
	 * @param {sap.ui.base.Event} [oEvent]
	 * 
	 * @return Promise
	 */
	this.clearPreload = function (oEvent) {
		var oButton = oEvent.getSource();
		var oI18nModel = oButton.getModel('i18n');
		oButton.setBusyIndicatorDelay(0).setBusy(true);
		return exfPWA
			.reset()
			.then(() => {
				oButton.setBusy(false);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.PWA.CLEARED'));
			}).catch(() => {
				oButton.setBusy(false);
				exfLauncher.showMessageToast(oI18nModel.getProperty('WEBAPP.SHELL.PWA.CLEARED_ERROR}'));
			})
	};



	/**
	 * TODO Could this be oStatus.toString()? But shouldn't we translate these strings?
	 * @returns {string}
	 */
	this.getTitle = function () {
		if (_forceOffline) {
			return "Offline, Forced";
		} else if (!navigator.onLine) {
			return "Offline, No Internet";
		} else if (_autoOffline && _bLowSpeed) {
			return "Offline, Low Speed";
		} else {
			return "Online";
		}
	};

	/**
	 * Shows the offline menu
	 * 
	 * @param {sap.ui.base.Event} [oEvent]
	 * 
	 * @return void
	 */
	this.showOfflineMenu = function (oEvent) {
		_oLauncher.contextBar.getComponent().getPWA().updateQueueCount();
		_oLauncher.contextBar.getComponent().getPWA().updateErrorCount();
		var oButton = oEvent.getSource();
		var oPopover = sap.ui.getCore().byId('exf-network-menu');
		const titleInterval = setInterval(function () {
			oPopover.setTitle(exfLauncher.getTitle());
		}, 1000);
		if (oPopover === undefined) {
			oPopover = new sap.m.ResponsivePopover("exf-network-menu", {
				title: exfLauncher.getTitle(),
				placement: "Bottom",
				content: [
					new sap.m.MessageStrip({
						text: "Offline sync not available.",
						type: "Warning",
						showIcon: true,
						visible: (!exfPWA.isAvailable())
					}).addStyleClass('sapUiSmallMargin'),
					new sap.m.List({
						items: [
							new sap.m.GroupHeaderListItem({
								title: '{i18n>WEBAPP.SHELL.NETWORK.SYNC_MENU}',
								upperCase: false
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.NETWORK.SYNC_MENU_QUEUE} ({/_network/queueCnt})",
								type: "Active",
								icon: "sap-icon://time-entry-request",
								press: _oLauncher.showOfflineQueue,
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.NETWORK.SYNC_MENU_ERRORS} ({/_network/syncErrorCnt})",
								type: "{= ${/_network/online} > 0 ? 'Active' : 'Inactive' }",
								icon: "sap-icon://alert",
								//blocked: "{= ${/_network/online} > 0 ? false : true }", //Deprecated as of version 1.69.
								press: _oLauncher.showOfflineErrors,
							}),
							new sap.m.GroupHeaderListItem({
								title: '{i18n>WEBAPP.SHELL.PWA.MENU}',
								upperCase: false
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.PWA.MENU_SYNC}",
								tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_SYNC_TOOLTIP}",
								icon: "sap-icon://synchronize",
								type: "{= ${/_network/online} > 0 ? 'Active' : 'Inactive' }",
								press: _oLauncher.syncOffline,
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.PWA.MENU_RE_SYNC}",
								tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_SYNC_RE_TOOLTIP}",
								icon: "sap-icon://synchronize",
								type: "{= ${/_network/online} > 0 ? 'Active' : 'Inactive' }",
								press: _oLauncher.reSyncOffline,
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.NETWORK.STORAGE_HEADER}",
								icon: "sap-icon://unwired",
								type: "Active",
								press: _oLauncher.showStorage,
							}),
							new sap.m.StandardListItem({
								title: "{i18n>WEBAPP.SHELL.PWA.MENU_RESET}",
								tooltip: "{i18n>WEBAPP.SHELL.PWA.MENU_RESET_TOOLTIP}",
								icon: "sap-icon://sys-cancel",
								type: "Active",
								press: _oLauncher.clearPreload,
							}),
							new sap.m.GroupHeaderListItem({
								title: "{i18n>WEBAPP.SHELL.NETWORK.OFFLINE_HEADER}",
								upperCase: false
							}),
							new sap.m.CustomListItem({
								content: new sap.m.FlexBox({
									direction: "Row",
									alignItems: "Center",
									customData: new sap.ui.core.CustomData({
										key: "style",
										value: "gap: 1rem;"
									}),
									items: [
										new sap.m.Switch('auto_offline_toggle', {
											// FIXME #auto-offline
											// state: true,
											// enabled: navigator.onLine, // Changed from disabled to enabled
											visible: false,
											state: false,
											enabled: navigator.onLine, // Changed from disabled to enabled
											change: function (oEvent) {
												var oSwitch = oEvent.getSource();
												if (oSwitch.getState()) {
													exfLauncher.toggleAutoOfflineOn();
												} else {
													exfLauncher.toggleAutoOfflineOff();
												}
											}
										}),
										new sap.m.Text({
											// FIXME #auto-offline
											visible: false,
											text: "{i18n>WEBAPP.SHELL.NETWORK_AUTOMATIC_OFFLINE}"
										}),
									],
								}),
							}).addStyleClass("sapUiResponsivePadding"),
							new sap.m.CustomListItem({
								content: new sap.m.FlexBox({
									direction: "Row",
									alignItems: "Center",
									customData: new sap.ui.core.CustomData({
										key: "style",
										value: "gap: 1rem;"
									}),
									items: [
										new sap.m.Switch('force_offline_toggle', {
											state: _forceOffline,
											 enabled: navigator.onLine, // Changed from disabled to enabled
											change: function (oEvent) {
												var oSwitch = oEvent.getSource();
												if (oSwitch.getState()) {
													exfLauncher.toggleForceOfflineOn();
												} else {
													exfLauncher.toggleForceOfflineOff();
												}
											}
										}),
										new sap.m.Text({
											text: "{i18n>WEBAPP.SHELL.NETWORK_FORCE_OFFLINE}"
										}),
									], 
								}).addStyleClass("sapUiResponsivePadding"),
							}),
						]
					})
				],
				endButton: [
					new sap.m.Button({
						icon: 'sap-icon://font-awesome/close',
						text: "{i18n>CONTEXT.BUTTON.CLOSE}",
						press: function () { oPopover.close(); },
					})

				],
				afterClose: function (oEvent) {
					clearInterval(titleInterval);
				}
			})
				.setModel(oButton.getModel())
				.setModel(oButton.getModel('i18n'), 'i18n');

			// Fetch and set the auto offline toggle status 
			if (exfPWA && exfPWA.data && typeof exfPWA.data.getAutoOfflineToggleStatus === 'function') {
				exfPWA.data.getAutoOfflineToggleStatus()
					.then(function (status) {
						var autoOfflineSwitch = sap.ui.getCore().byId('auto_offline_toggle');
						if (autoOfflineSwitch) {
							autoOfflineSwitch.setState(status);
							_autoOffline = status;
						}
					})
					.catch(function (error) {
						console.error('Error fetching auto offline toggle status:', error);
					});
			} else {
				console.error('getAutoOfflineToggleStatus function is not available');
			}
		}

		jQuery.sap.delayedCall(0, this, function () {
			oPopover.openBy(oButton);
		});
	};

	this.showErrorLog = function (oEvent) {
		var oTable = new sap.m.Table({
			autoPopinMode: true,
			fixedLayout: false,
			columns: [
				new sap.m.Column({
					header: new sap.m.Label({ text: "Timestamp" }),
					width: "200px"
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "Level" }),
					width: "100px"
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "Message" })
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "URL" })
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "Stack" })
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "Network Status" }),
					width: "150px"
				}),
				new sap.m.Column({
					header: new sap.m.Label({ text: "Connection Status" }),
					width: "150px"
				})
			],
			items: {
				path: "/errors",
				template: new sap.m.ColumnListItem({
					cells: [
						new sap.m.Text({ text: "{timestamp}" }),
						new sap.m.Text({ text: "{level}" }),
						new sap.m.VBox({
							items: [
								new sap.m.Text({
									text: {
										path: "message",
										formatter: function(text) {
											return text && text.length > 100 ? text.substring(0, 100) + "..." : text;
										}
									}
								}).addStyleClass("sapUiTinyMarginBottom"),
								new sap.m.Link({
									text: {
										path: "message",
										formatter: function(text) {
											return text && text.length > 100 ? "More" : "";
										}
									},
									press: function(oEvent) {
										var oLink = oEvent.getSource();
										var oVBox = oLink.getParent();
										var oText = oVBox.getItems()[0];
										var sFullText = oEvent.getSource().getBindingContext().getObject().message;
										
										if (oLink.getText() === "More") {
											oText.setText(sFullText);
											oLink.setText("Less");
										} else {
											oText.setText(sFullText.substring(0, 100) + "...");
											oLink.setText("More");
										}
									}
								})
							]
						}),
						new sap.m.VBox({
							items: [
								new sap.m.Text({
									text: {
										path: "url",
										formatter: function(text) {
											return text && text.length > 100 ? text.substring(0, 100) + "..." : text;
										}
									}
								}).addStyleClass("sapUiTinyMarginBottom"),
								new sap.m.Link({
									text: {
										path: "url",
										formatter: function(text) {
											return text && text.length > 100 ? "More" : "";
										}
									},
									press: function(oEvent) {
										var oLink = oEvent.getSource();
										var oVBox = oLink.getParent();
										var oText = oVBox.getItems()[0];
										var sFullText = oEvent.getSource().getBindingContext().getObject().url;
										
										if (oLink.getText() === "More") {
											oText.setText(sFullText);
											oLink.setText("Less");
										} else {
											oText.setText(sFullText.substring(0, 100) + "...");
											oLink.setText("More");
										}
									}
								})
							]
						}),
						new sap.m.VBox({
							items: [
								new sap.m.Text({
									text: {
										path: "stack",
										formatter: function(text) {
											return text && text.length > 100 ? text.substring(0, 100) + "..." : text;
										}
									}
								}).addStyleClass("sapUiTinyMarginBottom"),
								new sap.m.Link({
									text: {
										path: "stack",
										formatter: function(text) {
											return text && text.length > 100 ? "More" : "";
										}
									},
									press: function(oEvent) {
										var oLink = oEvent.getSource();
										var oVBox = oLink.getParent();
										var oText = oVBox.getItems()[0];
										var sFullText = oEvent.getSource().getBindingContext().getObject().stack;
										
										if (oLink.getText() === "More") {
											oText.setText(sFullText);
											oLink.setText("Less");
										} else {
											oText.setText(sFullText.substring(0, 100) + "...");
											oLink.setText("More");
										}
									}
								})
							]
						}),
						new sap.m.Text({ text: "{networkStatus}" }),
						new sap.m.Text({ text: "{connectionStatus}" })
					]
				})
			}
		});
	
		// Update error logs and add network status information 
		var updatedErrors = capturedErrors.map(function (error) {
			return exfPWA.getConnectionStatus()
				.then(function (oStatus) {
					return {
						timestamp: error.timestamp,
						level: error.level,
						message: error.message,
						url: error.url,
						stack: error.stack,
						networkStatus: navigator.connection ? navigator.connection.effectiveType : 'Unknown',
						connectionStatus: oStatus.toString()
					};
				});
		});
	
		Promise.all(updatedErrors).then(function (resolvedErrors) {
			var oModel = new sap.ui.model.json.JSONModel({
				errors: resolvedErrors
			});
			oTable.setModel(oModel);
	
			var dialog = new sap.m.Dialog({
				title: 'Error Log',
				contentWidth: "90%",
				contentHeight: "80%",
				content: [oTable],
				buttons: [
					new sap.m.Button({
						text: "Copy Logs",
						press: function() {
							const errorLogs = exfLauncher.getJsErrorLogs();
							const errorLogsString = JSON.stringify(errorLogs, null, 2);

							// Copy to clipboard
							navigator.clipboard.writeText(errorLogsString).then(() => {
								exfLauncher.showMessageToast("Error logs copied to clipboard!");
							}).catch(err => {
								console.error("Failed to copy error logs:", err);
							});
						}
					}),
					new sap.m.Button({
						text: "Dismiss All",
						press: function() {
							// Clear Error List
							capturedErrors = [];
		
							// Update Model 
							oTable.getModel().setProperty("/errors", []); 
							exfLauncher.showMessageToast("Error log cleared");
						}
					}),
					new sap.m.Button({
						text: "Close",
						type: "Emphasized",
						press: function() {
							dialog.close();
						}
					}),
				]
			});
	
			dialog.open();
		});
	};

	this.toggleForceOfflineOn = function () {
		_forceOffline = true;
		_autoOffline = false; // Disable auto offline when force offline is enabled
		_oLauncher.updateNetworkState(true, false);

		_oLauncher.showMessageToast(_oLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.PWA.FORCE_OFFLINE_ON"));
		clearInterval(_oNetworkSpeedPoller);
		_oLauncher.toggleOnlineIndicator({ lowSpeed: true });

		// Update UI elements
		var autoOfflineSwitch = sap.ui.getCore().byId('auto_offline_toggle');
		if (autoOfflineSwitch) {
			autoOfflineSwitch.setState(false);
			autoOfflineSwitch.setEnabled(false);
		}

		// Update force offline switch state
		var forceOfflineSwitch = sap.ui.getCore().byId('force_offline_toggle');
		if (forceOfflineSwitch) {
			forceOfflineSwitch.setState(true);
		}

		// TODO move this to where network status is saved
		exfPWA.data.saveAutoOfflineToggleStatus(false); // Save auto offline status
	};

	this.toggleForceOfflineOff = function () {
		_forceOffline = false;
		_bLowSpeed = false;
		// TODO aka 23.10.2024: shouldn't the state be forced offline here?
		_oLauncher.updateNetworkState(false, _autoOffline);

		_oLauncher.showMessageToast(_oLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.PWA.FORCE_OFFLINE_OFF"));
		if (_autoOffline) {
			_oLauncher.initPoorNetworkPoller();
		} else {
			_oLauncher.toggleOnlineIndicator({ lowSpeed: false });
		}

		// Update UI elements
		var autoOfflineSwitch = sap.ui.getCore().byId('auto_offline_toggle');
		if (autoOfflineSwitch) {
			autoOfflineSwitch.setEnabled(true);
		}

		// Update force offline switch state
		var forceOfflineSwitch = sap.ui.getCore().byId('force_offline_toggle');
		if (forceOfflineSwitch) {
			forceOfflineSwitch.setState(false);
		}
	};

	this.toggleAutoOfflineOn = function () {
		if (_forceOffline) {
			exfLauncher.showMessageToast(exfLauncher.contextBar.getComponent().getModel('i18n').getProperty("WEBAPP.SHELL.PWA.AUTO_OFFLINE_DISABLED_FORCE_OFFLINE"));
			return;
		}

		exfPWA.data.saveAutoOfflineToggleStatus(true)
			.then(function () {
				_autoOffline = true;
				return exfLauncher.isNetworkSlow();
			})
			.then(function (isNetworkSlow) {
				exfLauncher.updateNetworkState(isNetworkSlow, _autoOffline);

				var i18nModel = exfLauncher.contextBar.getComponent().getModel('i18n');
				exfLauncher.showMessageToast(i18nModel.getProperty("WEBAPP.SHELL.PWA.AUTOMATIC_OFFLINE_ON"));

				if (isNetworkSlow) {
					exfLauncher.initFastNetworkPoller();
				} else {
					exfLauncher.initPoorNetworkPoller();
				}

				exfLauncher.toggleOnlineIndicator({ lowSpeed: isNetworkSlow });
			})
			.catch(function (error) {
				console.error("Error turning on auto offline mode:", error);
				exfLauncher.showMessageToast("Error turning on auto offline mode");
			});
	};
	// Update the updateNetworkState function to handle both auto offline and force offline
	this.updateNetworkState = function (isLowSpeed, isAutoOffline) {
		var isOnline = navigator.onLine && !_forceOffline;
		var currentState = {
			isLowSpeed: isLowSpeed,
			isOnline: isOnline,
			isAutoOffline: isAutoOffline,
			isForceOffline: _forceOffline
		};

		if (JSON.stringify(currentState) !== JSON.stringify(this._lastNetworkState)) {
			this._lastNetworkState = currentState;

			var connectionStatus;
			if (_forceOffline) {
				connectionStatus = 'offline_forced';
			} else if (!isOnline) {
				connectionStatus = 'offline';
			} else if (isLowSpeed && isAutoOffline) {
				connectionStatus = 'offline_bad_connection';
			} else {
				connectionStatus = 'online';
			}

			// TODO why is lowSpeed connected to forceOffline?
			this.toggleOnlineIndicator({ lowSpeed: _forceOffline || (isLowSpeed && isAutoOffline) });
			exfPWA.data.saveConnectionStatus(connectionStatus, isLowSpeed, isAutoOffline, _forceOffline);

			// Update network menu title
			var oPopover = sap.ui.getCore().byId('exf-network-menu');
			if (oPopover) {
				oPopover.setTitle(this.getTitle());
			}
		}
	};

	this.toggleAutoOfflineOff = function () {
		exfPWA.data.saveAutoOfflineToggleStatus(false)
			.then(function () {
				_autoOffline = false;
				return exfLauncher.isNetworkSlow();
			})
			.then(function (isNetworkSlow) {
				exfLauncher.updateNetworkState(isNetworkSlow, _autoOffline);

				clearInterval(_oNetworkSpeedPoller);

				if (_bLowSpeed) {
					_bLowSpeed = false;
				}

				exfLauncher.initPoorNetworkPoller();
				exfLauncher.toggleOnlineIndicator({ lowSpeed: false });

				var i18nModel = exfLauncher.contextBar.getComponent().getModel('i18n');
				exfLauncher.showMessageToast(i18nModel.getProperty("WEBAPP.SHELL.PWA.AUTOMATIC_OFFLINE_OFF"));
			})
			.catch(function (error) {
				console.error("Error turning off auto offline mode:", error);
				exfLauncher.showMessageToast("Error turning off auto offline mode");
			});
	};
}).apply(exfLauncher);


var originalAjax = $.ajax;
$.ajax = function (options) {
	var startTime = new Date().getTime();
	// Calculate the request headers length
	let requestHeadersLength = 0;
	if (options.headers) {
		for (let header in options.headers) {
			if (options.headers.hasOwnProperty(header)) {
				requestHeadersLength += new Blob([header + ": " + options.headers[header] + "\r\n"]).size * 8;
			}
		}
	}

	// Calculate the request content length (if any)
	let requestContentLength = 0;
	if (options.data) {
		requestContentLength = new Blob([JSON.stringify(options.data)]).size * 8;
	}

	var newOptions = $.extend({}, options, {
		success: function (data, textStatus, jqXHR) {
			// Record the response end time
			let endTime = new Date().getTime();

			// Check if the response is from cache; skip measurement if true
			if (jqXHR.getResponseHeader('X-Cache') === 'HIT') {
				return; // Cancel measurement
			}

			// Retrieve the 'Server-Timing' header
			let serverTimingHeader = jqXHR.getResponseHeader('Server-Timing');
			let serverTimingValue = 0;

			// Extract the 'dur' value from the Server-Timing header
			if (serverTimingHeader) {
				let durMatch = serverTimingHeader.match(/dur=([\d\.]+)/);
				if (durMatch) {
					serverTimingValue = parseFloat(durMatch[1]);
				}
			}

			// Calculate the duration, adjusting for server processing time
			let duration = (endTime - startTime - serverTimingValue) / 1000; // Convert to seconds

			// Retrieve the Content-Length (size) of the response
			let responseContentLength = parseInt(jqXHR.getResponseHeader('Content-Length')) || 0;

			// Calculate the length of response headers
			let responseHeaders = jqXHR.getAllResponseHeaders(); // Retrieves all response headers as a string
			let responseHeadersLength = new Blob([responseHeaders]).size * 8; // Calculate in bits

			// Calculate the total data size (request headers + request body + response headers + response body) in bits
			let totalDataSize = (requestHeadersLength + requestContentLength + responseHeadersLength + responseContentLength * 8);

			// Calculate internet speed in Mbps
			let speedMbps = totalDataSize / (duration * 1000000);

			// Retrieve the Content-Type from the headers or from the contentType property
			let requestMimeType = options.contentType || (options.headers && options.headers['Content-Type']) || 'application/x-www-form-urlencoded; charset=UTF-8';

			// check exfPWA library is exists
			if (typeof exfPWA !== 'undefined') {
				exfPWA.data.saveNetworkStat(new Date(endTime), speedMbps, requestMimeType, totalDataSize)
					.then(function () {
						listNetworkStats();
					})
					.catch(function (error) {
						console.error("Error saving network stat:", error);
					});

				// Set up periodic deletion if not already set
				if (!window.networkStatCleanupInterval) {
					window.networkStatCleanupInterval = setInterval(function () {
						deleteOldNetworkStats();
						listNetworkStats();
					}, 10 * 60 * 1000); // 10 minutes in milliseconds
				}

			} else {
				console.error("exfPWA is not defined");
			}


			if (options.success) {
				options.success.apply(this, arguments);
			}
		},
		complete: function (jqXHR, textStatus) {
			if (options.complete) {
				options.complete.apply(this, arguments);
			}
		}
	});

	// Function to delete old network stats
	function deleteOldNetworkStats() {
		if (typeof exfPWA !== 'undefined') {
			var tenMinutesAgo = new Date(Date.now() - 10 * 60 * 1000);
			exfPWA.data.deleteNetworkStatsBefore(tenMinutesAgo)
				.then(function () {

				})
				.catch(function (error) {
					console.error("Error deleting old network stats:", error);
				});
		}
	}

	return originalAjax.call(this, newOptions);
};


function listNetworkStats() {
	// FIXME #performance this caused a memory leak for some installations
	// The code seemed to get called indefinitely causing all JS to run very
	// slow and memory consuption of the browser tab to jump to 1.1-1.2 GB
	return;
	exfPWA.data.getAllNetworkStats()
		.then(stats => {
			if (exfPWA.isAvailable() === false) {
				return;
			}
			// Check if there are any statistics available
			if (stats.length === 0) {
				return; // Exit if there are no stats
			}

			// Sort the statistics by time (oldest to newest)
			stats.sort((a, b) => a.time - b.time);

			const averageSpeeds = {};
			const currentSecond = Math.floor(Date.now() / 1000); // Get current time in seconds
			const earliestSecond = Math.floor(stats[0].time / 1000); // Earliest timestamp (first element)
			const latestSecond = Math.floor(stats[stats.length - 1].time / 1000); // Latest timestamp (last element)

			// Group speeds by second
			stats.forEach(stat => {
				const requestTimeInSeconds = Math.floor(stat.time / 1000); // Convert timestamp to seconds
				if (!averageSpeeds[requestTimeInSeconds]) {
					averageSpeeds[requestTimeInSeconds] = []; // Initialize array if it doesn't exist
				}
				averageSpeeds[requestTimeInSeconds].push(stat.speed); // Collect speeds for this second
			});

			const secondsToProcess = latestSecond - earliestSecond; // Calculate the number of seconds to process

			const result = {};
			let lastKnownSpeed = 0; // Variable to store the last known speed

			// Iterate over each second from earliest to latest
			for (let i = 0; i <= secondsToProcess; i++) {
				const second = earliestSecond + i; // Get the second to process

				if (averageSpeeds[second]) {
					// Filter out any NaN values from the speeds and convert to doubles
					const validSpeeds = averageSpeeds[second].filter(speed => {
						const isValid = !isNaN(speed); // Check if speed is not NaN
						if (!isValid) {
							console.warn(`Invalid speed detected: ${speed} at second ${second}`);
						}
						return isValid; // Return valid speeds
					}).map(speed => parseFloat(speed)); // Convert to floating-point numbers

					if (validSpeeds.length > 0) {
						// Calculate the average speed from valid speeds
						const avgSpeed = validSpeeds.reduce((a, b) => a + b, 0) / validSpeeds.length;
						result[second] = avgSpeed; // Store the average speed for this second
						lastKnownSpeed = avgSpeed; // Update the last known speed
					} else {
						result[second] = lastKnownSpeed; // Use the last known speed if no valid speeds
					}
				} else {
					result[second] = lastKnownSpeed; // If no data for this second, use last known speed
				}
			}

			// Register each calculated speed
			Object.keys(result).forEach(second => {
				exfLauncher.registerNetworkSpeed(result[second]);
			});
		})
		.catch(error => {
			console.error("An error occurred while listing network statistics:", error);
		});
}

// Set to keep track of dismissed errors
exfLauncher.dismissedErrors = new Set();

/**
 * Displays an error popover with a list of captured errors, excluding dismissed ones.
 * This function creates or updates a popover to show error details.
 * @param {string} errorMessage - The most recent error message to display.
 */
exfLauncher.showErrorPopover = function(errorMessage) {
    // Close and destroy the existing popover if it's open 
    if (this.errorPopover) {
        this.errorPopover.close();
        this.errorPopover.destroy();
    }

    // Filter out dismissed errors 
    var activeErrors = capturedErrors.filter(error => !this.dismissedErrors.has(error.message));

    // Prepare error messages as a list  
    var errorList = new sap.m.List({
        items: activeErrors.map(function(error) {
            return new sap.m.StandardListItem({
                title: error.message,
                description: new Date(error.timestamp).toLocaleString(),
                type: "Active",
                wrapping: true,
                press: function() {
                    // Close the popover before showing the log
					exfLauncher.errorPopover.close();
					var dummyEvent = {
						getSource: function() {
							return sap.ui.getCore().byId("exf-network-indicator");
						}
					};
					// Show the detailed error log
					exfLauncher.showErrorLog(dummyEvent);
                }
            });
        })
    });

    this.errorPopover = new sap.m.Popover({
        placement: sap.m.PlacementType.Bottom,
        showHeader: true,
        title: "Errors Occurred", 
        content: [
            new sap.m.VBox({
                items: [
                    new sap.m.Text({
                        text: activeErrors.length + " error(s) occurred. Tap for details.",
                        wrapping: true // Wrapping the message
                    }).addStyleClass("sapUiSmallMargin"),
                    errorList
                ],
                justifyContent: sap.m.FlexJustifyContent.SpaceBetween,
                alignItems: sap.m.FlexAlignItems.Stretch,
                height: "100%"
            })
        ],
        footer: new sap.m.Toolbar({
            content: [
                new sap.m.ToolbarSpacer(),
                new sap.m.Button({
                    text: "Details",
                    icon: "sap-icon://detail-view",
                    press: function() {
                        // Close the popover before showing the  log
                        exfLauncher.errorPopover.close();
                        // Create a dummy event object since showErrorLog  expects one
                        var dummyEvent = {
                            getSource: function() {
                                return sap.ui.getCore().byId("exf-network-indicator");
                            }
                        };
                        // Show the detailed error log
                        exfLauncher.showErrorLog(dummyEvent);
                    }
                }),
                new sap.m.Button({
                    text: "Dismiss All",
                    press: function() {
                        activeErrors.forEach(error => exfLauncher.dismissedErrors.add(error.message));
                        exfLauncher.errorPopover.close();
                    }
                }),
                new sap.m.Button({
                    text: "Close",
                    press: function() {
                        exfLauncher.errorPopover.close();
                    }
                })
            ]
        }),
        afterClose: function() {
            // Don't destroy the popover after closing, keep it for reuse
            exfLauncher.errorPopover.close();
        }
    });

    // Make the popover more visible
    this.errorPopover.addStyleClass("sapUiContentPadding");
    this.errorPopover.addStyleClass("sapUiResponsivePadding");

    // Keep the popover above other page elements
    this.errorPopover.setInitialFocus(this.errorPopover.getContent()[0]);

    // Show Popover only if there are active errors
    if (activeErrors.length > 0) {
        var oNetworkIndicator = sap.ui.getCore().byId("exf-network-indicator");
		jQuery.sap.delayedCall(0, this, function () {
        	this.errorPopover.openBy(oNetworkIndicator);
		});
    }
};

/**
 * Logs all JS console output to the capturedErrors array with details such as message, stack trace, and timestamp.
 * Messages also show a popup if the level is 'error' and tracing is enabled in the context bar.
 * 
 * @param args error arguments from console
 * @param level level of logging (e.g. error, warn, log, assert, etc.)
 * @param message error message to log (optional, if not provided it will be constructed from args)
 */
exfLauncher.logJsError = function(args, level, message = null){
	if (!Array.isArray(args) || args.length === 0) return;

	let sMessage = args.map(arg => {
		if (arg instanceof Error) {
			return arg.stack || arg.message;
		} else if (typeof arg === 'object') {
			try {
				return JSON.stringify(arg);
			} catch {
				return '[cant stringify error message]';
			}
		} else {
			return String(arg);
		}
	}).join(' ');

	const shouldIgnore = ignoredErrorPatterns?.some(pattern =>
		pattern.test(sMessage)
	);

	if (!shouldIgnore && !exfLauncher.dismissedErrors?.has(sMessage)) {
		const errorArg = args.find(a => a && (a instanceof Error || typeof a.stack === "string"));
		const logDetails = {
			level: level, // console method name (error, warn, log, etc.)
			message: message || sMessage,
			timestamp: new Date().toISOString(),
			url: window.location.href,
			stack: errorArg?.stack || new Error().stack
		};

		// save to log array
		capturedErrors.push(logDetails);

		// only show popover for error levels
		// and send the error to the LogHub Facade
		if (typeof exfLauncher.showErrorPopover === "function" && level === 'error') {
			exfLauncher.showErrorPopover(sMessage);
			exfLauncher.postJsErrorLog(logDetails);
		}
	}
}

exfLauncher.requestCount = 0;
exfLauncher.MAX_REQUESTS = 60; // max requests allowed
exfLauncher.TIME_WINDOW = 60000; // per time window in milliseconds

/**
 * Sends the JS Error to the LogHub Facade for centralized logging.
 * @param {*} oLogDetails object containing the error log details
 */
exfLauncher.postJsErrorLog = function (oLogDetails) {

	// basic rate limiting, to avoid spamming the logs
	// for now, we can try at most 60 logs/minute?
	if (exfLauncher.requestCount >= exfLauncher.MAX_REQUESTS) {
        console.warn("Rate limit exceeded. JS Error Log not sent.");
        return;
    }

	exfLauncher.requestCount++;

	// send location/breadcrumb data with request
	const aCrumbs = this.getShell().getModel().getProperty("/_breadcrumbs/crumbs");
	const sCurrentTitle = this.getShell().getModel().getProperty("/_breadcrumbs/current_title");
	let aLocations = [
		...aCrumbs,
		{ title: sCurrentTitle, url: oLogDetails.url }
	];
	oLogDetails.locations = aLocations;
	oLogDetails.page = sCurrentTitle;

	$.ajax({
		type: 'POST',
		url: 'api/loghub',
		data: JSON.stringify(oLogDetails)
	})
};

/**
 * Function that returns the current error logs
 * 
 * @returns array[json objects] 
 */
exfLauncher.getJsErrorLogs = function () {
	return capturedErrors;
};

/**
 * Function that resets the current error logs to an empty array
 * 
 */
exfLauncher.resetJsErrorLogs = function () {
	capturedErrors = [];
};

/**
 * This wraps the default console functions in a proxy to capture and log errors.
 * This logs the original console output, captures details, and displays errors in a popover.
 * 
 * NOTE sah: the error level doesn't seem to work for anything other than explicitly thrown errors (e.g. throw new Error(), or console.error()),
 * so we additionally listen to window.onerror for runtime errors; (see next function)
 */
window.__exfProxyInstance = new Proxy(window.__originalConsole, {
	get(target, prop) {
		const originalMethod = target[prop];

		// only proxy proper functions, return for other console properties (console.memory etc...)
		if (typeof originalMethod !== 'function') {
			return originalMethod;
		}

		// apply console method and log error
		return function (...args) {
			originalMethod.apply(target, args);
			exfLauncher?.logJsError?.(args, prop);
		};
	}
});

/**
 * Listen to other errors that are not caught by console.error, such as type/reference errors 
 * this doesnt need to be proxied because window.onerror can pass the error object through unlike the console functions
 */
window.onerror = function (message, source, lineno, colno, error) {
	// log error if tracing is enabled
	if (sessionStorage.getItem('exfJsTracingEnabled') === 'true') {
		exfLauncher.logJsError([error], 'error', message);
	}

    // returning false lets the browser still log the error normally
    return false;
};

/**
 * routes console output through a proxy function to capture logs
 */
exfLauncher.enableJsTracing = function () {
	window.console = window.__exfProxyInstance;
	sessionStorage.setItem('exfJsTracingEnabled', 'true');
	
	// rate limiting for sending logs to server
    if (!exfLauncher._rateLimitInterval) { // Prevent multiple intervals
        exfLauncher._rateLimitInterval = setInterval(() => {
            exfLauncher.requestCount = 0; 
        }, exfLauncher.TIME_WINDOW);
    }
};

/**
 * restores the original console functions and disable error logging
 */
exfLauncher.disableJsTracing = function () {
	window.console = window.__originalConsole;
	sessionStorage.setItem('exfJsTracingEnabled', 'false');

	// Clear the rate-limiting interval
    if (exfLauncher._rateLimitInterval) {
        clearInterval(exfLauncher._rateLimitInterval);
        exfLauncher._rateLimitInterval = null; 
    }
};


// Define initial state
exfLauncher._lastNetworkState = null;

// Store the existing window.onload function (if it exists)
var existingOnload = window.onload;

// Define the new window.onload function
window.onload = function () {
	// If there is an existing onload function, call it
	if (typeof existingOnload === 'function') {
		existingOnload();
	}

	// Clear the error list (Step 5)
	capturedErrors = [];

	// restart js tracing if needed
	if (sessionStorage.getItem('exfJsTracingEnabled') === 'true') {
		exfLauncher.enableJsTracing();
	}

	// After the page has finished loading, check the error count and show toast message (Step 6)
	setTimeout(function () {
		if (capturedErrors.length > 0) {
			exfLauncher.showMessageToast(capturedErrors.length + ' errors occurred. Check the error log for details.', 3000);
		}
	}, 1000); // 1 second delay to ensure the page has fully loaded
};
window['exfLauncher'] = exfLauncher;


//ServiceWorker automatic update
function checkForServiceWorkerUpdate() {
	if ('serviceWorker' in navigator) {
		navigator.serviceWorker.getRegistration().then(reg => {
			if (reg) {
				reg.update();
			}
		});
	}
}

// // After page load, check service 
// window.addEventListener('load', () => {
	
// });