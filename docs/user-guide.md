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

## Setting the source language

The source language is a component setting (Options), defaulting to `en-GB`. Set it to
the language your content is authored in. Everything the queue lists as an original to
translate is a content item in this language; every installed content language except
the source and the special "All" (`*`) language is treated as a target language.

## The views and the workflow

### Queue

The queue is a grid. Each row is a source-language content item; each column is a target
language. A cell shows the state of that item's translation into that language:

- empty / no translation yet,
- review (a draft is waiting for a translator),
- approved, published, and the states reserved for automated runs.

From a cell with no translation you can trigger a translation, which creates an
unpublished draft in that language and sets the cell to "review". Items marked "no need
for translation" are hidden by default; a filter lets you show them and clear the flag.

### Translator feedback view

Opening a cell that is in review takes you to the side-by-side editor. The source content
item is shown read-only on the left; the editable translation is on the right, field by
field. Each content type shows the fields it can translate (for an article: title, intro
and full text, meta description and keywords, note, and the image alt text and captions),
together with any translatable custom fields the item has. You correct the translation and
use **Save & Train**: the draft is saved and every field you changed is recorded as feedback.

### Marking an item as "no need for translation"

On a source article's edit form, in the Translations tab, a toggle marks the article as
one that should not be translated. Marked items drop out of the queue (and can be brought
back from the queue's filter).

## What is still in progress

The component is under active development. The automatic translation currently uses a
built-in placeholder rather than a real translation service, and the approve/publish flow,
the rules distiller and the scheduled automation are planned. This guide will grow as
those land.
