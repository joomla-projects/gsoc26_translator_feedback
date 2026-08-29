/**
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

(() => {
  const exportForm = document.getElementById('exportForm');

  exportForm.addEventListener('submit', () => {
    const selected = document.querySelectorAll("#adminForm input[name='cid[]']:checked");

    // Left empty, the export follows the list filters instead of a selection.
    exportForm.elements.cids.value = [...selected].map(box => box.value).join(',');
  });
})();
