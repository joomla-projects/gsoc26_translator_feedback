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

The package needs Joomla 6, PHP 8.3 or newer, and a MySQL or MariaDB database (PostgreSQL is
not supported yet). The provider plugins that ship with it call Claude, so you also need an
API key from Anthropic; another provider can be added as a plugin, see
[translation-plugin.md](translation-plugin.md).

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

## Installing and setting up

1. Download `pkg_translations-<version>.zip` from the
   [Releases page](https://github.com/joomla-projects/gsoc26_translator_feedback/releases) and
   install it in **System, Install, Extensions**. It installs the component and five plugins.
2. Joomla installs plugins disabled, so enable them in **System, Manage, Plugins**:
   - **Content - Translations** keeps the queue in step with your content. It notices when an
     original is edited, adds the "no need for translation" toggle, preselects the source
     language on a new item, and trashes or removes translations when their original is trashed
     or deleted. Enable it before you start.
   - **Translation - Claude** makes the translation call. Enter your **API key** and choose a
     **Model**.
   - **RAG - Claude** distils rules from feedback and works out the standard form of words. It
     has its own **API key** and **Model**, which can differ from the translation plugin's.

   Both Claude plugins also have:

   - **Effort**: how much the model may think before it answers; **Low** by default. Thinking
     is paid for like the answer itself. Measured on language-pack corrections, distilling at
     **High** cost about six times as much per correction as at **Low** and added mostly rules
     that rest on a single correction; translation came out the same at either level. Haiku
     takes no effort level, so the setting is ignored for it.
   - **Timeout (seconds)**: how long to wait for an answer before the request counts as failed;
     300 by default. A run started over the web can be cut off earlier by the web server.
   - **Task - Translations Translate** and **Task - Translations Distiller** are needed only if
     you want to run the work on a schedule (see "Doing it on a schedule").
3. Set the source language in the component's Options, as described next.

## Setting the source language

The source language is a component setting (Options), defaulting to `en-GB`. Set it to
the language your content is authored in. Everything the queue lists as an original to
translate is a content item in this language; every installed content language except
the source and the special "All" (`*`) language is treated as a target language.

## Publishing translations automatically

By default a machine translation is created unpublished, so nothing reaches your visitors
before a translator has read it. The **Auto-Publish New Translations** option (Options) changes
that: with it on, a translation goes live the moment it is created.

The option applies only the **first** time an item is translated into a language. Once a
translation exists, translating it again never changes whether it is on the site: a live
translation stays live and an unpublished one stays unpublished, whatever the option says. This
is why re-translating an item after its source is edited no longer takes a published page down.

Turning it on is a real trade. Visitors see the machine's own wording until a translator gets
to it, and after a source edit a live page is replaced by a fresh machine translation. What
makes it reasonable is that the corrections made earlier are captured as feedback, and once they
have been distilled and the rules that came out of them published, a later translation speaks the
terminology the community taught it. Joomla's version history also keeps the approved text, so
nothing is lost.

## The views and the workflow

### Queue

The queue is a grid. Each row is a source-language content item; each column is a target
language, headed by its language code with the full name as a tooltip. A cell shows the state
of that item's translation into that language as an icon, and hovering it names the state:

- **no translation yet**, a grey circle,
- **pending**, waiting to be translated, which is where a translation goes when its source
  has been edited since it was made, and where an item waits for its next attempt when one
  failed,
- **review**, a draft is waiting for a translator,
- **approved**, a translator has finished with it,
- **published**, the translation was approved and published from here,
- **failed**, a warning sign: the translate task tried three times and set the item aside (see
  "Doing it on a schedule").

Each state has its own icon, so none of them has to be told apart by colour alone. The colours
group them instead: grey for nothing yet, blue for pending and approved, green for published,
amber for review, the state that is waiting on a translator, and red for failed, which is
waiting on whoever looks after the site. Where a cell can be clicked its tooltip also says
what clicking does.

With many languages the grid scrolls sideways on its own, keeping the source column beside its
row, so the page itself never grows wider than the screen.

Use the tab above the grid to move between content types. There is one tab per type the
component translates: articles, categories, tags and site menu items.

From a cell with no translation, or one that is pending or failed, you can trigger a translation. It
creates a draft in that language and sets the cell to "review". The cell spins while the
translation runs, and a second click on it is ignored. Clicking a cell in review or
approved opens the side-by-side editor. Items marked "no need for translation" are hidden by
default; a filter lets you show them and clear the flag.

A translation is made from the source as it was at that moment, and the component remembers
which version that was. Edit the source afterwards and its translations go back to pending,
so you can see at a glance which ones no longer match their original. A translation that is
already on the site stays there while it waits, and re-translating it replaces its text
without taking the page down.

### Whether a translation is on the site

The state in a cell says where a translation stands in its **review**. Whether the translated
item is **on the site** is a different question, and Joomla answers it: someone can publish or
unpublish a translated article in Content, Articles, without the queue being involved at all.

Where those two disagree, the cell shows an eye beside its state:

- a **green eye** means the item is live although it was not published from here,
- a **crossed eye** means it was published from here and is no longer live.

Where they agree there is no eye, so the marker only ever draws attention to something you
might not expect.

### Translator feedback view

Opening a cell that is in review takes you to the side-by-side editor. The source content
item is shown read-only on the left; the editable translation is on the right, field by
field. Each content type shows the fields it can translate (for an article: title, intro
and full text, meta description and keywords, note, and the image alt text and captions),
together with any translatable custom fields the item has.

The page follows your administrator language, so working in a right-to-left language such as
Arabic gives a right-to-left page and editor. The original keeps its own direction: an English
source still reads left to right beside it. Its direction comes from the source language's
language pack, or, where no pack for it is installed, from its language code.

Correct whatever is wrong, then choose one of:

- **Save** or **Save & Close** keeps your changes on the draft. The translation stays in
  review, so you can come back to it later. Nothing is learned yet.
- **Approve** says the translation is finished. Every field you changed is compared with
  what the machine produced, and each difference is stored as feedback for the component to
  learn from. Approving leaves the translated item where it is: it does not publish one that
  is unpublished, and does not take down one that is already live.
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

### The alias of a translated item

A translation gets the alias its own translated title gives it, so a Portuguese article is
reachable under a Portuguese URL.

Joomla keeps an alias unique among an item's neighbours - an article among the articles of its
category, a category or a tag among the children of its parent - and the language is no part of
that. Two languages whose titles lead to the same alias are neighbours like any other: Spanish
"Referencia" and Portuguese "Referencia" both come down to `referencia`. Where the alias a
translation would take is already held, the component appends the language code, so the second
of them becomes `referencia-pt-pt`, and where that is held too it is numbered.

Joomla has the last word on whether an alias is free, and it says so only by refusing to save.
Where it refuses one the component read as free, the translation is still saved, under an alias
that names the item it was translated from. That is worth knowing because it is the one alias
that does not read well - `referencia-pt-pt-587` - and seeing one means the two disagreed.

A title Joomla cannot write in the URL alphabet at all, as it cannot for Persian, falls back to
the source item's alias with the language appended, rather than to the timestamp Joomla would
use.

A translated menu item is placed in its own language's menu, under the translation of its
parent, and opens the translation of the page it points at. Where the parent or the page has no
translation yet, the menu item waits at the root of the menu or points at the original, and it
is moved under its parent, or pointed at the translated page, as soon as that is translated.

At the root of a menu Joomla keeps a few names for itself: `component`, and every folder in the site root. A menu item that
translates to one of those - "Media", "Plugins", "Modules" - takes the language-appended
alias instead, as it would from any other alias already in use. Two languages may share a
menu item alias, as Joomla allows, so a menu URL keeps the alias its own title gives it.

An alias is also cut back to a hundred characters, on a word. Joomla builds one out of the whole
title and gives it four hundred characters in the database, so an item carrying more than a title
in its title field would otherwise produce an alias that cannot be stored at all, and the
translation would be refused over it.

Changing an alias by hand afterwards is fine, but translating the item again builds it from the
title once more, as saving an item in Joomla with the alias field emptied does.

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
until a person reads it and publishes it. The **Auto-Publish New Rules** option (Options)
changes that: with it on, a distilled rule reaches the next translation without waiting to
be read, which suits a site that would rather keep the system moving and review the rules
afterwards.

The option applies only as a rule is created. When the distiller later refines a rule it
already wrote, that rule keeps the state it has, so unpublishing or trashing one is never
undone by a later run.

You can edit any rule, change its type, publish it, unpublish it or trash it. You can also
write a rule by hand, which is useful for a convention your community already knows without
waiting for someone to correct a translation first.

Each rule also carries a confidence, between 0 and 1, which is the distiller's own estimate
of how well established the convention looked. It is there to help you decide what to
publish.

Each rule also records its **origin**, how it came to exist, and the Rules view can filter on
it:

- **Distilled from feedback**: learned from corrections translators made.
- **Imported from language files**: learned from a language pack by the seed task (see
  "Starting from your language pack").
- **Manually authored**: written by hand in the Rules view.
- **Imported from the wiki**: reserved for rules taken from the documentation wiki, which
  nothing produces yet.

The origin is set when a rule is created and cannot be changed afterwards.

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

### Carrying rules between sites

A rule set your community has built is worth more than one site. The Rules view can write its
rules out as a file and read one back.

**Export** sends what the list is currently showing, so the search box and the type, language,
origin and status filters decide what goes into the file. Tick some rows first and you get only
those. A set holding a single language is named after it.

The file describes what the rules are, not where they came from on your site. Ids, the feedback a
rule was distilled from, who created it and when, and the manual ordering all stay behind, because
they mean nothing anywhere else. The status stays behind too, and is chosen when the file is read.
What each rule does keep is its **origin**, so a set that arrived from elsewhere can still be told
apart later.

**Import** reads such a file back. It needs the Create permission, since it makes rules.

A rule the site already has is passed over rather than written twice, and you are told how many
were. Rules are matched on what they are matched *against* rather than on their name, because the
wording of a name changes every time a rule is restated: a terminology or preservation rule by its
term, in the standard form where there is one, and a style rule by its text, both within one target
language and rule type.

Three things are reported separately, because each one is a decision waiting for you:

- A match on a rule you have **trashed** is named and left in the trash. Throwing a rule away is a
  deliberate answer, so an import does not undo it.
- A rule for a **content language this site does not have** is refused. It would store perfectly
  well and then never be used, which is worse than being told.
- A rule the component cannot accept, such as one with no target term, is refused with the reason.

Imported rules arrive **unpublished** unless you choose otherwise, for the same reason distilled
ones do: nothing reaches a translation until a person has read it.

### Marking an item as "no need for translation"

On a source item's edit form, in the Translations tab, a toggle marks the item as one that
should not be translated. It is available for articles, categories, tags and site menu
items. Marked items drop out of the queue (and can be brought back from the queue's filter).

## Doing it on a schedule

Both halves of the loop can run unattended, through Joomla's own **Scheduled Tasks**
(**System, Manage, Scheduled Tasks**). The component ships two task types:

- **Translate Queued Items** takes items with no translation yet, and ones sent back to
  pending, and translates them a few at a time.
- **Distil Translation Rules** reads the feedback collected from approvals and turns it into
  rules, unpublished unless **Auto-Publish New Rules** is on.

Both are optional. Everything they do can also be done by hand: translate from a queue cell,
and distil with the **Distil Now** button in the Rules view. How many items each run handles
is a setting on the task, so you can keep a run short on a busy site.

The translate task has two settings:

- **Batch Size** (5 by default): the most items one run translates. Each item is at least one
  request to the provider, and a long item is several (see "Long articles" below).
- **Time per Run (seconds)**: as for the distil task below. A run stops before a request that
  would probably not finish in time, but the first request of a run is always sent.

The distil task has two settings:

- **Corrections per Request** (50 by default): how many feedback rows go to the provider in
  one request. Every request also carries the existing rules as context, about ten thousand
  tokens, so a request with only a few corrections pays mostly for that context. Long rows,
  such as whole articles, go in fewer per request, and only the paragraphs that changed are
  sent.
- **Time per Run (seconds)**: a run keeps sending requests until this time is used up, and
  stops before a request that would probably not finish in time; the next run carries on.
  With **0** a run takes 25 seconds over the web and 240 seconds from the command line. With
  Web Cron called every minute, 110 is a good value (see "Running the scheduler" below).

Every request to the provider is paid for, so a request that fails is not repeated without
end. A feedback item whose request fails is tried again in a later run, in a request half the
size, then on its own; after its third failed attempt it is set aside with the status `failed`
and the last error, and no run sends it again. The attempt is counted before the request is
sent, so this holds even when a run is cut off while it waits for the provider. A run stops
after two failed requests in a row, because the provider is then most likely unreachable. A
run only asks to be repeated straight away when it got something done; a run that only failed
waits for the task's next scheduled time. The results of each request are saved as soon as it
is answered, so a run that stops part-way keeps everything it finished.

The translate task counts its attempts the same way, per item. An item whose translation
fails is tried again in a later run, after the items not tried yet, so it never holds up the
rest; after its third failed attempt its cell shows **failed**, the last error is kept with it,
and no run sends it again. Translate such an item by hand from its cell once the cause is
dealt with, or give it new attempts with **Retry Failed Translations** (see "Maintenance
tasks"). Editing the source item also gives its translations a fresh set of attempts.

A run that got part of a long article translated gives that article its attempts back, because
the run worked: the attempts are there to stop an item that cannot be translated from being
paid for again and again, not to limit how many runs a long article may take. An article whose
*next* part keeps failing still runs out of attempts and is set aside, since a part that was
already translated is not sent again and so does not count as progress a second time.

A provider that cannot be reached, or an empty credit balance, uses up attempts as well. A run
stops after two failures in a row, so each run spends an attempt on at most two items, but a
schedule that keeps running through a long outage sets item after item aside. Run **Retry
Failed Translations** once the provider answers again.

### Long articles

A translation provider has to write the whole translated article back, and that reply is both
what takes the time and what the provider caps. An article of a few thousand words asks for
more than a model will write in one reply, so it can never be translated in one request,
however long the hosting allows the request to run; a shorter one can still take longer than a
web request is given. Either way the run is cut off, nothing comes back, and the article is set
aside as failed after three attempts.

The component therefore translates a long article in parts, and carries on by itself:

- The article is cut into parts of about **Characters per Request** characters (Options, 4000
  by default). Cuts are only made between two HTML tags, so no tag and no word is ever split,
  and the shallowest boundary near that size is preferred, so a part tends to end at a
  paragraph, a heading or a table row. Where a stretch holds no tags, it is cut at the end of
  a sentence or, in a code sample, at a line break. Only a stretch with none of these in it -
  a single very long line - is left whole and makes the size overshoot.
- Each part is a request of its own, and every answered request is kept before the next is
  sent. A run that uses up its **Time per Run** stops between parts and asks to run again; the
  next run carries on with the parts that are left. Nothing is paid for twice.
- The article is saved as a draft, and its cell turns to **review**, only once every part is
  translated. Until then the cell stays **pending**, and the task log says how many parts a
  run got through.
- So the parts read as one article, each request is told what the article is about - its title
  and the headings of its sections - and which terms the earlier parts were already translated
  with, so a word is not translated one way in one section and another way in the next.

**Characters per Request** is the one setting worth tuning. The provider's reply grows with
it, so it decides how long a single request takes:

- Lower it if long articles still time out. Your hosting's PHP time limit for a web request is
  what a single request has to fit inside, and 30 seconds is common on shared hosting. That
  limit is the one that counts, because translating has to run over the web for now - see
  "Translating needs the web, not the command line" below.
- Raise it when your hosting allows a web request a generous time. Fewer, larger parts means
  fewer requests, less context repeated in each of them, and each part reading more naturally
  in itself.

The least you can set is 1000. An article short enough to fit in one request is sent exactly as
it always was, in one request, so this changes nothing for ordinary content.

### Translating needs the web, not the command line

Run the **translate** task through Web Cron or the Lazy Scheduler. Started from the command
line it translates the article, pays for it, and then cannot save the draft, because of an open
Joomla bug: a component model's form cannot be loaded outside a web request
([joomla/joomla-cms#43347](https://github.com/joomla/joomla-cms/issues/43347), open since
April 2024 and affecting Joomla 4, 5 and 6). The run ends with
`Undefined constant "Joomla\CMS\MVC\Model\JPATH_COMPONENT"`.

This is about the translate task only. The seed and distil tasks have no draft to save, so they
are unaffected and the command line remains the better place for them.

**Nothing is paid for twice.** The parts that were translated are stored, so a later run reuses
them and sends no new requests. But a command-line attempt leaves an unfinished draft behind in
the target language, with no link to its source. A later run cannot tell that the unfinished
draft belongs to the item, and saves a second translation beside it. To recover an item a
command-line run has touched:

1. Delete the unfinished translation in the target language (Content, Articles, filtered on
   that language - it is the one that is not linked to its source).
2. If the item is already shown as **failed**, run **Retry Failed Translations** (see
   "Maintenance tasks").
3. Let the translate task run over the web. It finishes the article from the stored parts,
   without sending the text again.

That holds as long as the source has not been edited in the meantime; an edited source is cut
into different parts, so the stored ones no longer fit and are translated again.

Pressing **Translate** on a long article's queue cell does the same thing, but a web request
has little time: expect a message saying the article is being translated in parts. The parts
done are kept, and the scheduled task finishes it - or you can press **Translate** again.

### Running the scheduler

The translate, seed and distil tasks are scheduled tasks like any other, managed in **System,
Manage, Scheduled Tasks**. Joomla starts them over the web, and for regular work on a site
**Web Cron** is the way to do that.

#### With Web Cron

1. In **System, Manage, Scheduled Tasks, Options**, enable **Web Cron**, and switch the
   **Lazy Scheduler** off.
2. Give the **Webcron Link** shown there to the cron service of your hosting, or to an
   external cron service, to be called **every minute**.

Each call is a web request that starts one due task, and none while another task is still
running, so the seed and distil tasks take turns. A run stops after its **Time per Run**, and
the next call starts the next run.

Recommended settings for seeding a language pack with Web Cron:

| Where | Setting | Value | Why |
| --- | --- | --- | --- |
| Seed task | Strings per Request | 25 | The default; a request takes about ten seconds. |
| Seed task | Time per Run (seconds) | 110 | See below. |
| Seed task | Schedule | every minute | The tasks take turns anyway; this keeps no minute unused. |
| Distil task | Corrections per Request | 50 | The default; a request takes about 25 seconds at low effort. |
| Distil task | Time per Run (seconds) | 110 | See below. |
| Distil task | Schedule | every minute | As for the seed task. |
| Scheduled Tasks Options | Task Timeout (seconds) | 600 | See below. |
| Scheduled Tasks Options | Lazy Scheduler | off | One scheduler is enough. |
| Translation - Claude, RAG - Claude | Effort | Low | The cheapest and fastest; see "Installing and setting up". |
| Translation - Claude, RAG - Claude | Timeout (seconds) | 300 | The default. |

- **Time per Run 110 seconds.** With 0, a run over the web takes 25 seconds, which fits the
  time limits of nearly any hosting, but a whole language pack then takes four to five hours.
  With 110, a run ends just before the call two minutes after it started, so that call starts
  the next run; the call in between finds a task running and does nothing. About ten seed
  requests or four distil requests fit in one run, and a pack takes about 1.5 to 2 hours. This
  needs hosting that lets a web request run about two minutes. If runs are cut off, lower it,
  for example to 60.
- **Task Timeout 600 seconds.** Joomla keeps a running task locked until it finishes, or until
  this time has passed. A run takes at most its Time per Run plus the Timeout of one request
  that was still under way, 410 seconds here, so the same task is never started twice. If the
  hosting does cut a run off, its lock stays until the Task Timeout has passed, and over Web
  Cron no task starts in the meantime; the tasks then wait up to ten minutes, without losing
  anything.
- **When the cron service stops waiting early**, nothing is lost: the results of each request
  are saved as soon as they come in, so at worst the last request of a run is sent again in a
  later run.

#### From the command line, for long-running work

Seeding a whole language pack is time-intensive work. When your hosting lets you add cronjobs
that run a command, Joomla can also start its scheduled tasks from the command line, where no
web time limit applies; the tasks stay managed in the Administrator as before. The Joomla
User Manual explains the set-up in [How to Run Scheduled Tasks from the Command Line](https://guide.joomla.org/user-manual/scheduled-tasks/run-task-from-cli).
For this component:

- Use it for the **seed** and **distil** tasks. The translate task cannot save a draft from the
  command line while [joomla/joomla-cms#43347](https://github.com/joomla/joomla-cms/issues/43347)
  is open, so leave that one to Web Cron or the Lazy Scheduler - see "Translating needs the web,
  not the command line" above.
- Let the cronjob run `scheduler:run --all` every minute, so the seed and distil tasks run
  side by side instead of taking turns. `--all` runs every task that is due, the translate task
  included, so while #43347 is open give the cronjob one `scheduler:run --id=<id>` line per
  task instead, and keep Web Cron on for translating.
- Leave **Time per Run** at 0 in both tasks: from the command line a run then takes 240
  seconds.
- Keep the **Task Timeout** at 600 seconds and the other settings as in the table above.

From the command line Joomla can start a task while the same task is still running in an
earlier process: `scheduler:run` does not check a task's lock. The seed and distil tasks
guard against that themselves (since version 1.2.1), and so does the translate task (since
version 1.3.0): a run that finds another run of the same job busy - the distiller, the
translate task, or the seed task for the same language - does nothing and logs that another
run is still busy, so nothing is sent or paid for twice.

The **Lazy Scheduler**, which runs tasks when someone visits the site, is fine for small
amounts of work but too slow for a whole language pack.

### Maintenance tasks

Six more task types fix things after the fact. Create them as tasks without a schedule and
start them with **Run Test** in the list of Scheduled Tasks:

- **Retry Failed Translations** gives the translations set aside as `failed` a new set of
  attempts. Run it once the cause is fixed, such as an invalid model, an empty credit balance
  or a time limit raised by the hosting.
- **Retry Failed Translator Feedback** gives the feedback rows set aside as `failed` a new set
  of attempts. Run it once the cause is fixed, such as an invalid model or an empty credit
  balance.
- **Retry Failed Language Pack Strings** does the same for the seed task's strings.
- **Forget a Seeded Language** undoes the seeding of one language, so its pack can be seeded
  again from scratch (see "Seeding a language again" below).
- **Merge Duplicate Translation Rules** merges terminology and preservation rules with the same
  term and the same translation, for one language or all. The oldest rule of each set is kept;
  it takes over the evidence and the highest confidence of the others, and is published when
  one of them was. The others go to the trash, so they can still be restored. New rules are
  checked against the existing ones when they are distilled, so this is mostly needed for rules
  distilled before version 1.2.0.
- **Import Translation Corrections** applies a file of corrections, see below.

### Importing a set of corrections

A correction that has to be made in many translations, such as a menu item and its article
that should carry the same title, or a field label that has to follow the language pack
everywhere, is too much work to make one translation at a time in the Translator feedback view.
**Import Translation Corrections** applies such a set from a file:

1. Put the file inside the site, for example in `tmp/translations-corrections.json`.
2. Create a task of the type **Import Translation Corrections**, with that path as
   **Corrections File**, and start it with **Run Test**. A run that runs out of time asks to be
   resumed: with Web Cron (see "With Web Cron" above) the next calls carry on by themselves,
   with **Time per Run** at 50; without it, click **Run Test** again until the log says the
   corrections are recorded as translator feedback. From the command line a run lasts four
   minutes, which suits a large file best.
3. Read the task log. It names every translation it left alone, and why. Remove the file when
   the import is done.

Each translation is saved through its own component, as the **Save** button of the Translator
feedback view does, so it gets a new version that can be restored. Its review state stays as
it is: the import corrects some fields, it does not review the whole translation.

Every field in the file carries a print (SHA-256) of the text it was corrected from. A field
that no longer holds that text was edited since the file was made, and the whole translation is
then left alone. A field that holds its correction already is passed over, so running a file
again changes nothing. A translation checked out by someone else is left alone too.

A run that resumes recognises the translations already corrected from a few queries, so its
time goes to the ones still to correct, also late in a large file.

Once every translation is handled, the file's feedback is recorded, in the next run when the
last corrections took up the run's time: each distinct correction once, with the number of
places it was made, under the origin the file names (`repair` by default). The distiller turns it into rules as it does with feedback from translators.

The file is JSON:

```json
{
  "format": "translations-corrections/1",
  "source_origin": "repair",
  "items": [
    {"type": "com_content.article", "source_id": 157, "language": "de-DE", "id": 2221,
     "fields": {"title": {"before": "<SHA-256 of the current title>", "after": "Datenschutz-Workflow"}}}
  ],
  "feedback": [
    {"type": "com_content.article", "source_id": 157, "language": "de-DE",
     "source_text": "Privacy Workflow", "machine_draft": "Privacy Workflow",
     "human_correction": "Datenschutz-Workflow", "occurrences": 1}
  ]
}
```

`type` is the content type key of the queue, `source_id` the id of the source item and `id`
the id of the translation. The fields are the columns of the item's table.

## Starting from your language pack

Before anyone has corrected a translation, a site's language packs already hold years of
decisions by its translation team. The seed task learns from those: it translates the
strings of the source language's pack with no rules at all, compares each result with the
translation the target language's pack already carries, and records every difference as
feedback. The distiller then turns that feedback into rules, like any other, with the origin
**Imported from language files**.

The seed task is optional and a separate download:

1. Download `plg_task_translationsseed-<version>.zip` from the same Releases page and install
   it. It needs the package installed first.
2. Enable **Task - Translations Seed** in **System, Manage, Plugins**.
3. In **System, Manage, Scheduled Tasks**, create a **Seed Translation Rules From a Language
   Pack** task and set:
   - **Language**: the installed language to learn from.
   - **Strings per Request** (25 by default): how many texts go to the provider in one request.
   - **Time per Run (seconds)**: as for the distil task; 110 with Web Cron called every minute
     (see "Running the scheduler"). A run that seeded strings and leaves some for later is
     followed by the next one, so a whole pack is worked through over several runs.
   - **Language Files**: a comma separated list such as `com_content.ini`, or empty to read the
     whole pack.

The task translates through the translation plugin, so that plugin must be enabled and have
its key. A string that has been seeded is not sent again, so running the task again does not
pay for it twice. Seeding is incremental: when a new version of the language pack adds files or
strings, the next run sends only those. A string whose English text or whose translation in the
pack has changed since it was seeded is sent again too, so what the language team changed is
learned as well; the seed task notices this by a fingerprint it keeps of both texts. Strings
seeded before version 1.2.0 get that fingerprint the first time a run sees them, without being
sent again. A text that occurs in several language files - the site, administrator and
API files repeat many strings - is translated once for all of them, and the feedback says how
many strings it stands for. A string the machine already translates the way the pack does
writes no feedback, because there is nothing to learn from it. Like the distiller, the seed
task tries a string that failed again in a smaller request, and sets it aside as `failed` after
its third attempt.

### Seeding a language again

A string that has been seeded is never sent again, so to seed a pack anew - for instance with a
better model, or with the cheaper and cleaner distillation of version 1.2.0 - first forget the
earlier seeding:

1. Create a **Forget a Seeded Language** task, choose the **Language**, save it, and start it
   with **Run Test**.
   - The record of which strings were seeded, and the feedback the seed task wrote for that
     language, are **deleted**. This cannot be undone.
   - The **unpublished** rules learned only from the pack are moved to the **trash**, where they
     can still be restored.
   - **Published** rules are kept, because someone reviewed them, unless you switch on **Also
     Trash Published Rules**. Be very careful with that option: published rules steer every new
     translation into the language, and with it on all published rules learned from the pack
     go to the trash and stop being used at once. They can be restored from the trash in the
     Rules view, as long as the trash has not been emptied.
   - Feedback and rules that came from translators are always kept.
2. Run the seed task for that language again, and let the distil task follow.

Seed version 1.2.0 needs version 1.2.0 of the package, because it records how many strings a
feedback row stands for. For a whole core language pack, plan for about 1.5 to 2 hours of
seeding and distilling with the settings in "Running the scheduler". The API cost was
estimated at $8-12 per pack with Claude Sonnet at low effort; it depends on the language and
the model.

## Translators working from the site

A translator does not need access to the administrator. The queue and the rules can be put on
the site as menu items: in **Menus**, add a menu item and choose **Translator Feedback**, then
**Translation Queue** or **Translation Rules**. From there a translator can translate, correct
and approve, and write, edit, publish and unpublish rules.

Who may use them is decided by the **Edit** permission on the component. Joomla's standard
**Publisher** group already has it, so a Publisher can translate without any further setup,
and someone without it is refused. Distilling, and exporting or importing rules, stay in the
administrator.
