<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Extension\TranslationsLanguageFiles;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguageFileTranslator;

/**
 * Shows in the task's form how far each language has got, with the three ways to get the files:
 * one download for the repository, an installable package per language, and installing that
 * package on this site.
 *
 * @since  1.0.0
 */
class LanguagefilesstatusField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Languagefilesstatus';

    /**
     * The field shows the whole width of the form.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $hiddenLabel = true;

    /**
     * Render the progress table and the buttons.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    protected function getInput()
    {
        $db         = Factory::getContainer()->get(DatabaseInterface::class);
        $translator = new LanguageFileTranslator($db, Factory::getContainer()->get(DispatcherInterface::class));

        try {
            $status  = $translator->status(TranslationsLanguageFiles::targetLanguages($db));
            $package = TranslationsLanguageFiles::package($db, $translator);
        } catch (\Throwable $e) {
            return '<div class="alert alert-warning">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $escape     = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $canInstall = Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_installer');
        $return     = base64_encode(Uri::getInstance()->toString());
        $rows       = '';

        foreach ($status as $language => $counts) {
            $buttons = '';

            if ($counts['translated'] > 0) {
                $url      = TranslationsLanguageFiles::DOWNLOAD_URL . '&language=' . rawurlencode($language);
                $buttons .= '<a class="btn btn-sm btn-secondary" href="' . $escape(Route::_($url . '&deliver=package', false)) . '">'
                    . '<span class="icon-download" aria-hidden="true"></span> '
                    . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_DOWNLOAD_PACKAGE') . '</a> ';

                if ($canInstall) {
                    $url .= '&deliver=install&return=' . rawurlencode($return) . '&' . Session::getFormToken() . '=1';
                    $buttons .= '<a class="btn btn-sm btn-secondary" href="' . $escape(Route::_($url, false)) . '">'
                        . '<span class="icon-upload" aria-hidden="true"></span> '
                        . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_INSTALL') . '</a>';
                }
            }

            $rows .= \sprintf(
                '<tr><th scope="row">%s</th><td>%d / %d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
                $escape($language),
                $counts['translated'],
                $counts['total'],
                $counts['failed'],
                $escape($package->installedVersion($language) ?? '-'),
                $buttons
            );
        }

        return '<p>' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_DELIVER_INTRO') . '</p>'
            . '<table class="table table-sm w-auto"><thead><tr>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_LANGUAGE') . '</th>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_TRANSLATED') . '</th>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_FAILED') . '</th>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_INSTALLED') . '</th>'
            . '<th scope="col"><span class="visually-hidden">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_ACTIONS') . '</span></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<a class="btn btn-primary" href="' . $escape(Route::_(TranslationsLanguageFiles::DOWNLOAD_URL, false)) . '">'
            . '<span class="icon-download" aria-hidden="true"></span> '
            . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_DOWNLOAD') . '</a>';
    }
}
