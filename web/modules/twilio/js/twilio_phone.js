'use strict';

(function ($, Drupal, once) {
  Drupal.behaviors.twilioPhone = {
    attach: function (context) {
      var selector = [
        'input[data-drupal-selector*="telefono"]',
        'input[name="telefono"]',
        'input[name*="[telefono]"]',
        'input[data-drupal-selector*="whatsapp-phone"]',
        'input[data-drupal-selector*="phone-whatsapp"]',
        'input[name="whatsapp_phone"]',
        'input[name="phone_whatsapp"]',
        'input[name*="[whatsapp_phone]"]',
        'input[name*="[phone_whatsapp]"]',
        'input[type="tel"][data-drupal-selector*="whatsapp"]',
        'input[type="text"][data-drupal-selector*="whatsapp"]'
      ].join(', ');
      once('twilioPhone', selector, context).forEach(function (element) {
        if (!window.intlTelInput) {
          return;
        }

        var iti = window.intlTelInput(element, {
          initialCountry: 'mx',
          preferredCountries: ['mx', 'us', 'ca'],
          strictMode: true,
          utilsScript: '/libraries/jquery.intl-tel-input/build/js/utils.js'
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
