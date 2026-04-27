/**
 * File: app/code/Paythor/SanalPosPro/view/frontend/web/js/view/payment/sanalpospro.js
 *
 * Registers our payment-method renderer with the checkout's render list.
 * Magento's Magento_Checkout/js/model/payment/renderer-list reads the
 * methods declared here and instantiates the matching method-renderer.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'paythor_sanalpospro',
        component: 'Paythor_SanalPosPro/js/view/payment/method-renderer/sanalpospro-method'
    });

    /** Component subscribed to renderer list keeps Magento happy. */
    return Component.extend({});
});
