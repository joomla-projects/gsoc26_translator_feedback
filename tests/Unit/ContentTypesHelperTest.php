<?php

/**
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * The content type map that ships with the component, and the lookups over it.
 *
 * contenttypes.json is data, so nothing in PHP fails when an entry is wrong: a relation
 * pointing at a type that is not in the map, or a type alias that no longer matches what
 * Joomla versions an item under, surfaces as a translation that quietly points at the
 * source item. These tests read the map as shipped, so adding a content type is checked by
 * running the suite.
 *
 * @since  1.0.1
 */
final class ContentTypesHelperTest extends TestCase
{
    /**
     * Drop the map the helper cached, so a test that replaced it cannot reach the next one.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    protected function tearDown(): void
    {
        self::setMap(null);

        parent::tearDown();
    }

    /**
     * Every mapped type is translated after the types its items point at.
     *
     * An article is pointed at the translation of its category, so the category has to have
     * one by then. Stating the rule rather than the order means a new content type is checked
     * by this test instead of listed in it.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEveryTypeFollowsTheTypesItRelatesTo(): void
    {
        $ordered = ContentTypesHelper::getContentTypesInDependencyOrder();

        $mapped = ContentTypesHelper::getContentTypes();
        $placed = $ordered;
        sort($mapped);
        sort($placed);

        $this->assertSame($mapped, $placed, 'Every mapped content type is ordered exactly once');

        foreach ($ordered as $position => $contentType) {
            // A type pointing at its own items, as a menu item at its parent, cannot precede itself;
            // the translation waits at its fallback and is moved once the parent is translated.
            foreach (array_diff(self::relatedTypes($contentType), [$contentType]) as $relatedType) {
                $this->assertLessThan(
                    $position,
                    array_search($relatedType, $ordered, true),
                    \sprintf('%s is translated after %s, which its items point at', $contentType, $relatedType)
                );
            }
        }
    }

    /**
     * Every mapped type says which items share an alias space with its drafts.
     *
     * Without it the draft keeps whatever its title gives it, and the managing component
     * refuses the save as soon as another language got to that alias first - after the
     * translation has been paid for. A content type added without an alias scope would
     * translate for a while and then start failing on the languages furthest down the list.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testEveryTypeNamesWhatScopesItsAlias(): void
    {
        foreach (ContentTypesHelper::getContentTypes() as $contentType) {
            $scope = ContentTypesHelper::getProperties($contentType)['aliasScope'] ?? null;

            $this->assertIsArray($scope, \sprintf('%s names the columns that scope its alias', $contentType));
            $this->assertNotEmpty($scope, \sprintf('%s names at least one column', $contentType));

            foreach ($scope as $column) {
                $this->assertIsString($column, \sprintf('%s scopes its alias by column names', $contentType));
            }
        }
    }

    /**
     * A relation that names a type the map does not hold is reported, not skipped.
     *
     * It is a typo in a data file, and the alternative to failing here is an item pointed at
     * an untranslated relation for as long as nobody looks.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnUnmappedRelationIsReported(): void
    {
        self::setMap(
            [
                'com_content.article' => ['associatedFields' => ['catid' => 'com_recipes.recipe']],
            ]
        );

        $this->expectException(RuntimeException::class);

        ContentTypesHelper::getContentTypesInDependencyOrder();
    }

    /**
     * Two types that point at each other are ordered rather than recursed into forever.
     *
     * One of them has to be translated first whatever the map says, so the ordering settles
     * on an order instead of exhausting the stack.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testARelationCycleStillProducesAnOrder(): void
    {
        self::setMap(
            [
                'com_a.one' => ['associatedFields' => ['b' => 'com_b.two']],
                'com_b.two' => ['associatedFields' => ['a' => 'com_a.one']],
            ]
        );

        $this->assertSame(['com_b.two', 'com_a.one'], ContentTypesHelper::getContentTypesInDependencyOrder());
    }

    /**
     * A content type is found by the type alias Joomla versions it under, which is not our key.
     *
     * A category is versioned under the extension that owns it, so reading the version of a
     * translated category depends on this being looked up rather than assumed.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAContentTypeIsFoundByItsVersionTypeAlias(): void
    {
        $this->assertSame(
            'com_categories.category',
            ContentTypesHelper::getContentTypeForVersionTypeAlias('com_content.category')
        );

        $this->assertNull(
            ContentTypesHelper::getContentTypeForVersionTypeAlias('com_banners.banner'),
            'A type this package does not translate matches nothing'
        );
    }

    /**
     * A content type is found by the form its "no need for translation" toggle is added to.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAContentTypeIsFoundByItsOptOutForm(): void
    {
        $this->assertSame(
            'com_content.article',
            ContentTypesHelper::getContentTypeForOptOutForm('com_content.article')
        );

        $this->assertSame(
            'com_categories.category',
            ContentTypesHelper::getContentTypeForOptOutForm('com_categories.categorycom_content'),
            'A category form is named after the extension that owns it'
        );

        $this->assertNull(ContentTypesHelper::getContentTypeForOptOutForm('com_banners.banner'));
    }

    /**
     * Asking for a type the map does not hold is an error rather than an empty answer.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAskingForAnUnmappedTypeFails(): void
    {
        $this->expectException(RuntimeException::class);

        ContentTypesHelper::getProperties('com_banners.banner');
    }

    /**
     * Every mapped type names the fields to translate and the table to read them from.
     *
     * The pipeline reads both without checking, so a type missing either is a fatal further on.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEveryMappedTypeCarriesWhatThePipelineReads(): void
    {
        foreach (ContentTypesHelper::getContentTypes() as $contentType) {
            $properties = ContentTypesHelper::getProperties($contentType);

            $this->assertNotEmpty($properties['translatableFields'] ?? [], $contentType . ' has fields to translate');
            $this->assertNotEmpty($properties['table'] ?? '', $contentType . ' names its table');
            $this->assertNotEmpty($properties['stateField'] ?? '', $contentType . ' names its state column');
        }
    }

    /**
     * The types one type's items point at, read from the map the same way the helper reads them.
     *
     * @param   string  $contentType  The content type key.
     *
     * @return  string[]  The related content type keys.
     *
     * @since   1.0.1
     */
    private static function relatedTypes(string $contentType): array
    {
        $properties = ContentTypesHelper::getProperties($contentType);
        $related    = array_merge(
            array_values((array) ($properties['associatedFields'] ?? [])),
            array_values((array) ($properties['m2m_relation'] ?? []))
        );

        foreach ((array) ($properties['linkTargets'] ?? []) as $parameters) {
            $related = array_merge($related, array_values((array) $parameters));
        }

        $related = array_merge($related, array_values((array) ($properties['paramTargets'] ?? [])));

        return array_values(array_unique($related));
    }

    /**
     * Replace the map the helper caches, or drop it so the shipped one is read again.
     *
     * @param   array|null  $map  The map to use, or null to read contenttypes.json again.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    private static function setMap(?array $map): void
    {
        $property = new ReflectionProperty(ContentTypesHelper::class, 'map');
        $property->setValue(null, $map);
    }
}
