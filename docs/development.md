# Development

Everything below is run from the repository root, and nothing below needs a Joomla
installation except the end-to-end tests.

## What is released

There are **two downloads**, and they are built separately:

| Download | What is in it |
| --- | --- |
| `pkg_translations-<version>.zip` | The component and the five plugins of the translation pipeline: `content/translations`, `translation/claude`, `rag/claude`, `task/translationsdistiller`, `task/translationstranslate`. |
| `plg_task_translationsseed-<version>.zip` | The seed plugin on its own. |

The seed plugin stays out of the package because it is not part of the pipeline: it reads
an installed language pack once to give a site something to learn from before anyone has
corrected anything, and a site that does not want that should not have it installed at all.

## Building

```
php build/build.php          # the package
php build/build.php seed     # the seed plugin
```

Both write into `build/`, which is git-ignored apart from the scripts themselves. Each run
clears the archives an earlier version of the same extension left there, because the release
workflow uploads `build/<extension>-*.zip` and would otherwise publish both.

What goes into the package is read from `pkg_translations.xml`, so adding an extension to
the release is one `<file>` line in that manifest - the build works out where it lives from
its type, group and id. Each version is read from the manifest that carries it, which is
the single place it is written: the archive is named after it, and the release workflow
refuses to publish a tag that disagrees with it.

## Update servers

An installed site learns that a new version exists by asking the URL in its extension's
`<updateservers>`. Both downloads have one, serving a file from `updates/` in this
repository:

| Extension | Update file |
| --- | --- |
| `pkg_translations` | `updates/pkg_translations.xml` |
| `plg_task_translationsseed` | `updates/plg_task_translationsseed.xml` |

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

Both downloads are published on one release, tagged after the **package** version. That is
why the seed plugin's download URL carries the package's version in its path and its own in
its file name.

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
| `ContentTypesHelperTest` | The shipped `contenttypes.json`: that every relation resolves, and that a type is translated after the types its items point at. |
| `TranslationProviderTest`, `RagProviderTest` | What the Claude plugins ask the API for and, more to the point, which replies they refuse. |
| `LanguagePackSeedTest` | Which of a language pack's strings are worth paying a provider to translate, and how they are batched. |
| `PackageManifestTest` | That the release is complete: every extension in the tree is in a manifest, and every manifest carries a version a tag can match. |
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
checking the tag against the package manifest, running the unit tests, building both
archives and publishing them on the release.

So: bump the version in the manifest of whatever changed, run `php build/update-xml.php`,
commit both, and tag `v<version>` matching `pkg_translations.xml`. The workflow file itself
has to be committed before the tag is pushed, because GitHub reads a workflow from the
commit the tag points at.

The seed plugin carries its own version and is released under the same tag as a second
download; the workflow says so in its log when the two differ.
