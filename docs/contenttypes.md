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

Each key is the **content type key**, `extension.typeName` (for example
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

**`versionTypeAlias`** (string, optional) - the type alias Joomla files this type's
version history under in `#__history`, for example `com_content.category`. It is not
always the same as our key for the content type, because a category is versioned under
the extension that owns it: a com_content category is versioned as
`com_content.category` while our key for it is `com_categories.category`. The alias is
read when recording which version of a source item a translation was made from, and
again when a newly stored version sends that translation back for re-translation.

Omit it for a type Joomla does not version. Menu items have no version history at all,
so their entry carries no version type alias, no version is recorded for them and a
stored version can never match them.

The setting that enables version history belongs to the extension named in the alias and
not to the managing component: Joomla reads `save_history` from the alias's extension, so
a com_content category follows com_content's **Enable Versions** setting, the same one
that governs articles. Having it on is a prerequisite for the component, see
[user-guide.md](user-guide.md).

**`optOutForm`** (string, optional) - the edit form the "no need for translation" toggle is
added to. Like the version type alias, this is not always our key for the content type: a
com_content category is edited on `com_categories.categorycom_content` while our key for it
is `com_categories.category`. Naming the site menu item form also excludes administrator menu
items, which Joomla edits on `com_menus.item.admin`.

Omit it for a type that is not offered the toggle. The toggle is submitted under a
`com_translations` form group, and no content table has a column of that name, so the flag is
kept in the queue alone and nothing is written onto the item itself.

**`stateField`** (string, required) - the publish-state column. The producer writes it
only when the draft is new: `0` so the translation waits for a translator, or `1` when
the component is set to publish new translations. Re-translating leaves the column out
of the save altogether, so an existing translation keeps the state it has. It differs
per type (`state` for articles, `published` for categories, tags and menu items).

**`draftCopyFields`** (array, required) - source columns copied onto the draft
unchanged. These are the structural, untranslated fields a new item needs, such as an
article's `catid`, `access` and `created_by`.

**`aliasScope`** (array, optional) - the columns that decide which items share an alias
space with the draft. Joomla keeps an alias unique among an item's neighbours and refuses
a save that would clash, and what counts as a neighbour differs per type: an article's
alias has to be free within its category (`["catid"]`), a category's within its parent and
extension (`["parent_id", "extension"]`), a tag's within its parent (`["parent_id"]`). The
producer reads the aliases held there and appends the language code, then a number, until
it has one that is free.

`language` is a column like any other here, but it means what Joomla means by it: the
draft's own language, and the items that apply to all of them. Only a type Joomla scopes
that way names it, which among these is the menu item; for an article, a category or a tag
the language is no part of the rule, and naming it would let a save be refused after the
translation has been paid for. Leaving it out where Joomla does use it only makes an alias
longer than it had to be, which for a menu item is the URL, so it is named there.

A type that names no columns leaves its alias to the managing component, which is what
every type did before 1.4.2.

**`aliasReservedAtRoot`** (boolean, optional, default `false`) - whether an alias at the
root of the tree is also kept off the names Joomla reserves there: `component`, and every
folder in the site root. A menu item at the root answers a URL directly under the site, so
Joomla refuses it those names, and a translated menu item is placed there whenever its source
sits there or its source's parent has no translation yet. The
words are ordinary ones - a menu item translated as "Media", "Plugins" or "Modules" lands
on one - so they are counted as taken and the alias steps aside to `media-pt-pt` rather
than being refused.

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

**`limitToClient`** (integer, optional) - restrict translation to items whose `client_id`
column matches this value. Menu items are stored for both clients in one table and Joomla
associates them on the site client alone, so the menu entry sets `"limitToClient": 0`;
an administrator menu item is never translated. Both this and `limitToExtension` narrow
the queue list as well as the translation itself, so an item the component cannot
translate is never offered.

**`draftForceFields`** (object, optional) - fields pinned to a fixed value on the draft,
regardless of the source. Where `draftCopyFields` copies the source value, each entry
here is `column: value`, or a value the managing model reads beside the columns. A menu
item uses `{ "home": 0, "menuordering": 0 }`: a translation never becomes the site's home
page, and translating it again leaves it where it is among its siblings, where Joomla's
menu item model would otherwise read an ordering that was never given.

**`languageMenu`** (string, optional) - names the column holding the item's
language-specific container. Menu items live in a per-language menu, so this is set to
`menutype`. The producer derives the target-language menu from the source's value
(stripping any source-language suffix and appending the target language code, so
`mainmenu` becomes `mainmenu-fr-fr`), creates that menu if it does not yet exist, and
places the draft in it.

**`context_custom_fields`** (string, optional) - the field context the item's translatable
custom fields are read from. Articles use `com_content.article`; categories use
`com_content.categories` (the context the fields system rewrites a category to, which
differs from the map key). Omit it for a type without translatable custom fields. The
translatable field types are text, textarea, editor and note; other custom fields are
copied to the draft unchanged.

**`copyCustomFieldAssignments`** (boolean, optional, default `false`) - whether to copy the
source category's directly assigned custom fields onto the translated category. A category's
custom fields are scoped to the category id, so a field assigned only to the source category
does not reach the new draft on save; the category entry sets this to `true` so those fields
are assigned to the draft and their values written. Global and parent-assigned fields already
reach the draft.

**`associatedFields`** (object, optional) - foreign-key fields that point at another
content type and are re-pointed at the translated related item. Each entry maps a field to
the related content type, for an article `{ "catid": "com_categories.category" }`, so the
draft's `catid` becomes that category's translation. The related type's
`context_associations` and `table` are used to find the translation; when none exists yet,
the source's value is kept.

A field can point at the type's own items, as a menu item's `{ "parent_id": "com_menus.item" }`
does: the translation is placed under the translation of its source's parent. When the parent is
translated after the child, the child's translation is moved under it then, with an alias free
among its new neighbours.

**`associatedFieldsFallback`** (object, optional) - the value an `associatedFields` field takes
while its related item has no translation yet, for a field whose source value cannot hold in the
target language. A menu item's parent lives in the source language menu, so the menu item entry
sets `{ "parent_id": 1 }`: the translation waits at the root of its menu until its parent is
translated. A translation someone has moved elsewhere by hand in the meantime stays where it is.

**`m2m_relation`** (object, optional) - many-to-many related items to re-point, mapping the
data key written onto the draft to the related content type, for an article
`{ "tags": "com_tags.tag" }`. The source's tag ids are remapped to their translations and
written back under that key; an id with no translation yet is kept.

**`linkTargets`** (object, optional) - the related items a link points at, re-pointed at their
translations. A menu item names its target in the query of its `link` rather than in a column, so
`associatedFields` cannot reach it. Each entry is keyed by the link's `option` and `view`, and maps
each query parameter that carries an id to the content type that id belongs to:

```json
"com_content.category": { "id": "com_categories.category", "filter_tag": "com_tags.tag" }
```

A view can carry more than one, as a category listing does with its category and its tag filter, and
a parameter can carry several ids, as the archived articles and tagged items views do. A parameter
the link does not carry is skipped, and a link carrying none of them is left exactly as it is. An id
of `0` is the root of a listing rather than an item, so it is left alone, and an id whose item has no
translation yet keeps pointing at the source, as a foreign key does, until that item is
translated: the target language's translated menu items still pointing at the source are then
pointed at its translation.

Only views whose ids name a content type in this map can be re-pointed. A contact or news feed
category is a `#__categories` row belonging to another extension, which `limitToExtension` excludes,
so those menu items are left untouched. An id that lives in a menu item's `params` rather than its
`link` is re-pointed through `paramTargets`.

**`paramTargets`** (object, optional) - the related items named in the item's `params`, re-pointed
at their translations, mapping each param name to the content type its id belongs to. A menu item
names more than its page: an alias names the menu item it stands for (`aliasoptions`), a login
form the menu items it redirects to, the featured articles view the categories it shows
(`featured_categories`, a list). It works as `linkTargets` does: a param the item does not have is
skipped, an id without a translation yet keeps pointing at the source until that item is
translated, and an id keeps the type it was stored with.

**`context_tags`** (string, optional) - the type alias the item's tags are stored under in the
content item tag map, used when reading the source's tags for `m2m_relation`. It defaults to the
content type's own key, which is correct for an article (`com_content.article`); a com_content
category's tags are stored under `com_content.category`, so the category entry sets it explicitly.

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
    "context_custom_fields": "com_content.article",
    "draftCopyFields": ["catid", "access", "created_by"],
    "aliasScope": ["catid"],
    "stateField": "state",
    "associatedFields": { "catid": "com_categories.category" },
    "m2m_relation": { "tags": "com_tags.tag" }
}
```

## Example: the menu item entry

A menu item translates only its `title`. It is placed in the derived per-language menu under the
translation of its parent, at the root while that has none, with `home` forced off, and `linkTargets` re-points its `link` at the translation of whatever it
points at, so a translated menu item opens the translated page. `paramTargets` does the same for
what it names in its params, so a translated alias stands for the translated menu item.

```json
"com_menus.item": {
    "translatableFields": ["title"],
    "component": "com_menus",
    "model": "Item",
    "table": "#__menu",
    "context_associations": "com_menus.item",
    "draftCopyFields": ["link", "type", "component_id", "browserNav", "access", "img", "template_style_id", "params"],
    "aliasScope": ["parent_id", "client_id", "language"],
    "aliasReservedAtRoot": true,
    "stateField": "published",
    "linkTargets": {
        "com_content.article": { "id": "com_content.article" },
        "com_content.category": { "id": "com_categories.category", "filter_tag": "com_tags.tag" },
        "com_content.categories": { "id": "com_categories.category" },
        "com_content.archive": { "catid": "com_categories.category" },
        "com_tags.tag": { "id": "com_tags.tag" },
        "com_tags.tags": { "parent_id": "com_tags.tag" }
    },
    "paramTargets": {
        "aliasoptions": "com_menus.item",
        "featured_categories": "com_categories.category",
        "redirect_menuitem": "com_menus.item",
        "cancel_redirect_menuitem": "com_menus.item",
        "login_redirect_menuitem": "com_menus.item",
        "logout_redirect_menuitem": "com_menus.item",
        "customRegLinkMenu": "com_menus.item",
        "logout": "com_menus.item"
    },
    "associatedFields": { "parent_id": "com_menus.item" },
    "associatedFieldsFallback": { "parent_id": 1 },
    "draftForceFields": { "home": 0, "menuordering": 0 },
    "languageMenu": "menutype"
}
```
