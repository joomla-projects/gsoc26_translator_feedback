# Glossary

This component uses a number of everyday words in a specific, narrow sense. Several
of them mean slightly different things in other contexts, so this file records the
meaning they have **here**, in the Translator Feedback Loop component and its
development.

As Herman noted when proposing this glossary, in Domain-Driven Design this shared
vocabulary is called the *ubiquitous language* of a bounded context: making the
terms explicit means developers, translators, mentors and other stakeholders all
attach the same meaning to them when talking about the code. It is a living
document; terms are added as they come up.

## Terms

**Association** - the link Joomla keeps between the same content item in different
languages, stored in `#__associations` under a per-content-type context
(`com_content.item`, `com_categories.item`, and so on). Approving a translation
leaves the source and its translated draft in one association group.

**Associated content item** - the same content item in another language. A source
article and its French translation are each other's associated content items.

**Content item** - a single piece of content of a content type: one article, one
category, one tag, one menu item. Some of its fields can be translated. For an
article, that is the title and the intro and full text, among others.

**Content type** - a kind of content the package can translate: an article,
category, tag or menu item. Other content types are possible but not yet
implemented. The properties specific to a content type, such as its table,
translatable fields, contexts and relations, are collected in `contenttypes.json`.

**Content type map** - the `contenttypes.json` file, which describes each content
type to the translation pipeline so the producer can stay independent of any single
content type.

**Distiller** - the scheduled task that batches collected feedback into reusable
rules (planned).

**Draft** - an automatically translated content item that has not yet been reviewed
by a human. It is a real but unpublished content item, an actual article or category
for example, created by the producer and linked to its source.

**Feedback** - a human correction captured when a translator edits a draft. Stored
as a preference pair in `#__translations_feedback`: the `source_text`, the
`machine_draft`, and the translator's `human_correction`. Feedback is what the
system learns from.

**No need for translation** - a flag on a source content item (`do_not_translate`)
marking it as one that should not be translated at all. Such items are hidden from
the queue by default.

**Producer** - the part of the component that produces a translation: the
`TranslationModel`. Given a source content item and a target language it prepares
the text, creates the unpublished draft, links it to the source, and sets the queue
state to "review". It is provider-agnostic; the actual translation call lives in a
separate translation plugin. "Producer-only" (as in "the categories commit is
producer-only") means a change adds only this production side for a content type,
with no queue interface to trigger it yet.

**Queue** - has two related meanings:

- the queue view: the admin grid that lists source content items and, for each
  target language, the state of its translation;
- the queue table (`#__translations_queue`): one row per source content item that
  has entered the pipeline. It holds no text of its own; the text lives in the
  content items themselves.

**Related content item** - a content item tied to another by a foreign key rather
than by language, such as the category an article belongs to (`catid`) or the tags
attached to an article. When a content item is translated, its foreign keys may need
to be re-pointed at the translated related items.

**Review** - a draft that is under review by a human translator. The translator
gives feedback by editing the translated text; after that the translation can be
approved. ("Review" is also a queue state, listed below.)

**Rule** - a distilled translation instruction stored in `#__translations_rules`,
with a `rule_type` of `terminology`, `style` or `preservation`. Rules are injected
into the translation prompt so a machine translation follows the community's
conventions.

**Source content item / source language** - the original content item, written in
the source language: the content language originals are authored in (configurable,
default `en-GB`). The queue lists source items as the originals to be translated.

**Target language** - a content language to translate into: every installed content
language except the source language and the "All" (`*`) language.

**Translatable field** - a field of a content type whose value can be translated
(for an article: the title, intro text, full text, meta description, and others).
Listed per content type in `contenttypes.json`.

**Translation plugin / translation provider** - a plugin in the `translation` group
that performs the provider-specific translation call (for example, one for Claude)
in response to the `onTranslate` event. The component itself stays provider-agnostic,
so different providers can be shipped as different plugins. Until a real one exists,
the producer uses a mock translation that prefixes each string with the target
language (`[MOCK:<lang>] ...`).

## Translation states

Each (source content item, target language) pair has at most one state, stored in
`#__translations_queue_states.translation_state`. The absence of a row is itself
meaningful.

- **No translation yet** - there is no state row for this item and language; it is
  ready to be translated. This is not a stored value, it is the absence of one.
- **Pending** - the item has been queued for translation but its batch turn has not
  come yet. Reserved for the scheduled task plugin (not yet built); the current manual
  trigger does not use it and writes "review" directly. The label shown for this
  state is planned to become "Pending for translation", to be clearer.
- **Translating** - the translation service is busy producing the translation for
  this item right now. Reserved for the scheduled task plugin.
- **Review** - the automatically translated draft is under review by a human
  translator, who edits it and gives feedback. From here it can be approved.
- **Approved** - a human translator has approved the translation; it is ready to be
  published.
- **Published** - the translated content item is published. By default this is
  reached only after a human approves it; an optional setting (planned) can publish
  a machine translation immediately, before review.
