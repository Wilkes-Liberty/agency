(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.discoveryConsole = {
    attach(context) {
      once('discovery-console', 'form.agency-console', context).forEach((form) => {
        const focus = form.querySelector('[data-agency-focus]');
        if (focus) focus.focus();

        const email = form.querySelector('[data-agency-selection-email]');
        const selection = form.querySelector('[data-agency-selection]');
        if (!email || !selection || email.disabled) return;
        const selectedEmail = email.value;
        email.addEventListener('input', () => {
          if (email.value === selectedEmail) return;
          selection.hidden = true;
          const prepare = form.querySelector('[data-agency-prepare]');
          if (prepare) prepare.disabled = true;
          const cleared = form.querySelector('[data-agency-selection-cleared]');
          if (cleared) cleared.hidden = false;
        });
      });
      once('discovery-console-return', '.agency-console', context).forEach((container) => {
        if (!/^#delivery-[a-f0-9]{64}$/.test(window.location.hash)) return;
        const opener = document.getElementById(window.location.hash.slice(1));
        if (opener && container.contains(opener)) opener.focus();
      });
    },
  };
})(Drupal, once);
