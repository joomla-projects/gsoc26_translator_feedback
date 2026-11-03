<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Module;

use Joomla\CMS\Table\Module;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The menu modules of a translated menu: copies of the source menu's modules.
 *
 * A translated menu shows nowhere until a module shows it. Each menu module of the source menu
 * gets a copy for the language: the same type, position and settings, the language's menu, a
 * translated title, and the pages the source module is on, in that language. A copy is linked
 * to its source in `#__associations` with the context `com_modules.item`, which core does not
 * use for modules, so its pages can follow the menu as more of it is translated.
 *
 * A side menu that starts at level 2 shows the branch of whatever page it is on, so it is
 * assigned to the pages of its own branch only; every menu item translated later has to be added
 * to the pages of the copy that shows its branch, or the copy does not show there.
 *
 * @since  2.0.0
 */
final class MenuModules
{
    /**
     * The association context that links a copy to its source module.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CONTEXT = 'com_modules.item';

    /**
     * The database.
     *
     * @var    DatabaseInterface
     * @since  2.0.0
     */
    private DatabaseInterface $db;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   2.0.0
     */
    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Give a language's menu a copy of each module of the source menu that has none yet.
     *
     * A module made by hand for the language is taken as the copy rather than made again, when
     * it can be told apart: a menu module of that language showing the language's menu, at the
     * same position and from the same level, and - with more than one such module - on the
     * translations of the source module's pages. A source module on *All* languages would show
     * on every language's pages beside its copy, so it is given the source language.
     *
     * @param   string                       $sourceMenu      The source menu's menutype.
     * @param   string                       $targetMenu      The language's menutype.
     * @param   string                       $sourceLanguage  The source language code.
     * @param   string                       $targetLanguage  The language code.
     * @param   callable(string): string     $translateTitle  Translates a module title.
     * @param   callable(int): (int|null)    $translatedItem  The translation of a source menu item, or null.
     *
     * @return  int[]  The ids of the copies made.
     *
     * @since   2.0.0
     */
    public function copy(
        string $sourceMenu,
        string $targetMenu,
        string $sourceLanguage,
        string $targetLanguage,
        callable $translateTitle,
        callable $translatedItem
    ): array {
        $made = [];

        foreach ($this->sourceModules($sourceMenu, $sourceLanguage) as $source) {
            $sourceId = (int) $source['id'];

            if ($this->copyOf($sourceId, $targetLanguage) !== null) {
                continue;
            }

            $pages   = self::pages($this->pagesOf($sourceId), $translatedItem);
            $adopted = $this->madeByHand($source, $targetMenu, $targetLanguage, $pages);

            if ($source['language'] === '*') {
                $this->setLanguage($sourceId, $sourceLanguage);
            }

            if ($adopted !== null) {
                $this->associate($sourceId, $sourceLanguage, $adopted, $targetLanguage);

                continue;
            }

            $copyId = $this->store($source, $targetMenu, $targetLanguage, $translateTitle((string) $source['title']), $translatedItem);
            $this->setPages($copyId, $pages);
            $this->associate($sourceId, $sourceLanguage, $copyId, $targetLanguage);
            $made[] = $copyId;
        }

        return $made;
    }

