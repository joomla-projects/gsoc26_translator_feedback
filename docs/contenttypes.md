# The content type map (`contenttypes.json`)

`contenttypes.json` is the file that tells the translation pipeline how to handle each
kind of content. The producer (`TranslationModel`) is written to stay independent of
any single content type: instead of hard-coding article columns, it reads what each
type needs from this map. Adding support for a new content type is then mostly a new
entry in this file.

This document explains the structure and every key. It is developer documentation;
for the words used here (producer, draft, association, and so on) see
[glossary.md](glossary.md).

## Structure

The file is a single object with one `contentTypes` member, keyed by content type:

```json
{
    "contentTypes": {
        "com_content.article": { ... },
        "com_categories.category": { ... },
        "com_tags.tag": { ... },
        "com_menus.item": { ... }
    }
}
```

The key is the content type **alias**, `extension.typeName` (for example
`com_content.article`). The same string is stored as `content_type` in
`#__translations_queue` and is the value passed to `TranslationModel::translate()`, so
one string identifies the type everywhere.

The map is read once, cached, and looked up per type. It is registered in the
component manifest and shipped in
`administrator/components/com_translations/contenttypes.json`.

## Keys

Each content type entry may use the following keys.

**`translatableFields`** (array, required) - the fields whose values are translated.
Each item is one of:

- a string: a column name on the type's table whose value is translated directly
  (`"title"`, `"introtext"`);
- an object mapping a JSON column to the sub-keys inside it that are translatable, for
  example `{ "images": ["image_intro_alt", "image_intro_caption"] }`. These are
  gathered under a dotted path (`images.image_intro_alt`) so the rest of the value,
  such as the image path, is left untouched.

**`component`** (string, required) - the managing component booted to save the draft,
for example `com_content`. Saving through the component's own model means its workflow,
versioning and other behaviours run as for a hand-made item.

**`model`** (string, required) - the admin model used to save the draft, for example
`Article`. It is created from the managing component's MVC factory.

**`table`** (string, required) - the database table the source item is read from and
the draft is stored in, for example `#__content`.

**`stateField`** (string, required) - the publish-state column, set to `0` so the draft
is unpublished until a translator approves it. It differs per type (`state` for
articles, `published` for categories, tags and menu items).

**`draftCopyFields`** (array, required) - source columns copied onto the draft
unchanged. These are the structural, untranslated fields a new item needs, such as an
article's `catid`, `access` and `created_by`.

**`context_associations`** (string, optional) - the `#__associations` context that ties
the language versions of an item together, for example `com_content.item`. It is what
links a translated draft back to its source. Omit it for a type that is not associable.

**`associationsByModel`** (boolean, optional, default `true`) - whether the managing
model writes the `#__associations` link itself when the draft is saved. Core models
that declare an `associationsContext` (articles, categories) do, so this is left out.
Tags do not, so their entry sets `"associationsByModel": false` and the component
writes the association row directly instead.

**`modelState`** (object, optional) - state values to set on the managing model before
the draft is saved, as `state-key: value`. Some core models read request-scoped state in
their `populateState`, which the component skips because it hands the model its data
directly. A category model reads its extension from the `category.extension` state to
confirm the item can be associated, so the category entry sets
`{ "category.extension": "com_content" }`; without it the association would not be written.

**`limitToExtension`** (string, optional) - restrict translation to items whose
`extension` column matches this value. Some tables hold several extensions' items;
categories, for example, are shared by many components, so the category entry sets
`"limitToExtension": "com_content"` to translate only content categories.

**`draftForceFields`** (object, optional) - fields pinned to a fixed value on the draft,
regardless of the source. Where `draftCopyFields` copies the source value, each entry
here is `column: value`. A menu item uses `{ "home": 0, "parent_id": 1 }` so a
translation never becomes the site's home page and is placed at the menu root.

**`languageMenu`** (string, optional) - names the column holding the item's
language-specific container. Menu items live in a per-language menu, so this is set to
`menutype`. The producer derives the target-language menu from the source's value
(stripping any source-language suffix and appending the target language code, so
`mainmenu` becomes `mainmenu-fr-fr`), creates that menu if it does not yet exist, and
places the draft in it.

**`associatedFields`** (object, optional) - foreign-key fields that point at another
content type and should be re-pointed at the translated related item. For an article,
`{ "catid": { "contentType": "category" } }` says the draft's `catid` should become the
translated category's id. It is read by the related-item remapping, a later pipeline
step that is not yet implemented.

**`m2m_relation`** (string, optional) - a many-to-many related content type, such as an
article's `"tag"`, whose ids are remapped to the translated tags. Also read by the
related-item remapping.

## Example: the article entry

```json
"com_content.article": {
    "translatableFields": [
        "title", "introtext", "fulltext", "metadesc", "metakey", "note",
        { "images": ["image_intro_alt", "image_intro_caption", "image_fulltext_alt", "image_fulltext_caption"] }
    ],
    "component": "com_content",
    "model": "Article",
    "table": "#__content",
    "context_associations": "com_content.item",
    "draftCopyFields": ["catid", "access", "created_by"],
    "stateField": "state",
    "associatedFields": { "catid": { "contentType": "category" } },
    "m2m_relation": "tag"
}
```

## Example: the menu item entry

A menu item translates only its `title`. It is placed in the derived per-language menu
at the root with `home` forced off; its `link` still points at the source content until
the related-item remapping re-points it.

```json
"com_menus.item": {
    "translatableFields": ["title"],
    "component": "com_menus",
    "model": "Item",
    "table": "#__menu",
    "context_associations": "com_menus.item",
    "draftCopyFields": ["link", "type", "component_id", "browserNav", "access", "img", "template_style_id", "params"],
    "stateField": "published",
    "draftForceFields": { "home": 0, "parent_id": 1 },
    "languageMenu": "menutype"
}
```
