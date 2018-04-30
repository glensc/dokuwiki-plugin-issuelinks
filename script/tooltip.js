/**
 * Tooltips populated by ajax request
 *
 * @author Michael Große
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 */


window.addTooltip = function addTooltip(selectorOr$element, url, dataOrDataFunction, complete) {
    'use strict';

    var serverEndpoint = url || window.DOKU_BASE + 'lib/exe/ajax.php';
    var DELAY = 300;
    var HOVER_DETECTION_DELAY = 100;
    var TOOLTIP_PARENT_CLASS = 'hasTooltip';

    function hoverStart() {
        var timeOutReference;
        var payload;
        var $tooltipDiv;
        var $element = jQuery(this);
        $element.addClass('hover');
        if ($element.hasClass(TOOLTIP_PARENT_CLASS)) {
            if ($element.data('MMtooltipID')) {
                $tooltipDiv = jQuery('#' + $element.data('MMtooltipID'));
                $tooltipDiv.show().position({
                    my: 'left top',
                    at: 'left bottom',
                    of: $element,
                }).attr('aria-hidden', 'false');
            }
            return;
        }
        payload = typeof dataOrDataFunction === 'function' ? dataOrDataFunction($element) : dataOrDataFunction;
        timeOutReference = setTimeout(function getToolTip() {
            var $div = jQuery('<div class="serverToolTip">')
                .uniqueId()
                .mouseleave(function hideTooltip() {
                    $div.removeClass('hover');
                    $div.hide().attr('aria-hidden', 'true');
                })
                .mouseenter(function allowJSHoverDetection() {
                    $div.addClass('hover');
                });
            $element.addClass(TOOLTIP_PARENT_CLASS);
            jQuery.get(serverEndpoint, payload).done(function injectTooltip(response) {
                window.magicMatcherUtil.showAjaxMessages(response);
                $div.html(response.data);
                $div.appendTo(jQuery('body'));
                $div.show().position({
                    my: 'left top',
                    at: 'left bottom',
                    of: $element,
                });
                if (!$element.hasClass('hover')) {
                    $div.hide();
                }
                $element.data('MMtooltipID', $div.attr('id'));
                if (typeof complete === 'function') {
                    complete($element, $div);
                }
            }).fail(function handleFailedState(jqXHR) {
                window.magicMatcherUtil.showAjaxMessages(jqXHR.responseJSON);
                $div.remove();
            });
        }, DELAY);
        $element.data('timeOutReference', timeOutReference);
    }

    function hoverEnd() {
        var $this = jQuery(this);
        $this.removeClass('hover');
        clearTimeout($this.data('timeOutReference'));
        if ($this.data('MMtooltipID')) {
            setTimeout(function conditionalHideTooltip() {
                var $tooltip = jQuery('#' + $this.data('MMtooltipID'));
                if (!$tooltip.hasClass('hover')) {
                    $tooltip.hide().attr('aria-hidden', 'true');
                }
            }, HOVER_DETECTION_DELAY);
        }
    }

    if (typeof selectorOr$element === 'string') {
        jQuery(document).on('mouseenter', selectorOr$element, hoverStart);
        jQuery(document).on('mouseleave', selectorOr$element, hoverEnd);
    } else {
        selectorOr$element.hover(hoverStart, hoverEnd);
    }
};
