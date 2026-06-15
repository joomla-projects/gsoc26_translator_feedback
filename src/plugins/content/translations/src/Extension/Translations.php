<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Content\Translations\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

/**
 * Adds a "no need for translation" toggle to the article form and stores it on the
 * article's #__translations_queue row, so the Translations component skips it.
 *
 * @since  0.3.0
 */
final class Translations extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.3.0
     */
    protected $autoloadLanguage = true;

    /**
     * Content type stored in #__translations_queue for articles (matches the component).
     *
     * @var    string
     * @since  0.3.0
     */
    private const CONTENT_TYPE = 'com_content.article';

    /**
     * The form field this plugin adds to the article form.
     *
     * @var    string
     * @since  0.3.0
     */
    private const FIELD = 'no_need_for_translation';

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareData' => 'onContentPrepareData',
            'onContentPrepareForm' => 'onContentPrepareForm',
            'onContentAfterSave'   => 'onContentAfterSave',
        ];
    }

    /**
     * Add the "no need for translation" toggle to the source-language article form.
     *
     * @param   PrepareFormEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->getName() !== 'com_content.article') {
            return;
        }

        // Load the toggle for source-language articles and new ones (language not set yet).
        $data     = $event->getData();
        $language = (string) (\is_object($data) ? ($data->language ?? '') : ($data['language'] ?? ''));

        if ($language !== '' && $language !== $this->getSourceLanguage()) {
            return;
        }

        $form->loadFile(\dirname(__DIR__, 2) . '/forms/translationoptout.xml');
    }

    /**
     * Default a new article's language to the source language, and reflect the queue flag on the toggle.
     *
     * A new article has no language yet, so it is set to the source language here. For an existing article
     * the displayed toggle value is set from the queue before the form binds the stored attribs (the form
     * binds after onContentPrepareForm runs), so it survives even after the flag was cleared from the grid.
     *
     * @param   PrepareDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $data = $event->getData();

        if (!\is_object($data)) {
            return;
        }

        // A new article has no language yet; default it to the source language.
        if (empty($data->id) && (string) ($data->language ?? '') === '') {
            $data->language = $this->getSourceLanguage();

            return;
        }

        // The edit form supplies the item with attribs as an array; leave other shapes alone.
        if (empty($data->id) || !\is_array($data->attribs ?? null)) {
            return;
        }

        $data->attribs[self::FIELD] = $this->isFlagged((int) $data->id) ? 1 : 0;
    }

    /**
     * Persist the toggle to the article's queue row when a source-language article is saved.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $article = $event->getItem();

        // Only source-language originals are tracked in the queue.
        if ((string) ($article->language ?? '') !== $this->getSourceLanguage()) {
            return;
        }

        // The toggle lives in the article's attribs group, so it arrives nested under 'attribs'.
        $data           = $event->getData();
        $attribs        = $data['attribs'] ?? [];
        $doNotTranslate = (int) (\is_array($attribs) ? ($attribs[self::FIELD] ?? 0) : 0);

        $this->storeFlag((int) $article->id, $doNotTranslate);
    }

    /**
     * The component's configured source language.
     *
     * @return  string
     *
     * @since   0.3.0
     */
    private function getSourceLanguage(): string
    {
        return (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
    }

    /**
     * Whether the article is currently flagged "no need for translation".
     *
     * @param   integer  $articleId  The article id.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function isFlagged(int $articleId): bool
    {
        // Bound parameters are passed by reference, so the constant needs a variable.
        $contentType = self::CONTENT_TYPE;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('do_not_translate'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $articleId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Store the flag on the article's queue row, creating the row only to record an opt-out.
     *
     * @param   integer  $articleId       The article id.
     * @param   integer  $doNotTranslate  1 to opt out of translation, 0 to allow it.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function storeFlag(int $articleId, int $doNotTranslate): void
    {
        // Bound parameters are passed by reference, so the constant needs a variable.
        $contentType = self::CONTENT_TYPE;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $articleId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        if ($queueId !== null) {
            $row = (object) [
                'id'               => (int) $queueId,
                'do_not_translate' => $doNotTranslate,
            ];
            $db->updateObject('#__translations_queue', $row, 'id');

            return;
        }

        // No row yet: create one only to record an opt-out, never just to store the default.
        if ($doNotTranslate === 0) {
            return;
        }

        $row = (object) [
            'content_type'     => $contentType,
            'content_id'       => $articleId,
            'do_not_translate' => 1,
        ];
        $db->insertObject('#__translations_queue', $row);
    }
}
