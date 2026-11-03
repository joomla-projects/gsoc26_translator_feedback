# Writing a translation plugin

The component is **provider-agnostic**: it prepares everything around a translation
(reading the source, gathering the translatable strings, creating the draft, linking it
to the source, setting the queue state) but does not itself call any translation service.
The actual translation call lives in a separate plugin, so a site can choose its provider
and anyone can ship a new one.

The component ships with one provider, `plg_translation_claude`, which calls the Anthropic
Claude API. This document is the contract for writing another. For the terms used here see
[glossary.md](glossary.md); for how each content type's translatable fields are defined see
[contenttypes.md](contenttypes.md).

## The plugin

A translation plugin is an ordinary Joomla plugin in the **`translation`** group. It
implements `SubscriberInterface` and subscribes to the **`onTranslate`** event:

```php
public static function getSubscribedEvents(): array
{
    return ['onTranslate' => 'onTranslate'];
}
```

It follows the same conventions as the component's other plugins: a `services/provider.php`
that constructs the plugin and injects whatever it needs (the Claude plugin injects an HTTP
client through its constructor), and `protected $autoloadLanguage = true;`.

Several translation plugins can be installed at once (one per provider); each is a
self-contained way to perform the call.

## What the plugin receives

The handler is passed a `TranslateEvent` carrying the whole item's translatable strings
together, so the provider can keep the context between them:

- `getSourceStrings()` - an associative array of the source strings, keyed by field. A key
  is either a column name (`title`, `introtext`, `metadesc`) or, for a translatable
  sub-field of a JSON column, a dotted path (`images.image_intro_alt`). Only non-empty
  fields are included. These keys come from the content type's `translatableFields` (see
  [contenttypes.md](contenttypes.md)).
- `getSourceLanguage()` - the source language code, for example `en-GB`.
- `getTargetLanguage()` - the target language code, for example `fr-FR`.
- `getRules()` - the distilled rules that apply to this item, for the provider to fold into
  its prompt. The component has already chosen them: published rules for the target language
  only, terminology and preservation rules kept only where their term or a search keyword
  appears in the item's text, style rules kept because they apply to the whole language, and
  the result capped so the prompt stays bounded. A plugin does not filter them again.

Two more getters matter only for an item too long to translate in one request, which the
component sends in several (see [Long items](#long-items) below). They are empty for every
other item, so a plugin that ignores them still works:

- `getDocumentContext()` - what the item as a whole is about: its title and the headings of
  its sections. Empty when the strings are the whole of what is being translated.
- `getTerminology()` - the terms the item's earlier parts were already translated with, as
  `['source term' => 'target term']`, for the provider to keep using. Empty for the first
  part, and for an item sent whole.

The rules arrive grouped by rule type, under the keys `terminology`, `style` and
`preservation`. Each rule in a group is an array with `source_term`, `target_term` and
`rule_text`, and any of them may be empty:

```php
[
    'terminology'  => [
        ['source_term' => 'article', 'target_term' => 'Beitrag', 'rule_text' => ''],
    ],
    'style'        => [
        ['source_term' => '', 'target_term' => '', 'rule_text' => 'Address the reader informally.'],
    ],
    'preservation' => [
        ['source_term' => 'Template', 'target_term' => '', 'rule_text' => ''],
    ],
]
```

How a plugin words these in its prompt is up to it. The Claude plugin turns them into three
labelled sections. A group can be empty, and a site with no rules yet gets three empty
groups, so handle that rather than assuming rules are present.

## What the plugin returns

Return the same collection, translated, through the event:

```php
public function onTranslate(TranslateEvent $event): void
{
    $translated = $this->translate(
        $event->getSourceStrings(),
        $event->getSourceLanguage(),
        $event->getTargetLanguage()
    );

    $event->addResult($translated);
    $event->stopPropagation();
}
```

- **`addResult($translated)`** - an associative array with **the same keys** as received,
  each value replaced by its translation. Keep the keys exactly, so the component can map
  each value back to its field; a value that should not change may be returned unchanged.

  A value is kept only where it can be a translation of the string it comes back under: one
  that differs from its source by more than four times either way, once either of them is past
  200 characters, is dropped. That is not a style rule but a guard against answers arriving
  under each other's keys, which costs an item its title and its body at once. A dropped value
  counts as not translated: the field keeps its source text, and a missing fragment of a long
  item stops that item so it is tried again.
- **`stopPropagation()`** - one provider answers per item, so stop the rest of the group
  running (the same pattern as the core authentication plugins). The component uses the
  first provider that returns a result.

- **`reportGlossary($terms)`** - optional, and only worth setting when
  `getDocumentContext()` was not empty: the terms the provider chose for this part, as
  `['source term' => 'target term']`, which the component carries into the request for the
  next part of the same item. A provider that cannot say which terms it chose leaves this
  alone, and the next part then carries only what earlier parts reported.

On any failure (missing key, network error, refusal, unreadable reply) throw an exception
instead of returning a placeholder, so the real reason reaches the user and no draft is
created.

## Long items

A provider has to send the whole translation back, so a long article asks for more output
than a model will write in one reply and more time than a web request is given. The
component therefore divides an item that does not fit into several requests, each about
`chunk_chars` characters of source (an option, 4000 by default).

What this means for a plugin:

- **Keys can name a part of a field.** A field that had to be cut arrives as
  `fulltext#1`, `fulltext#2` and so on. A plugin does not need to know that: return the
  keys it received, as always, and the component joins the parts back together.
- **A value can be an HTML fragment.** Cuts are only made between a `>` and a `<` (with
  at most white space between them), at the end of a sentence or at a line break, so no tag
  and no word is ever split, but a part can still begin or end inside a list, a table, a code
  sample or a section. Translate it as the fragment it is: do not open, close or add tags, and do
  not finish a sentence that runs on into the next part. The parts are concatenated exactly
  as they come back, so anything added shows up in the middle of the article.
- **Each part is its own request.** Keeping a word translated the same way through the
  article is what `getTerminology()` and `reportGlossary()` are for, and
  `getDocumentContext()` says what the article is about for a part that does not contain
  its title.
- **Code arrives as a placeholder.** The text inside `<code>`, `<kbd>`, `<samp>` and `<pre>`
  is never translated, so the plugin does not get it: such an element arrives holding a
  placeholder like `{{keep:3}}`. Keep every placeholder once, inside its element; the element may
  move within the sentence. An answer that loses or doubles a placeholder counts as not
  translated, and the component puts the held-back text back in all the others.

Every answered request is stored before the next is sent, so a run that ends before the
item does loses nothing: the translate task reports that it should resume, and the next run
carries on with the parts that are left. An item is only saved as a draft once all of its
parts are translated.

## Example

Received:

```php
[
    'title'                  => 'The old lighthouse',
    'introtext'              => '<p>It stood at the edge of the cliff.</p>',
    'images.image_intro_alt' => 'A lighthouse',
]
// sourceLanguage: 'en-GB', targetLanguage: 'fr-FR'
```

Returned through `addResult()`:

```php
[
    'title'                  => 'Le vieux phare',
    'introtext'              => '<p>Il se dressait au bord de la falaise.</p>',
    'images.image_intro_alt' => 'Un phare',
]
```

The component takes it from there: it packs the translated values back into the draft
(rebuilding JSON columns from their dotted keys), saves the draft through the content
type's managing component, links it to the source, and sets the queue state to "review".
