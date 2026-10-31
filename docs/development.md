# Development

Everything below is run from the repository root, and nothing below needs a Joomla
installation except the end-to-end tests.

## What is released

There are **three downloads**, and they are built separately:

| Download | What is in it |
| --- | --- |
| `pkg_translations-<version>.zip` | The component and the five plugins of the translation pipeline: `content/translations`, `translation/claude`, `rag/claude`, `task/translationsdistiller`, `task/translationstranslate`. |
| `plg_task_translationsseed-<version>.zip` | The seed plugin on its own. |
| `plg_task_translationslanguagefiles-<version>.zip` | The language files plugin on its own. |

The two plugins stay out of the package because they are not part of the pipeline. The seed
plugin reads an installed language pack once to give a site something to learn from before
anyone has corrected anything, and a site that does not want that should not have it installed
at all. The language files plugin translates this package's own language files (see
[Translating the package's language files](#translating-the-packages-language-files)), which
only the site the project makes them on needs.

## Building

```
php build/build.php                 # the package
php build/build.php seed            # the seed plugin
php build/build.php languagefiles   # the language files plugin
```

All of them write into `build/`, which is git-ignored apart from the scripts themselves. Each
run clears the archives an earlier version of the same extension left there, because the
release workflow uploads `build/<extension>-*.zip` and would otherwise publish both.

What goes into the package is read from `pkg_translations.xml`, so adding an extension to
the release is one `<file>` line in that manifest - the build works out where it lives from
its type, group and id. Each version is read from the manifest that carries it, which is
the single place it is written: the archive is named after it, and the release workflow
refuses to publish a tag that disagrees with it.

## Update servers

An installed site learns that a new version exists by asking the URL in its extension's
`<updateservers>`. Every download has one, serving a file from `updates/` in this
repository:

| Extension | Update file |
| --- | --- |
| `pkg_translations` | `updates/pkg_translations.xml` |
| `plg_task_translationsseed` | `updates/plg_task_translationsseed.xml` |
| `plg_task_translationslanguagefiles` | `updates/plg_task_translationslanguagefiles.xml` |

Those files are **generated, not edited**:

```
php build/update-xml.php
```

Run it after bumping a version, and commit the result. A stale one is invisible from here
and costly out there - it either hides the release from every installed site, or offers
them a download that 404s - so `UpdateServerTest` fails when a manifest and its update file
disagree, and the release workflow runs the suite before it builds.

A site remembers the update URL it was installed with, so moving those files cuts off the
sites that already have them. They are meant to stay where they are.

All downloads are published on one release, tagged after the **package** version. That is
why each plugin's download URL carries the package's version in its path and its own in its
file name.

The generated files declare `<targetplatform name="joomla" version="6\.[0-9]+"/>`. The
package is developed and tested on Joomla 6; widen that pattern in `build/update-xml.php` if
an older series is ever supported.

## Unit tests

```
composer install
composer test          # or: vendor/bin/phpunit
```

The suite covers this package's own logic and nothing else. It needs no database, no site
and no network, so it runs in well under a second and a release can be gated on it.

It also runs without Joomla, which is deliberate. What it loads is the package's own
classes, the framework packages the CMS itself ships (`joomla/registry`, `joomla/event`),
and `tests/Stub/joomla.php` - a handful of empty classes that exist only so a class that
extends `CMSPlugin` or `FormModel` can be loaded at all. Nothing in the suite asserts
anything about those stubs: a test that needed one of them to *do* something would be
testing Joomla rather than this package.

Whether the package still fits the CMS it is installed into is a question about signatures
rather than behaviour, and PHPStan answers that one:

```
composer analyse
```

`phpstan.neon` scans a real Joomla in `joomla/`, so a checkout of the CMS has to be there.

### What is tested, and why

| Test | What it protects |
| --- | --- |
| `RuleRetrieverTest` | Which of a language's rules reach the prompt for one item. A rule that is not selected never reaches the model, and one selected too eagerly spends prompt on an item it has nothing to say about - both are silent failures. |
| `WordNormaliserTest` | Which words are worth asking a provider about, and what a provider is allowed to answer. The answers are written to a table that later runs read back as fact. |
| `CorrectionDiffTest` | What a translator's correction is reduced to before it is distilled, and whether a change is labelled as a term or as a phrase. |
| `TranslatableValuesHelperTest` | Reading an item's translatable values and writing a translation back over them, including the keys of a JSON column that must survive the round trip. |
| `ContentTypesHelperTest` | The shipped `contenttypes.json`: that every relation resolves, that a type is translated after the types its items point at, and that every type says which items share an alias space with its drafts. |
| `TranslationProviderTest`, `RagProviderTest` | What the Claude plugins ask the API for and, more to the point, which replies they refuse. |
| `LanguagePackSeedTest` | Which of a language pack's strings are worth paying a provider to translate, how they are batched, and that a text repeated across files is translated once. |
| `TextChunkerTest` | How an item too long for one request is divided into several: that the parts rebuild the source character for character, that no cut lands inside a tag or a word, and that every part belongs to exactly one request. The parts are written into the site's content, and a plan that came out differently on the next run would make the parts a previous run paid for unusable. |
| `DraftAliasTest` | The alias a draft is saved under, and that it steps aside for the aliases its neighbours hold. Joomla scopes an alias by an item's neighbours and not by its language, so two languages whose titles come down to the same alias clash, and the component's answer has to reach a free alias every time: a refused save throws away a translation that has been paid for. |
| `StringTranslatorTest` | That an answer is kept only where it can be a translation of the string it came back under. A provider answers several strings at once, keyed, and two answers arriving under each other's keys cost an item its title and its body at once - which is what happened on guide.joomla.org, where an article took its own body for its title. |
| `RunResultTest` | Whether a seed, distil or translate run asks to run again, and how much smaller a retry is. A run that resumes without progress is how one failing batch was retried, and paid for, for days. |
| `TimeBudgetTest` | When a run stops sending requests, so it neither overruns the web server or the scheduler's lock nor stops too early. |
| `RunLockTest` | The name of the database lock that keeps two runs of one job apart: the same for every run of a job, different per job and per site, and within MySQL's 64 characters. Two runs at once would send, and pay for, the same requests. |
| `DistillerRequestTest` | What goes into one distil request (one language, one attempt level, a size and token budget), how long texts are cut to the changed blocks, and which rules count as the same rule. |
| `PackageManifestTest` | That the release is complete: every extension in the tree is in a manifest, and every manifest carries a version a tag can match. |
| `SchemaUpdateTest` | That an updated site and a new one get the same tables: every column an update file adds is in the install file too, and every addition ends in its CAN FAIL marker directly before the semicolon, which is the only place Joomla recognises it. |
| `UpdateServerTest` | That a site asking for updates is told the version that was released, and pointed at the archive the build actually produces. |

What is **not** tested is Joomla: no test asserts that a list model filters, that a table
stores, or that a form renders. Those are the CMS's own behaviours, covered by its own
suite, and imitating them here would only test the imitation.

## End-to-end tests

The Cypress specs cover what the unit tests structurally cannot reach: a browser, a
database and an installed Joomla. They expect a site with the package installed and a
content language for the target language.

```
npm install
cp cypress.config.dist.js cypress.config.js   # then fill in the site and the Super User
npm run cypress:open                          # or: npm run cypress:run
```

`cypress.config.js` is git-ignored: it holds the address and the Super User of somebody's
own test site. The specs create rules named with a `[cypress]` marker and trash them again
afterwards, so they can be run repeatedly against the same site.

## Releasing

A release is one tag. The workflow in the main repository reacts to a pushed `v*` tag by
checking the tag against the package manifest, running the unit tests, building the three
archives and publishing them on the release.

So: bump the version in the manifest of whatever changed, run `php build/update-xml.php`,
commit both, and tag `v<version>` matching `pkg_translations.xml`. The workflow file itself
has to be committed before the tag is pushed, because GitHub reads a workflow from the
commit the tag points at.

The seed plugin and the language files plugin each carry their own version and are released
under the same tag as downloads of their own; the workflow logs all three versions.

Once the downloads are published, the workflow writes their SHA-512 checksums into the update
files with `php build/update-xml.php --checksum` and commits that to `main` itself. Joomla
checks a downloaded update against that checksum, and warns when there is none. The checksum
has to come from the workflow because a zip built on another machine has different timestamps
and line endings, so its hash does not match. Pull `main` after a release, before the next
version bump. Running `build/update-xml.php` without `--checksum` keeps a committed checksum
while the download stays the same, and drops it when the version changes.

## Translating the package's language files

The package is written with en-GB language files, and translators should see it in their own
language - for a right-to-left language the editor only works properly when the screens around
it are in that language too. `plg_task_translationslanguagefiles` makes the other languages, on
a site whose translation rules are worth using: guide.joomla.org, whose rules are mostly learned
from the core language packs.

1. Install the release's `plg_task_translationslanguagefiles-<version>.zip` on that site, next
   to an up-to-date package and a configured translation plugin, and enable **Task - Translations
   Language Files**.
2. In **System, Manage, Scheduled Tasks**, create a **Translate Translator Feedback's Language
   Files** task. **Languages** empty means every published content language except en-GB;
   **Strings per Request** and **Time per Run** work as in the seed task. Let Web Cron run it, or
   press **Run Test** until the log says nothing is left.
3. Open the task. It shows how far each language has got, and offers the files in three ways,
   all made from the stored translations, so they can be fetched again at any time. Each needs
   permission to manage Scheduled Tasks.
   - **Download All for the Repository** gives one zip for this repository. Unpack it into
     `src/`: every path in it is the path the file has here, such as
     `plugins/task/translationsdistiller/language/ar-AA/plg_task_translationsdistiller.ini`. Its
     `README.md` lists the `<language>` lines to add to each manifest - Joomla only installs the
     language files a manifest names - and how complete each language is.
   - **Download Package**, per language, gives an installable zip: a file extension
     `files_translatorfeedback_<tag>` (for example `files_translatorfeedback_nl-nl`) that puts
     each translated file where a site keeps its en-GB original, with the tag in place of en-GB.
     Install it on any site that has the package, through the normal extension installer.
   - **Install on This Site**, per language, installs that same package here straight away,
     through Joomla's installer, and needs permission to manage extensions as well. The column
     **Installed Here** shows the version installed.

   The package has the same element every time, so a newer one of a language replaces the older
   one, and uninstalling it removes its files. A language file goes into the package only when it
   has a translated string.

The task reads the en-GB files the site has installed: the package's own, every extension
installed with it, and the seed plugin's. It sends them through the component's own route - the
published rules of the target language that apply to each batch, and the enabled translation
plugin - with the language constant as context. Every answer is stored with a fingerprint of its
English text, so after the site is updated to a new release, running the task again sends only
the strings that are new or changed. A translation that loses a placeholder or an HTML tag is
refused and retried in a smaller request, and set aside after three attempts; **Retry Failed
Language File Strings** gives those new attempts, and **Forget Translated Language Files** makes
the next run start a language from scratch, for instance after its rules have improved. A string
not translated yet is written in English, so no screen shows a bare language constant.
