<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Whether a translation is on the site, as far as its source decides it.
 *
 * A translation is never on the site while its source is not. When a source goes off the site,
 * unpublished or archived, its live translations follow it and are marked as held by the source;
 * when the source is published again, exactly the held ones come back. A translation somebody
 * took off the site themselves is never held, so it stays off: the source only undoes what it did.
 *
 * The same goes for a new translation that Auto-Publish would put on the site: while its source
 * is off the site it waits, held, until the source is published.
 *
 * @since  2.0.0
 */
final class SourceState
{
    /**
     * The published state of Joomla's content tables.
     *
     * @var    integer
     * @since  2.0.0
     */
    public const PUBLISHED = 1;

    /**
     * The unpublished state of Joomla's content tables.
     *
     * @var    integer
     * @since  2.0.0
     */
    public const UNPUBLISHED = 0;

    /**
     * The archived state of Joomla's content tables.
     *
     * @var    integer
     * @since  2.0.0
     */
    public const ARCHIVED = 2;

    /**
     * The state a new translation is created with.
     *
     * @param   integer  $sourceState  The source's state.
     * @param   boolean  $autoPublish  Whether a new translation is meant to go on the site at once.
     *
     * @return  array{state: int, held: bool}  The translation's state, and whether it is held by its source.
     *
     * @since   2.0.0
     */
    public static function forNewTranslation(int $sourceState, bool $autoPublish): array
    {
        if (!$autoPublish) {
            return ['state' => self::UNPUBLISHED, 'held' => false];
        }

        if ($sourceState === self::PUBLISHED) {
            return ['state' => self::PUBLISHED, 'held' => false];
        }

        return ['state' => self::offSite($sourceState), 'held' => true];
    }

    /**
     * The state a translation goes to now that its source has the state it has.
     *
     * @param   integer  $sourceState       The source's state.
     * @param   integer  $translationState  The translation's state.
     * @param   boolean  $held              Whether the translation is held by its source.
     *
     * @return  array{state: int, held: bool}|null  The translation's new state and whether it is held,
     *                                              or null when it stays as it is.
     *
     * @since   2.0.0
     */
    public static function follow(int $sourceState, int $translationState, bool $held): ?array
    {
        if ($sourceState === self::PUBLISHED) {
            return $held ? ['state' => self::PUBLISHED, 'held' => false] : null;
        }

        if ($sourceState !== self::UNPUBLISHED && $sourceState !== self::ARCHIVED) {
            // Trashing has its own cascade; any other state is not one to follow.
            return null;
        }

        if ($translationState === self::PUBLISHED || ($held && $translationState !== $sourceState)) {
            return ['state' => $sourceState, 'held' => true];
        }

        return null;
    }

    /**
     * The state a translation waits in while its source is off the site.
     *
     * @param   integer  $sourceState  The source's state.
     *
     * @return  integer  Archived for an archived source, unpublished otherwise.
     *
     * @since   2.0.0
     */
    private static function offSite(int $sourceState): int
    {
        return $sourceState === self::ARCHIVED ? self::ARCHIVED : self::UNPUBLISHED;
    }
}
