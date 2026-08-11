# User guide

This is the user documentation for the Translator Feedback Loop component
(`com_translations`): what it does and how to use it. For the precise meaning of the
terms below, see [glossary.md](glossary.md).

## What it does

The component helps a multilingual Joomla site translate its content while learning the
community's terminology and style. It produces a draft translation of a content item,
a translator reviews and corrects that draft, and the corrections are captured as
feedback. Over time that feedback is distilled into rules that guide later machine
translations, so the translations get closer to how the community actually writes.

## Prerequisites

The component assumes a **multilingual Joomla site**:

- More than one content language installed and published.
- Multilingual enabled, with the System - Language Filter plugin on (this is what makes
  Joomla associate the same item across languages).
- One language is the **source language** (the language your originals are written in),
  and one or more others are the **target languages** to translate into.

Without a multilingual setup there is nothing to associate translations with.

It also requires **version history** on the content you translate. In each component's
Options, set **Enable Versions** to *Yes*:

- **Content**, then **Articles**, then **Options**, on the **Editing Layout** tab. This
  one setting covers articles **and** content categories, because Joomla versions a
  category under the component that owns it.
- **Tags**, then **Options**, for tags.

Both are already on after a standard Joomla installation, so this is usually a check
rather than a change.

The component records which version of a source item each translation was made from,
and compares it against the source's current version to notice when an original has been
edited since it was translated. Such a translation is sent back for re-translation. With
versions turned off Joomla stores no history, an edited original is never noticed, and
its translations stay out of date without telling anyone.

## Setting the source language

The source language is a component setting (Options), defaulting to `en-GB`. Set it to
the language your content is authored in. Everything the queue lists as an original to
translate is a content item in this language; every installed content language except
the source and the special "All" (`*`) language is treated as a target language.

## The views and the workflow

### Queue

The queue is a grid. Each row is a source-language content item; each column is a target
language. A cell shows the state of that item's translation into that language:

- **no translation yet**, an empty cell,
- **pending**, waiting to be translated, which is where a translation goes when its source
  has been edited since it was made,
- **review**, a draft is waiting for a translator,
- **approved**, a translator has finished with it,
- **published**, the translated item is live.

Use the tab above the grid to move between content types. There is one tab per type the
component translates: articles, categories, tags and site menu items.

From a cell with no translation, or one that is pending, you can trigger a translation. It
creates an unpublished draft in that language and sets the cell to "review". Clicking a cell
in review or approved opens the side-by-side editor. Items marked "no need for translation"
are hidden by default; a filter lets you show them and clear the flag.

A translation is made from the source as it was at that moment, and the component remembers
which version that was. Edit the source afterwards and its translations go back to pending,
so you can see at a glance which ones no longer match their original.

### Translator feedback view

Opening a cell that is in review takes you to the side-by-side editor. The source content
item is shown read-only on the left; the editable translation is on the right, field by
field. Each content type shows the fields it can translate (for an article: title, intro
and full text, meta description and keywords, note, and the image alt text and captions),
together with any translatable custom fields the item has.

Correct whatever is wrong, then choose one of:

- **Save** or **Save & Close** keeps your changes on the draft. The translation stays in
  review, so you can come back to it later. Nothing is learned yet.
- **Approve** says the translation is finished. Every field you changed is compared with
  what the machine produced, and each difference is stored as feedback for the component to
  learn from. The translated item is not published.
- **Approve & Publish** does the same and publishes the translated item as well.

Approving is what teaches the component, so approve when you are happy with the whole
translation rather than after each small edit.

### Setting the language of a new item

When you create a new article, category, tag or site menu item, its language is preselected
as the source language, so an original is ready to be translated without you setting the
language by hand. You can still change it before saving.

Two limits are worth knowing. It applies only to a **new** item, never to one you are
editing. And it applies to categories of the extension the component manages and to **site**
menu items only, because those are the ones the component translates.

### Rules

The Rules view lists what the component has learned, one row per rule, for one target
language each. A rule has a type:

- **Terminology** pairs a term in the source language with the translation your community
  prefers, such as "article" to "Beitrag" in German.
- **Preservation** names a term to leave in the source language, such as a product name, or
  a word your community keeps in English on purpose.
- **Style** describes how the text should read, such as addressing the reader informally.
  A style rule has no term; it applies to the whole language.

Rules made from feedback arrive **unpublished**, so nothing the component learns is used
until a person reads it and publishes it. You can edit any rule, change its type, publish
it, unpublish it or trash it. You can also write a rule by hand, which is useful for a
convention your community already knows without waiting for someone to correct a
translation first.

Each rule also carries a confidence, between 0 and 1, which is the distiller's own estimate
of how well established the convention looked. It is there to help you decide what to
publish.

### How the rules reach a translation

Only the rules that matter to an item are sent when it is translated, not the whole rule
base. Before the translation call the component takes the item's text, keeps the rules whose
term or search keyword appears in it, adds the style rules for that language, and passes
those to the translation plugin. This keeps the request small and stops unrelated rules from
confusing the result.

Terms are also matched in their **standard form**, so a rule written for one form of a word
applies to its other forms as well. A rule for "article" therefore also applies where the
text says "articles". The component works these forms out once per word and stores them, so
the same word costs nothing the next time.

### Marking an item as "no need for translation"

On a source item's edit form, in the Translations tab, a toggle marks the item as one that
should not be translated. It is available for articles, categories, tags and site menu
items. Marked items drop out of the queue (and can be brought back from the queue's filter).

## Doing it on a schedule

Both halves of the loop can run unattended, through Joomla's own **Scheduled Tasks**
(System, then Scheduled Tasks). The component ships two task types:

- **Translate Queued Items** takes items with no translation yet, and ones sent back to
  pending, and translates them a few at a time.
- **Distil Translation Rules** reads the feedback collected from approvals and turns it into
  draft rules.

Both are optional. Everything they do can also be done by hand: translate from a queue cell,
and distil with the **Distil Now** button in the Rules view. How many items each run handles
is a setting on the task, so you can keep a run short on a busy site.

The plugins that provide these tasks are disabled when the package is installed, like every
Joomla plugin, so enable the ones you want before creating a task.

## What is still in progress

The component is under active development. Two things are worth knowing:

- A translated item is published only after a person approves it. An option to publish a
  machine translation immediately, before review, is planned.
- The component needs a **translation plugin** and a **RAG plugin** to be installed, enabled
  and configured with an API key. With no translation plugin enabled, asking for a
  translation reports an error rather than producing anything.

This guide will grow as more lands.