    /**
     * Add a menu item's translation to the copies of the modules its source is on.
     *
     * The copies keep up with the menu: a page the source module is on, the copy is on in the
     * language as soon as it is translated, and a page the source module leaves out ("on all
     * pages except"), the copy leaves out too. A copy that names the source item as its base
     * names the translation.
     *
     * @param   int     $sourceItemId    The source menu item.
     * @param   int     $translationId   Its translation.
     * @param   string  $targetLanguage  The translation's language.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function follow(int $sourceItemId, int $translationId, string $targetLanguage): void
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['moduleid', 'menuid']))
            ->from($db->quoteName('#__modules_menu'))
            ->whereIn($db->quoteName('menuid'), [$sourceItemId, -$sourceItemId]);

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            $copyId = $this->copyOf((int) $row['moduleid'], $targetLanguage);

            if ($copyId === null) {
                continue;
            }

            $excluded = (int) $row['menuid'] < 0;

            // "On all pages except" with nothing excepted yet was stored as "on all pages".
            if ($excluded) {
                $this->deletePage($copyId, 0);
            }

            $this->deletePage($copyId, $excluded ? -$translationId : $translationId);
            $this->insertPages($copyId, [$excluded ? -$translationId : $translationId]);
        }

        foreach ($this->copiesOfModulesBasedOn($sourceItemId, $targetLanguage) as $copyId) {
            $this->setBase($copyId, (string) $translationId);
        }
    }

    /**
     * The pages of a copy, from the pages of its source module.
     *
     * `0` is every page and stays so. A positive id is a page the module is on, a negative one a
     * page it is not on; each becomes its translation, and one not translated yet is left out
     * until it is (see follow()). Leaving out every exception of "on all pages except" would put
     * the copy on no page, so it is on all pages instead until an exception is translated.
     *
     * @param   int[]                      $sourcePages     The source module's `#__modules_menu` ids.
     * @param   callable(int): (int|null)  $translatedItem  The translation of a source menu item, or null.
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    public static function pages(array $sourcePages, callable $translatedItem): array
    {
        $pages = [];

        foreach ($sourcePages as $page) {
            $page = (int) $page;

            if ($page === 0) {
                $pages[] = 0;

                continue;
            }

            $translation = $translatedItem(abs($page));

            if ($translation !== null) {
                $pages[] = $page < 0 ? -$translation : $translation;
            }
        }

        $allBut = $sourcePages !== [] && max(array_map('intval', $sourcePages)) < 0;

        return $pages === [] && $allBut ? [0] : array_values(array_unique($pages));
    }

    /**
     * A copy's params: the source module's, showing the language's menu from the translated base.
     *
     * `base` names the menu item whose branch the module shows. A source item names an item of
     * the source menu, so the copy names its translation, or none until it is translated.
     *
     * @param   string                     $params          The source module's params.
     * @param   string                     $targetMenu      The language's menutype.
     * @param   callable(int): (int|null)  $translatedItem  The translation of a source menu item, or null.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public static function params(string $params, string $targetMenu, callable $translatedItem): string
    {
        $registry = new Registry($params);
        $registry->set('menutype', $targetMenu);
        $base = (int) $registry->get('base', 0);

        if ($base > 0) {
            $registry->set('base', (string) ($translatedItem($base) ?? ''));
        }

        return $registry->toString();
    }

    /**
     * The menu modules of the source menu: in the source language or on all languages.
     *
     * @param   string  $sourceMenu      The source menu's menutype.
     * @param   string  $sourceLanguage  The source language code.
     *
     * @return  array<int, array<string, mixed>>
     *
     * @since   2.0.0
     */
    private function sourceModules(string $sourceMenu, string $sourceLanguage): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' IN (0, 1)')
            ->whereIn($db->quoteName('language'), [$sourceLanguage, '*'], ParameterType::STRING)
            ->order($db->quoteName('id') . ' ASC');

        return array_values(array_filter(
            $db->setQuery($query)->loadAssocList(),
            static fn (array $module): bool => (string) (new Registry($module['params']))->get('menutype') === $sourceMenu
        ));
    }

    /**
     * The copy of a module in a language, through its association group.
     *
     * @param   int     $moduleId  The source module.
     * @param   string  $language  The language.
     *
     * @return  int|null
     *
     * @since   2.0.0
     */
    private function copyOf(int $moduleId, string $language): ?int
    {
        $db      = $this->db;
        $context = self::CONTEXT;
        $query   = $db->getQuery(true)
            ->select($db->quoteName('copy.id'))
            ->from($db->quoteName('#__associations', 'source'))
            ->join('INNER', $db->quoteName('#__associations', 'copy'), $db->quoteName('copy.key') . ' = ' . $db->quoteName('source.key'))
            ->join('INNER', $db->quoteName('#__modules', 'module'), $db->quoteName('module.id') . ' = ' . $db->quoteName('copy.id'))
            ->where($db->quoteName('source.context') . ' = :context')
            ->where($db->quoteName('copy.context') . ' = :copyContext')
            ->where($db->quoteName('source.id') . ' = :moduleId')
            ->where($db->quoteName('module.language') . ' = :language')
            ->bind(':context', $context)
            ->bind(':copyContext', $context)
            ->bind(':moduleId', $moduleId, ParameterType::INTEGER)
            ->bind(':language', $language);
        $id = $db->setQuery($query)->loadResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * A module made by hand as the copy of a source module, when it can be told apart.
     *
     * @param   array<string, mixed>  $source          The source module.
     * @param   string                $targetMenu      The language's menutype.
     * @param   string                $targetLanguage  The language code.
     * @param   int[]                 $pages           The pages the copy would be on.
     *
     * @return  int|null  Null when there is none, or more than one that fits as well.
     *
     * @since   2.0.0
     */
    private function madeByHand(array $source, string $targetMenu, string $targetLanguage, array $pages): ?int
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'params']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' IN (0, 1)')
            ->where($db->quoteName('position') . ' = :position')
            ->where($db->quoteName('language') . ' = :language')
            ->bind(':position', $source['position'])
            ->bind(':language', $targetLanguage);
        $startLevel = (int) (new Registry($source['params']))->get('startLevel', 1);
        $candidates = [];

        foreach ($db->setQuery($query)->loadAssocList() as $module) {
            $params = new Registry($module['params']);

            if ((string) $params->get('menutype') === $targetMenu
                && (int) $params->get('startLevel', 1) === $startLevel
                && !$this->isAssociated((int) $module['id'])) {
                $candidates[(int) $module['id']] = \count(array_intersect($pages, $this->pagesOf((int) $module['id'])));
            }
        }

        if (\count($candidates) === 1) {
            return (int) array_key_first($candidates);
        }

        // Several: the one on most of the pages, when it is the only one.
        arsort($candidates);
        $best = array_slice($candidates, 0, 2, true);

        if ($best !== [] && reset($best) > 0 && (\count($best) === 1 || array_values($best)[0] > array_values($best)[1])) {
            return (int) array_key_first($best);
        }

        return null;
    }

    /**
     * Store a copy of a source module.
     *
     * @param   array<string, mixed>       $source          The source module.
     * @param   string                     $targetMenu      The language's menutype.
     * @param   string                     $targetLanguage  The language code.
     * @param   string                     $title           The translated title.
     * @param   callable(int): (int|null)  $translatedItem  The translation of a source menu item, or null.
     *
     * @return  int  The copy's id.
     *
     * @throws  \RuntimeException  When the module cannot be stored.
     *
     * @since   2.0.0
     */
    private function store(array $source, string $targetMenu, string $targetLanguage, string $title, callable $translatedItem): int
    {
        $table = new Module($this->db);
        $data  = $source;

        unset($data['id'], $data['asset_id'], $data['checked_out'], $data['checked_out_time']);
        $data['title']    = $title;
        $data['language'] = $targetLanguage;
        $data['params']   = self::params((string) $source['params'], $targetMenu, $translatedItem);

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException('The menu module "' . $source['title'] . '" could not be copied.');
        }

        return (int) $table->id;
    }

    /**
     * The `#__modules_menu` ids of a module.
     *
     * @param   int  $moduleId  The module.
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    private function pagesOf(int $moduleId): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('menuid'))
            ->from($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = :moduleId')
            ->bind(':moduleId', $moduleId, ParameterType::INTEGER);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * Give a copy its pages.
     *
     * @param   int    $moduleId  The copy.
     * @param   int[]  $pages     Its `#__modules_menu` ids.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setPages(int $moduleId, array $pages): void
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = :moduleId')
            ->bind(':moduleId', $moduleId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $this->insertPages($moduleId, $pages);
    }

    /**
     * Add pages to a module.
     *
     * @param   int    $moduleId  The module.
     * @param   int[]  $pages     The `#__modules_menu` ids to add.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function insertPages(int $moduleId, array $pages): void
    {
        if ($pages === []) {
            return;
        }

        $db    = $this->db;
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__modules_menu'))
            ->columns($db->quoteName(['moduleid', 'menuid']));

        foreach ($pages as $page) {
            $query->values($moduleId . ', ' . (int) $page);
        }

        $db->setQuery($query)->execute();
    }

    /**
     * Take a page from a module.
     *
     * @param   int  $moduleId  The module.
     * @param   int  $page      The `#__modules_menu` id.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function deletePage(int $moduleId, int $page): void
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__modules_menu'))
            ->where($db->quoteName('moduleid') . ' = :moduleId')
            ->where($db->quoteName('menuid') . ' = :page')
            ->bind(':moduleId', $moduleId, ParameterType::INTEGER)
            ->bind(':page', $page, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * The copies in a language of the modules whose base is a source menu item.
     *
     * @param   int     $sourceItemId    The source menu item.
     * @param   string  $targetLanguage  The language.
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    private function copiesOfModulesBasedOn(int $sourceItemId, string $targetLanguage): array
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'params']))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_menu'))
            ->where($db->quoteName('client_id') . ' = 0');
        $copies = [];

        foreach ($db->setQuery($query)->loadAssocList() as $module) {
            if ((int) (new Registry($module['params']))->get('base', 0) === $sourceItemId) {
                $copyId = $this->copyOf((int) $module['id'], $targetLanguage);

                if ($copyId !== null && $copyId !== (int) $module['id']) {
                    $copies[] = $copyId;
                }
            }
        }

        return $copies;
    }

    /**
     * Set a module's base.
     *
     * @param   int     $moduleId  The module.
     * @param   string  $base      The menu item id.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setBase(int $moduleId, string $base): void
    {
        $db     = $this->db;
        $query  = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $moduleId, ParameterType::INTEGER);
        $params = new Registry((string) $db->setQuery($query)->loadResult());
        $params->set('base', $base);
        $json = $params->toString();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__modules'))
            ->set($db->quoteName('params') . ' = :params')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $moduleId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Set a module's language.
     *
     * @param   int     $moduleId  The module.
     * @param   string  $language  The language code.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setLanguage(int $moduleId, string $language): void
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__modules'))
            ->set($db->quoteName('language') . ' = :language')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':language', $language)
            ->bind(':id', $moduleId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Whether a module is in an association group.
     *
     * @param   int  $moduleId  The module.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private function isAssociated(int $moduleId): bool
    {
        return $this->groupKey($moduleId) !== null;
    }

    /**
     * The association key of a module's group.
     *
     * @param   int  $moduleId  The module.
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    private function groupKey(int $moduleId): ?string
    {
        $db      = $this->db;
        $context = self::CONTEXT;
        $query   = $db->getQuery(true)
            ->select($db->quoteName('key'))
            ->from($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':context', $context)
            ->bind(':id', $moduleId, ParameterType::INTEGER);
        $key = $db->setQuery($query)->loadResult();

        return $key === null ? null : (string) $key;
    }

    /**
     * Link a copy to its source module, in the source's group or a new one.
     *
     * @param   int     $sourceId        The source module.
     * @param   string  $sourceLanguage  The source language.
     * @param   int     $copyId          The copy.
     * @param   string  $targetLanguage  The copy's language.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function associate(int $sourceId, string $sourceLanguage, int $copyId, string $targetLanguage): void
    {
        $db   = $this->db;
        $key  = $this->groupKey($sourceId);
        $rows = [$copyId];

        // Joomla keys a group with the hash of its members by language.
        if ($key === null) {
            $key  = md5(json_encode([$sourceLanguage => $sourceId, $targetLanguage => $copyId]) ?: '');
            $rows = [$sourceId, $copyId];
        }

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__associations'))
            ->columns($db->quoteName(['id', 'context', 'key']));

        foreach ($rows as $id) {
            $query->values($id . ', ' . $db->quote(self::CONTEXT) . ', ' . $db->quote($key));
        }

        $db->setQuery($query)->execute();
    }
}
