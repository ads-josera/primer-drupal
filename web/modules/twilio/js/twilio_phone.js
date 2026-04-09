'use strict';

(function ($, Drupal, once) {
  Drupal.behaviors.twilioPhone = {
    attach: function (context) {
      var selector = 'input[data-drupal-selector^="edit-phone-whatsapp"], input[data-drupal-selector^="edit-whatsapp-phone"]';
      once('twilioPhone', selector, context).forEach(function (element) {
        if (!window.intlTelInput) {
          return;
        }

        var iti = window.intlTelInput(element, {
          initialCountry: 'mx',
          preferredCountries: ['mx', 'us', 'ca'],
          strictMode: true,
          loadUtils: function () {
            return import('https://cdn.jsdelivr.net/npm/intl-tel-input/build/js/utils.js');
          }
        });

        var syncValue = function () {
          var rawValue = (element.value || '').trim();
          if (!rawValue) {
            element.value = '';
            return;
          }

          if (iti.isValidNumber()) {
            element.value = iti.getNumber();
          }
        };

        element.addEventListener('blur', syncValue);
        element.addEventListener('countrychange', syncValue);
      });
    }
  };
})(jQuery, Drupal, once);
