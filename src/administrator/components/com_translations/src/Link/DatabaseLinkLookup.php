<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Link;

use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The site's items and their translations, read from the database.
 *
 * What it reads is kept for as long as the object lives, so use a new one after items were added.
 * A trashed item counts as gone: a link is never pointed at one.
 *
 * @since  2.0.0
 */
final class DatabaseLinkLookup implements LinkLookup
{
    /**
     * The trashed state of Joomla's content tables.
     *
     * @var    integer
     * @since  2.0.0
     */
    private const TRASHED = -2;

    /**
     * The site menu items by id, once read.
     *
     * @var    array<int, MenuItem>|null
     * @since  2.0.0
     */
    private ?array $menuItems = null;

    /**
     * The component menu items per "option.view.id" they open.
     *
     * @var    array<string, MenuItem[]>
     * @since  2.0.0
     */
    private array $byTarget = [];

    /**
     * The languages read, per "content type.id".
     *
     * @var    array<string, string|null>
     * @since  2.0.0
     */
    private array $languages = [];

    /**
     * The association groups read, per "content type.id": item id per language.
     *
     * @var    array<string, array<string, int>>
     * @since  2.0.0
     */
    private array $groups = [];

    /**
     * Build the lookup.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   2.0.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function menuItem(int $id): ?MenuItem
    {
        return $this->menuItems()[$id] ?? null;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function menuItemOpening(string $opens, int $id, string $language): ?MenuItem
    {
        $this->menuItems();

        $best = null;
        $rank = 0;

        foreach ($this->byTarget[$opens . '.' . $id] ?? [] as $item) {
            $itemRank = $item->language === $language ? 1 : ($item->forAllLanguages() ? 0 : -1);

            if ($itemRank >= 0 && ($best === null || $itemRank > $rank)) {
                $best = $item;
                $rank = $itemRank;
            }
        }

        return $best;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function language(string $contentType, int $id): ?string
    {
        $key = $contentType . '.' . $id;

        if (\array_key_exists($key, $this->languages)) {
            return $this->languages[$key];
        }

        $properties = ContentTypesHelper::getProperties($contentType);
        $stateField = (string) ($properties['stateField'] ?? 'state');
        $trashed    = self::TRASHED;
        $query      = $this->db->getQuery(true)
            ->select($this->db->quoteName('language'))
            ->from($this->db->quoteName((string) ($properties['table'] ?? '')))
            ->where($this->db->quoteName('id') . ' = :id')
            ->where($this->db->quoteName($stateField) . ' <> :trashed')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':trashed', $trashed, ParameterType::INTEGER);

        $language = $this->db->setQuery($query)->loadResult();

        return $this->languages[$key] = $language === null ? null : (string) $language;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function translation(string $contentType, int $id, string $language): ?int
    {
        $key = $contentType . '.' . $id;

        if (!isset($this->groups[$key])) {
            $this->groups[$key] = $this->group($contentType, $id);
        }

        return $this->groups[$key][$language] ?? null;
    }

    /**
     * The association group of an item, without trashed members: item id per language.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     *
     * @return  array<string, int>
     *
     * @since   2.0.0
     */
    private function group(string $contentType, int $id): array
    {
        $properties   = ContentTypesHelper::getProperties($contentType);
        $context      = (string) ($properties['context_associations'] ?? '');
        $stateField   = (string) ($properties['stateField'] ?? 'state');
        $groupContext = $context;
        $trashed      = self::TRASHED;

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['item.language', 'item.id']))
            ->from($this->db->quoteName('#__associations', 'own'))
            ->join(
                'INNER',
                $this->db->quoteName('#__associations', 'other'),
                $this->db->quoteName('other.key') . ' = ' . $this->db->quoteName('own.key')
            )
            ->join(
                'INNER',
                $this->db->quoteName((string) ($properties['table'] ?? ''), 'item'),
                $this->db->quoteName('item.id') . ' = ' . $this->db->quoteName('other.id')
            )
            ->where($this->db->quoteName('own.context') . ' = :context')
            ->where($this->db->quoteName('other.context') . ' = :groupContext')
            ->where($this->db->quoteName('own.id') . ' = :id')
            ->where($this->db->quoteName('item.' . $stateField) . ' <> :trashed')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $groupContext, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':trashed', $trashed, ParameterType::INTEGER);

        $group = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $group[(string) $row->language] = (int) $row->id;
        }

        return $group;
    }

    /**
     * The site menu items that are not trashed, read once.
     *
     * @return  array<int, MenuItem>
     *
     * @since   2.0.0
     */
    private function menuItems(): array
    {
        if ($this->menuItems !== null) {
            return $this->menuItems;
        }

        $client  = 0;
        $root    = 1;
        $trashed = self::TRASHED;

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'language', 'type', 'link', 'params']))
            ->from($this->db->quoteName('#__menu'))
            ->where($this->db->quoteName('client_id') . ' = :client')
            ->where($this->db->quoteName('id') . ' > :root')
            ->where($this->db->quoteName('published') . ' <> :trashed')
            ->order($this->db->quoteName('id'))
            ->bind(':client', $client, ParameterType::INTEGER)
            ->bind(':root', $root, ParameterType::INTEGER)
            ->bind(':trashed', $trashed, ParameterType::INTEGER);

        $this->menuItems = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $type = (string) $row->type;
            $link = [];
            parse_str((string) parse_url(html_entity_decode((string) $row->link), PHP_URL_QUERY), $link);

            $params      = json_decode((string) $row->params, true);
            $aliasTarget = $type === MenuItem::ALIAS && \is_array($params) && is_numeric($params['aliasoptions'] ?? null)
                ? (int) $params['aliasoptions']
                : 0;

            $item = new MenuItem((int) $row->id, (string) $row->language, $type, $link, $aliasTarget);

            $this->menuItems[$item->id] = $item;

            if ($type === 'component' && $item->opens() !== '' && $item->opensId() > 0) {
                $this->byTarget[$item->opens() . '.' . $item->opensId()][] = $item;
            }
        }

        return $this->menuItems;
    }
}
