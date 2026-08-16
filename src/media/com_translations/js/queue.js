/**
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

(() => {
  document.querySelectorAll('.translate-trigger').forEach(trigger => {
    let started = false;

    trigger.addEventListener('click', event => {
      // A second click would start a second translation.
      if (started) {
        event.preventDefault();
        return;
      }

      started = true;

      // The translation is made before the response returns, so the cell shows that it is running.
      trigger.querySelector('span').className = 'icon-spinner icon-spin icon-fw text-primary';
    });
  });
})();
