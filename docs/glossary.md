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

**Distiller** - the part of the component that turns collected feedback into draft
rules: the `DistillerModel`. It reads a batch of feedback, works out what changed in
each correction, asks a RAG plugin to distil rules from it, and stores the results as
unpublished rules for review. It can be run by hand from the Rules view or on a
schedule by the distiller task plugin.

**Draft** - an automatically translated content item that has not yet been reviewed
by a human. It is a real content item, an actual article or category for example,
created by the producer and linked to its source. It is unpublished unless the
component is set to publish new translations.

**Feedback** - a human correction captured when a translator edits a draft. Stored
as a preference pair in `#__translations_feedback`: the `source_text`, the
`machine_draft`, and the translator's `human_correction`. Feedback is what the
system learns from.

**Guard** - a defensive check in the code that protects an invariant by stopping
or redirecting an operation that would otherwise be invalid. The producer, for
example, guards against translating an item marked "no need for translation",
against an item outside a content type's extension, and against creating a
duplicate when the target language already has a translation (updating the
existing one instead).

**No need for translation** - a flag on a source content item (`do_not_translate`)
marking it as one that should not be translated at all. Such items are hidden from
the queue by default.

**Producer** - the part of the component that produces a translation: the
`TranslationModel`. Given a source content item and a target language it prepares
the text, creates the draft, links it to the source, and sets the queue state to
"review". It is provider-agnostic; the actual translation call lives in a
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

**RAG plugin** - a plugin in the `rag` group, which answers two events: `onDistil`, to
distil rules from corrections, and `onNormalise`, to reduce words to their standard
form. The package ships `plg_rag_claude`. It is a separate group from the translation
plugins, and carries its own API key and model setting, so a site can learn with one
model and translate with another. RAG stands for retrieval-augmented generation, the
practice of retrieving relevant knowledge and adding it to a prompt. The retrieval
itself lives in the component, in `RuleRetriever`.

**Standard form** - the form of a word that rules are matched on: a noun reduced to
its singular, a verb to its infinitive. A rule's term and the words of the source text
are reduced the same way, so a rule written for "article" also applies where the text
says "articles". Standard forms are stored in `#__translations_standard_forms`, one row
per word per language, so each word is worked out only once.

**Translation plugin / translation provider** - a plugin in the `translation` group
that performs the provider-specific translation call in response to the `onTranslate`
event. The package ships `plg_translation_claude`, which calls the Anthropic Claude
API. The component itself stays provider-agnostic, so different providers can be
shipped as different plugins, and several can be installed at once. With no translation
plugin enabled the component reports an error rather than producing a draft; there is
no built-in fallback translation. See [translation-plugin.md](translation-plugin.md) for
how to write one.

## Translation states

Each (source content item, target language) pair has at most one state, stored in
`#__translations_queue_states.translation_state`. The absence of a row is itself
meaningful.

- **No translation yet** - there is no state row for this item and language; it is
  ready to be translated. This is not a stored value, it is the absence of one.
- **Pending** - the item is waiting to be translated. It is set when a source item is
  edited after it was translated, which sends its translations back to be made again.
  A translation is produced from this state just as it is from no state at all.
- **Translating** - reserved, and not currently written. The translate task plugin
  deliberately leaves the state alone while it works: if a provider were unreachable,
  an item marked this way would be stuck there with nothing in the interface to reset
  it.
- **Review** - the automatically translated draft is under review by a human
  translator, who edits it and gives feedback. From here it can be approved.
- **Approved** - a human translator has approved the translation. Approving does not
  change whether the item is on the site.
- **Published** - the translated content item has been approved and published from the
  component. It is written by Approve & Publish, and by the install backfill for a
  translation that already existed and was live. Like the states above it describes the
  review rather than the item: an item can be live without being in this state, which is
  what the eye beside a queue cell shows.
