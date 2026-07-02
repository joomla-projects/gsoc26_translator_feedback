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

Later, the matched rules and extra context (for retrieval-augmented prompting) will be
passed in as well, for the provider to fold into its prompt.

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
- **`stopPropagation()`** - one provider answers per item, so stop the rest of the group
  running (the same pattern as the core authentication plugins). The component uses the
  first provider that returns a result.

On any failure (missing key, network error, refusal, unreadable reply) throw an exception
instead of returning a placeholder, so the real reason reaches the user and no draft is
created.

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
