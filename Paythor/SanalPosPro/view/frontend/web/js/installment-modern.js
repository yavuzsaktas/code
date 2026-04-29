document.addEventListener('DOMContentLoaded', function() {
    /* ---------- Reviews <-> Installments section nav (Page Builder layout) ----------
     * Page Builder renders the Reviews block and our Installments block as
     * two separate full-width sections under the product. Wire them together
     * with a small tab nav so visitors can flip between them like sibling
     * tabs (matching the WC reference). Falls back to the standalone
     * sections when either is missing.
     * ------------------------------------------------------------------------------- */
    (function buildSectionTabs() {
        var reviews      = document.getElementById('reviews');
        var installments = document.getElementById('paythor-installments');
        if (!reviews || !installments) {
            return; // Nothing to wire up.
        }
        if (document.querySelector('.paythor-section-tabs')) {
            return; // Already initialised.
        }

        var tabs = document.createElement('div');
        tabs.className = 'paythor-section-tabs';
        tabs.innerHTML =
            '<button type="button" class="paythor-section-tab" data-target="reviews">Reviews</button>' +
            '<button type="button" class="paythor-section-tab" data-target="installments">Installments</button>';

        // Keep installments markup but suppress its internal title to avoid
        // duplicate headings once the tab nav owns the labelling.
        var ownTitle = installments.querySelector('.sppro-installment-title');
        if (ownTitle) {
            ownTitle.style.display = 'none';
        }
        // Hide the original Reviews <h2> too (Page Builder adds one).
        var reviewsTitle = reviews.querySelector('.product-section-title');
        if (reviewsTitle) {
            reviewsTitle.style.display = 'none';
        }

        // Place installments immediately after reviews so they share a region,
        // then prepend the tab nav above them.
        if (installments.previousElementSibling !== reviews) {
            reviews.parentNode.insertBefore(installments, reviews.nextSibling);
        }
        reviews.parentNode.insertBefore(tabs, reviews);

        function activate(target) {
            reviews.style.display      = target === 'reviews'      ? '' : 'none';
            installments.style.display = target === 'installments' ? '' : 'none';
            tabs.querySelectorAll('.paythor-section-tab').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-target') === target);
            });
        }

        tabs.querySelectorAll('.paythor-section-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                activate(btn.getAttribute('data-target'));
            });
        });

        activate('reviews');
    })();

    /* ---------- Card-family tab switching inside the installments table ---------- */
    var tabItems = document.querySelectorAll('.sppro-tab-item');
    var tabPanes = document.querySelectorAll('.sppro-tab-pane');

    if (tabItems.length > 0) {
        tabItems[0].classList.add('active');
        var firstTabId = tabItems[0].getAttribute('data-tab');
        var firstPane  = document.querySelector('.sppro-tab-pane[data-tab-content="' + firstTabId + '"]');
        if (firstPane) {
            firstPane.classList.add('active');
        }
    }

    tabItems.forEach(function (item) {
        item.addEventListener('click', function () {
            var tabId = this.getAttribute('data-tab');
            tabItems.forEach(function (tab) { tab.classList.remove('active'); });
            this.classList.add('active');
            tabPanes.forEach(function (pane) { pane.classList.remove('active'); });
            var pane = document.querySelector('.sppro-tab-pane[data-tab-content="' + tabId + '"]');
            if (pane) {
                pane.classList.add('active');
            }
        });
    });
});

function selectCardFamily(selector, cardFamilyWrapper) {
    document.querySelectorAll('.sanalpospro-card-installment-wrapper').forEach(function(wrapper, index) {
        wrapper.classList.add('sanalpospro-installment-card-wrapper-inactive');
    });

    document.querySelector(selector).classList.remove('sanalpospro-installment-card-wrapper-inactive');
    document.querySelector(selector).classList.add('sanalpospro-installment-card-wrapper-active');

    document.querySelectorAll('.sanalpospro-card-family-wrapper').forEach(function(wrapper, index) {
        wrapper.classList.remove('sanalpospro-card-family-wrapper-active');
        wrapper.classList.add('sanalpospro-card-family-wrapper-inactive');
    });

    document.querySelector(cardFamilyWrapper).classList.add('sanalpospro-card-family-wrapper-active');
} 