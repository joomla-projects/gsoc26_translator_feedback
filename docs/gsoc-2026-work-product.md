# GSoC 2026 final work product

**Project:** Translator Feedback Loop for Joomla (inspired by RLHF)
**Contributor:** Krishna Gandhi
**Mentors:** Herman Peeren, Charvi Mehra, Stefan Wendhausen
**Organisation:** Joomla!
**Repository:** [joomla-projects/gsoc26_translator_feedback](https://github.com/joomla-projects/gsoc26_translator_feedback)

This is my final work product for Google Summer of Code 2026. It covers what the project set
out to do, what I built, where it stands now, what is still open, and what I learned along
the way.

## What the project is for

Machine translation is fast, but it doesn't know the words a community has spent years
agreeing on. Joomla has its own vocabulary. Words like "Article", "Module" and "Template"
mean something specific here, and over twenty years each language's translators have agreed
on how to render them. A general-purpose tool has no way of knowing any of that.

The example I keep coming back to is a simple one. Translate "Article" into German the
everyday way and you get *Artikel*. But the German Joomla community settled on *Beitrag*. It
goes the other way too: the Dutch and French communities deliberately leave "template" in
English, so a tool that helpfully translates it is just as wrong. Multiply that across
thousands of pages in more than sixty languages and the scale starts to show.

Here's the part that really matters. The machine never learns from the fixes. A volunteer
corrects the same mistake this week that they corrected last week, and will correct again
next week. All that knowledge, "we always translate this word this way", "never translate
that one", lives in people's heads and gets thrown away every single time.

So the goal was to stop throwing it away. A translator corrects a machine-generated draft,
the way they would anyway. The component records what changed, works out the convention
behind it, and feeds that convention into later translations. The translations themselves
don't learn. The system around them does.

## What I built

`pkg_translations`, an installable Joomla package with six extensions in it:

- **`com_translations`**, the admin component. It owns the queue, the side-by-side editor,
  the rules views, and all of the translation logic that isn't tied to a particular
  provider. It is served on the front end as well, so a translator never needs a backend
  account.
- **`plg_content_translations`**, a content plugin. It adds the "no need for translation"
  toggle to a source item's form, and notices when a source is edited so its translations
  can be marked out of date.
- **`plg_translation_claude`**, a translation provider. It answers the `onTranslate` event by
  calling the Anthropic Messages API.
- **`plg_rag_claude`**, a RAG provider. It answers `onDistil`, which turns corrections into
  rules, and `onNormalise`, which reduces a word to its standard form.
- **`plg_task_translationstranslate`**, a scheduled task that translates a batch of waiting
  items.
- **`plg_task_translationsdistiller`**, a scheduled task that distils collected feedback into
  candidate rules.

Underneath there are five tables (`#__translations_queue`, `#__translations_queue_states`,
`#__translations_feedback`, `#__translations_rules` and `#__translations_standard_forms`),
four admin views, and three event contracts. At the time of writing `main` carries 44 tracked
PHP files and roughly 10,110 lines of PHP under `src`, clean at PHPStan level 5 with the
deprecation rules switched on.

## How the loop works

The queue is a grid. Each row is a content item in the source language, each column is a
target language, and each cell is the state of that item's translation into that language.
An empty cell means there is no translation yet.

When an item is translated, the component collects its translatable fields as one set,
retrieves the rules that are relevant to that text, and hands both to whichever translation
plugin is enabled. What comes back is written as a real, unpublished Joomla item in the
target language and linked to its source through Joomla's own associations. The cell moves to
*review*. Nothing here is a special object of ours: the draft is an ordinary article,
category, tag or menu item, so everything Joomla already does keeps working on it.

Clicking a cell in review opens the side-by-side editor. The source sits on the left, read
only, and the editable translation on the right, field by field, including translatable
custom fields. The translator fixes what is wrong, in the ordinary way.

On approval the correction is paired against a snapshot of the machine draft as it was
produced, and that pair is stored as feedback. Pairing against the snapshot rather than the
live item is what makes the signal worth anything, because the difference is exactly what the
human changed and nothing else.

A scheduled task then sends batches of those pairs to the RAG plugin and asks what convention
explains them. What comes back are candidate rules of three kinds: **terminology** (translate
this term this way), **style** (write like this) and **preservation** (leave this alone).
They are saved unpublished, and a person publishes them, so the system never starts using a
convention nobody agreed to.

On the next translation the retriever loads the published rules for that target language,
keeps the ones whose term or search keyword actually appears in the source text, and injects
those into the prompt. Style rules apply to the whole language. The result is capped so a
prompt stays bounded. Matching survives inflection, because rule terms and source words are
both reduced to a standard form through `onNormalise` and cached per language, so a rule
written for "move" still matches "moved" and "moves".

The loop closes when someone edits a source item. The component records which version of the
source each translation was made from, so an edited original sends its translations back to
*pending*. The item comes round again, and this time the rules are already there.

## Where it stands now

The loop works end to end, and I have run it that way repeatedly against a live provider on
real multilingual sites, across all four content types. Corrections made in the editor become
published rules, and those rules visibly change the next translation: text that came back
with *Artikel* comes back with *Beitrag* once the rule exists.

I demonstrated it live at the Joomla User Group London meetup on 18 August 2026, running the
whole cycle on stage. Translate, correct, distil, publish the rules, re-translate, and watch
the corrections come back on their own.

Four content types are supported: articles, categories, tags and site menu items. They are
described in a JSON content-type map instead of in conditional code, so the differences
between them (which fields are translatable, which component, model and table own them, how
Joomla versions and associates them) are declared in one file. Adding a fifth is mostly a
matter of describing it.

The boundary to a translation service is an event contract rather than a PHP interface. That
was a deliberate choice, and it means anyone can ship a plugin for a different provider
without inheriting from anything of ours. Nothing in the shipped package falls back to a
mock either. With no provider enabled, translation fails loudly instead of quietly producing
something fake.

The documentation is in [`docs/`](.): a [user guide](user-guide.md), a
[glossary](glossary.md) of the vocabulary this project uses in a narrow sense, a
[content-type map reference](contenttypes.md), and a
[guide to writing your own translation plugin](translation-plugin.md).

## What went upstream

**76 of my pull requests were merged into
[`joomla-projects/gsoc26_translator_feedback`](https://github.com/joomla-projects/gsoc26_translator_feedback)
during the programme.** The full list is here:

<https://github.com/joomla-projects/gsoc26_translator_feedback/pulls?q=is%3Apr+is%3Amerged+author%3Akrishnagandhicode>

I kept them small on purpose and cut them at review seams, because Herman Peeren, my mentor,
was reading them. Roughly grouped by what they did:

- **The component and the queue.** The installable component and its tables ([#13]), the queue
  grid and its filters ([#16]), a configurable source language ([#23], [#106]), content types as
  tabs ([#57]), scoping the queue to items the component can actually translate ([#129]), and
  two later passes over the grid itself ([#145], [#148]).
- **The editor and feedback capture.** The side-by-side editor reachable from the queue
  ([#29]), saving into the draft ([#33]), recording feedback pairs on save ([#36]), editing all
  translatable fields rather than just title and text ([#59]), translatable custom fields
  ([#77]), and checking a draft out while it is being edited ([#94]).
- **The producer.** The component-side model that creates the draft, the association and the
  review state ([#39]), handing a provider one collection of strings instead of one string at a
  time ([#44]), and translating in dependency order so a category exists before the article
  that sits in it ([#137]).
- **Providers and the event contracts.** The Claude translation plugin ([#80]), the
  distillation plugin ([#92]), its rename into the `rag` group ([#119]), and word normalisation
  through `onNormalise` ([#120]).
- **The learning half.** The rules view and rule editing ([#86]), the distiller that turns
  feedback into draft rules ([#89]), the retriever that puts them back into a prompt ([#103]),
  sending a provider only the rules relevant to the corrections in hand ([#142]), and showing
  the standard form a rule was matched on ([#139]).
- **Scheduled tasks and packaging.** The distiller task ([#93]), the translate task ([#133]), and
  packaging all six extensions into a single installable ([#134]).
- **Lifecycle and re-translation.** Re-translating when a source is edited ([#99], [#122], [#125]),
  updating an existing translation instead of duplicating it ([#75]), cascading trash and
  delete through to translations ([#65]), remapping an article's category and tags to the
  translated ones ([#76]), publishing new translations when the option is set ([#146]), and
  re-translating published translations too ([#149]).
- **The other content types.** Articles first, then categories, tags and menu items across
  the queue, the editor and the opt-out toggle ([#53], [#68], [#100], [#131]).
- **Front-end access.** Serving the translator's views on the site, so a translator with
  ordinary edit permission never needs a backend login ([#117]).
- **Documentation.** The glossary ([#58]), the user guide ([#61]), the content-type map ([#64]),
  the translation-plugin guide ([#87]), and a pass bringing all of it back in line with what
  had actually shipped ([#143]).

### A fix for Joomla core

Adding category support turned up a real defect in Joomla itself. Editing a source category
silently drops every unpublished translation out of its association group, and if fewer than
two members are left the group is deleted outright. It has been there for about eight years,
and I think it survived because the usual result is partial loss rather than obvious total
loss. `com_categories` is the only component that filters association members by their
published state, which is why the same flow is fine for articles.

I reproduced it on a stock Joomla install, traced it to `CategoryModel::getItem()` reading
from a helper that filters on `published = 1`, checked that no display behaviour actually
depends on that filter, and raised a patch:

**[joomla/joomla-cms#48208](https://github.com/joomla/joomla-cms/pull/48208)**, targeting
`5.4-dev`.

The guidelines ask for what got merged and what did not, so for completeness: this one is
still open, waiting on core review.

## What is still open

The project does what it set out to do. The loop runs end to end, and a translator's
corrections come back as rules that steer the next translation.

Nineteen issues are open on the repository, eleven of them labelled `enhancement`. They were
kept back for after the submission on purpose, so the board stayed readable while the work was
running and so anyone in the community is free to pick one up. Four of them, [#150] to [#153],
were opened by Herman after the London talk, which says something about where this can go next.

The main ones fall into two groups.

The first is code and architecture: a better structure for rule retrieval ([#108]), fully typed
objects in place of the JSON content-type map ([#138]), and logging the processing data so the
retrieval limits can be tuned against measured numbers ([#144]).

The second is functionality that makes the extension more useful day to day. The biggest is
seeding a starting rule set out of the translations Joomla already has, in its language packs
and in the old documentation wiki ([#5], [#11], [#152]), which is what lets a community begin with
its own conventions instead of a blank slate, with [#153] to export a rule set and share it
between sites. Alongside those: pointing links inside translated text at the translated pages
([#150]), publishing a distilled rule automatically ([#151]), defaulting the queue filter ([#97]),
locking the source language in the edit view ([#43]), adding content types through configuration
([#60]), and the menu follow-ups ([#62], [#63], [#67]).

## What I learned

My first days went on Joomla's MVC structure and dependency injection, because everything I was
going to build sat on those two things. A component registers itself through a service provider,
and the container hands it the database and the dispatcher instead of it going and fetching
them. Until that clicked, I could not write anything that really fitted into Joomla rather than
just running inside it.

After that, the harder skill was finding my way around a big codebase. Joomla is twenty years
old and much bigger than anything I had worked in before, and at first I had no idea how to find
the twenty lines that mattered to what I was building. Learning to find them, and then to read
them instead of guessing what they did, is what the rest of the summer was built on. It is also
the only way to know your extension really works with core rather than just looks like it does.
Nearly every hard problem I hit was solved that way, and the answer was usually not the one I
expected. One of them turned out to be a bug in Joomla itself.

The biggest design lesson was to let the plugin boundary carry the contract, instead of a PHP
interface. My first idea was a `TranslationProviderInterface`. Herman pushed back: Joomla runs
plugins as events, and an interface would tie every provider to our own classes. The contract
became the event instead, so a provider for a different service is its own extension that does
not depend on ours at all.

One I did not expect: a machine draft is not the same every time. While preparing the demo, I
wrote a correction against one draft and pasted it over a later one. The wording had changed
slightly, so my paste carried an edit I had not meant to make, and the distiller turned that
into a rule. Not a bug, but a lesson I will remember about testing anything with a model in it.
What you think you gave it is not always what it got.

And small pull requests are worth it. Seventy-six is a lot, and it was still the right call,
because each one was small enough to actually be read. The standard I was held to was that the
code should show how something is done better than core does it, and that "core does it this
way" is not a good enough reason on its own, because core has old habits sitting next to good
ones. Learning to tell the difference taught me the most.

## Installing it, and building on it

The package installs like any Joomla extension. Build it with `build/make_package_zip.ps1`,
or take a built package, and install the zip through **System**, then **Install**, then
**Extensions**. All six extensions go in under one package ID. The site has to be
multilingual, with more than one content language published and the language filter plugin
enabled, and version history has to be on for the content types being translated. The
[user guide](user-guide.md) covers the setup and the day-to-day workflow.

To point it at a different translation service, write a plugin in the `translation` group that
subscribes to `onTranslate`, reads the source strings and the retrieved rules off the event,
and adds its result. The [translation plugin guide](translation-plugin.md) walks through it,
and `plg_translation_claude` is a working example of the whole contract in one small class. A
RAG provider works the same way through `onDistil` and `onNormalise`.

To teach it a new content type, describe it in
[`contenttypes.json`](../src/administrator/components/com_translations/contenttypes.json)
rather than adding conditionals to the model: which fields are translatable, which component,
model and table own it, how Joomla versions it, and how it is associated.
[`contenttypes.md`](contenttypes.md) documents every key in the map.

## Elsewhere

- **Article:** [Reuse the experience of Joomla
  translators](https://magazine.joomla.org/issues/2026/june-2026/reusing-20-years-of-joomla-translations-to-improve-automatic-translations),
  Joomla Community Magazine, June 2026.
- **Talk:** *Keeping the translator in the loop*, Joomla User Group London, 18 August 2026.
  [Slides](https://krishna-jug-london.netlify.app/).

## Thanks

To Herman Peeren, who reviewed this work throughout, asked the questions and gave the
suggestions that shaped its architecture, and held it to a standard I would not have set for
myself. Early on he spent more than two weeks of live sessions walking me through building a
Joomla component from the ground up, and a lot of how I think about code now came from that.
To Stefan Wendhausen for the guidance along the way, and to the Joomla community for a
genuinely welcoming first year.

<!-- Pull request and issue links -->

[#13]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/13
[#16]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/16
[#23]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/23
[#29]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/29
[#33]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/33
[#36]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/36
[#39]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/39
[#44]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/44
[#53]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/53
[#57]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/57
[#58]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/58
[#59]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/59
[#61]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/61
[#64]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/64
[#65]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/65
[#68]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/68
[#75]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/75
[#76]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/76
[#77]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/77
[#80]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/80
[#86]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/86
[#87]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/87
[#89]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/89
[#92]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/92
[#93]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/93
[#94]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/94
[#99]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/99
[#100]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/100
[#103]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/103
[#106]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/106
[#117]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/117
[#119]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/119
[#120]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/120
[#122]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/122
[#125]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/125
[#129]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/129
[#131]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/131
[#133]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/133
[#134]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/134
[#137]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/137
[#139]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/139
[#142]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/142
[#143]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/143
[#145]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/145
[#146]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/146
[#148]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/148
[#149]: https://github.com/joomla-projects/gsoc26_translator_feedback/pull/149
[#5]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/5
[#11]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/11
[#43]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/43
[#60]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/60
[#62]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/62
[#63]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/63
[#67]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/67
[#97]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/97
[#108]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/108
[#138]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/138
[#144]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/144
[#150]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/150
[#151]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/151
[#152]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/152
[#153]: https://github.com/joomla-projects/gsoc26_translator_feedback/issues/153
