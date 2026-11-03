<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Link;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A site menu item, as far as a link to it is concerned.
 *
 * @since  2.0.0
 */
final class MenuItem
{
    /**
     * The type of a menu item that stands for another one.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ALIAS = 'alias';

    /**
     * Describe a menu item.
     *
     * @param   integer               $id           The menu item id.
     * @param   string                $language     Its language, or '*'.
     * @param   string                $type         Its type: 'component', 'alias', 'url', ...
     * @param   array<string, mixed>  $query        The query of its link.
     * @param   integer               $aliasTarget  For an alias, the menu item it stands for; else 0.
     *
     * @since   2.0.0
     */
    public function __construct(
        public readonly int $id,
        public readonly string $language,
        public readonly string $type,
        public readonly array $query,
        public readonly int $aliasTarget = 0
    ) {
    }

    /**
     * Whether the menu item is shown for every language.
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    public function forAllLanguages(): bool
    {
        return $this->language === '*' || $this->language === '';
    }

    /**
     * What the menu item opens, as "option.view", or '' for no component view.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function opens(): string
    {
        $option = $this->query['option'] ?? '';
        $view   = $this->query['view'] ?? '';

        return \is_string($option) && \is_string($view) && $option !== '' && $view !== '' ? $option . '.' . $view : '';
    }

    /**
     * The id of the item the menu item opens, or 0.
     *
     * @return  integer
     *
     * @since   2.0.0
     */
    public function opensId(): int
    {
        $id = $this->query['id'] ?? 0;

        return \is_scalar($id) ? (int) $id : 0;
    }
}
