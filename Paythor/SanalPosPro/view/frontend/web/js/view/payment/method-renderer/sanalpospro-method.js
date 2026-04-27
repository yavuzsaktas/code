/**
 * File: app/code/Paythor/SanalPosPro/view/frontend/web/js/view/payment/method-renderer/sanalpospro-method.js
 */
define([
    'jquery',
    'underscore',
    'ko',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/select-payment-method',
    'Magento_Checkout/js/checkout-data',
    'Magento_Checkout/js/action/set-payment-information', // YENİ EKLENDİ: Sepet bilgilerini kaydetmek için
    'Magento_Customer/js/model/customer',
    'Magento_Ui/js/modal/modal',
    'Magento_Ui/js/model/messageList',
    'mage/url',
    'mage/translate'
], function (
    $,
    _,
    ko,
    Component,
    quote,
    urlBuilder,
    fullScreenLoader,
    selectPaymentMethodAction,
    checkoutData,
    setPaymentInformationAction, // YENİ EKLENDİ
    customer,
    modal,
    globalMessageList,
    urlFormatter,
    $t
) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Paythor_SanalPosPro/payment/sanalpospro',
            redirectAfterPlaceOrder: false,
            paythorEndpoint: 'paythor/payment/create'
        },

        modalInstance: null,
        modalContainer: null,
        callbackBound: false,

        isPlaceOrderActionAllowed: ko.observable(true),

        getCode: function () {
            return 'paythor_sanalpospro';
        },

        getData: function () {
            return {
                method: this.getCode(),
                additional_data: null
            };
        },

        /**
         * 1. Adım: Magento'ya ödeme/adres bilgilerini kaydettir (Siparişi tamamlamadan).
         * 2. Adım: Başarılı olursa, bizim Controller'ımıza (paythor/payment/create) AJAX at.
         */
        placeOrder: function (data, event) {
            var self = this;

            if (event) {
                event.preventDefault();
            }

            if (!this.validate() || !this.isPlaceOrderActionAllowed()) {
                return false;
            }

            // Ödeme yöntemini seçili olarak işaretle
            selectPaymentMethodAction(this.getData());
            checkoutData.setSelectedPaymentMethod(this.getCode());

            this.isPlaceOrderActionAllowed(false);
            fullScreenLoader.startLoader();

            // Misafirler için email kontrolü
            if (!customer.isLoggedIn() && !quote.guestEmail) {
                fullScreenLoader.stopLoader();
                this.isPlaceOrderActionAllowed(true);
                this._showError($t('Please enter your email address.'));
                return false;
            }

            // Önce bilgileri Magento'ya kaydet (Hata almamak için bu şart)
            $.when(
                setPaymentInformationAction(this.messageContainer, {
                    method: this.getCode()
                })
            ).done(function () {
                // Kayıt başarılıysa, kendi Controller'ımıza AJAX atıyoruz
                self._bindCallbackListener();
                self._sendCreateRequest()
                    .done(function (response) {
                        fullScreenLoader.stopLoader();
                        self.isPlaceOrderActionAllowed(true);

                        if (!response || response.success !== true || !response.iframe_html) {
                            self._showError(
                                (response && response.message)
                                    ? response.message
                                    : $t('Unable to initialize payment. Please try again.')
                            );
                            return;
                        }

                        self._openIframeModal(response.iframe_html);
                    })
                    .fail(function (jqXhr) {
                        fullScreenLoader.stopLoader();
                        self.isPlaceOrderActionAllowed(true);

                        var msg = $t('Unable to initialize payment. Please try again.');
                        if (jqXhr && jqXhr.responseJSON && jqXhr.responseJSON.message) {
                            msg = jqXhr.responseJSON.message;
                        }
                        self._showError(msg);
                    });
            }).fail(function (jqXhr) {
                // Some Magento setups return 404 for guest-carts set-payment-information.
                // Continue with the custom create endpoint instead of hard-failing the flow.
                if (jqXhr && jqXhr.status === 404) {
                    self._bindCallbackListener();
                    self._sendCreateRequest()
                        .done(function (response) {
                            fullScreenLoader.stopLoader();
                            self.isPlaceOrderActionAllowed(true);

                            if (!response || response.success !== true || !response.iframe_html) {
                                self._showError(
                                    (response && response.message)
                                        ? response.message
                                        : $t('Unable to initialize payment. Please try again.')
                                );
                                return;
                            }

                            self._openIframeModal(response.iframe_html);
                        })
                        .fail(function (xhr) {
                            fullScreenLoader.stopLoader();
                            self.isPlaceOrderActionAllowed(true);

                            var fallbackMsg = $t('Unable to initialize payment. Please try again.');
                            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                                fallbackMsg = xhr.responseJSON.message;
                            }
                            self._showError(fallbackMsg);
                        });
                    return;
                }

                fullScreenLoader.stopLoader();
                self.isPlaceOrderActionAllowed(true);
                self._showError($t('Failed to save payment information. Please check your billing details.'));
            });

            return true;
        },

        _sendCreateRequest: function () {
            var quoteId = null;
            if (typeof quote.getQuoteId === 'function') {
                quoteId = quote.getQuoteId();
            } else if (quote.quoteId) {
                quoteId = (typeof quote.quoteId === 'function') ? quote.quoteId() : quote.quoteId;
            }

            var payload = {
                form_key: $.mage.cookies.get('form_key') || window.checkoutConfig.formKey,
                method: this.getCode(),
                cart_id: quoteId,
                // Quote ID veya Email gönderebiliriz, controller'ın sepeti bulmasına yardımcı olur
                email: quote.guestEmail || (customer.customerData && customer.customerData.email)
            };

            return $.ajax({
                url: urlFormatter.build(this.paythorEndpoint),
                type: 'POST',
                dataType: 'json',
                data: payload,
                showLoader: false,
                cache: false,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
        },

        _openIframeModal: function (iframeHtml) {
            var self = this;

            if (this.modalContainer) {
                try { this.modalContainer.remove(); } catch (e) { /* noop */ }
                this.modalContainer = null;
                this.modalInstance = null;
            }

            this.modalContainer = $(
                '<div class="paythor-sanalpospro-modal-content" data-role="paythor-iframe-host"></div>'
            ).html(iframeHtml);

            $('body').append(this.modalContainer);

            this.modalInstance = modal({
                type: 'popup',
                modalClass: 'paythor-sanalpospro-modal',
                title: $t('Secure Card Payment'),
                responsive: true,
                innerScroll: true,
                clickableOverlay: false,
                buttons: [{
                    text: $t('Cancel'),
                    class: 'action secondary action-cancel',
                    click: function () {
                        self.modalInstance.closeModal();
                    }
                }],
                closed: function () {
                    self.isPlaceOrderActionAllowed(true);
                    if (self.modalContainer) {
                        try { self.modalContainer.remove(); } catch (e) { /* noop */ }
                        self.modalContainer = null;
                    }
                }
            }, this.modalContainer);

            this.modalInstance.openModal();
        },

        _bindCallbackListener: function () {
            if (this.callbackBound) {
                return;
            }
            this.callbackBound = true;

            var self = this,
                allowedOrigin = window.location.origin;

            window.addEventListener('message', function (ev) {
                if (ev.origin !== allowedOrigin) {
                    return;
                }

                var data = ev.data;
                if (!data || data.source !== 'paythor_sanalpospro') {
                    return;
                }

                if (data.status === 'success') {
                    if (self.modalInstance) {
                        try { self.modalInstance.closeModal(); } catch (e) { /* noop */ }
                    }
                    window.location.replace(urlFormatter.build('checkout/onepage/success'));
                } else {
                    if (self.modalInstance) {
                        try { self.modalInstance.closeModal(); } catch (e) { /* noop */ }
                    }
                    self._showError(
                        data.message || $t('Payment was not completed. Please try again.')
                    );
                }
            }, false);
        },

        _showError: function (message) {
            globalMessageList.addErrorMessage({ message: message });
        }
    });
});