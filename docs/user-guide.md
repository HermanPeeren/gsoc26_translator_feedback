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
  has been edited since it was made,
- **review**, a draft is waiting for a translator,
- **approved**, a translator has finished with it,
- **published**, the translation was approved and published from here.

Each state has its own icon, so none of them has to be told apart by colour alone. The colours
group them instead: grey for nothing yet, blue for pending and approved, green for published,
and amber for review, the one state that is waiting on a translator. Where a cell can be
clicked its tooltip also says what clicking does.

With many languages the grid scrolls sideways on its own, keeping the source column beside its
row, so the page itself never grows wider than the screen.

Use the tab above the grid to move between content types. There is one tab per type the
component translates: articles, categories, tags and site menu items.

From a cell with no translation, or one that is pending, you can trigger a translation. It
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

Every request to the provider is paid for, so a request that fails is not repeated without
end. A feedback item whose request fails is tried again in a later run, in a request half the
size, then on its own; after its third failed attempt it is set aside with the status `failed`
and the last error, and no run sends it again. The attempt is counted before the request is sent, so this holds even
when a run is cut off while it waits for the provider. A run only asks to be repeated straight
away when it got something done; a run that only failed waits for the task's next scheduled
time. The results of each request are saved as soon as it is answered, so a run that stops
part-way keeps everything it finished.

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
   - **Batch Size**: the most strings seeded in one run (50 by default). A run that seeded
     strings and leaves some for later is followed straight away by the next one, so a whole
     pack is worked through over several runs.
   - **Language Files**: a comma separated list such as `com_content.ini`, or empty to read the
     whole pack.

The task translates through the translation plugin, so that plugin must be enabled and have
its key. A string that has been seeded is not sent again, so running the task again does not
pay for it twice. A string the machine already translates the way the pack does writes no
feedback, because there is nothing to learn from it. Like the distiller, the seed task tries a
string that failed again in a smaller request, and sets it aside as `failed` after its third
attempt.

## Translators working from the site

A translator does not need access to the administrator. The queue and the rules can be put on
the site as menu items: in **Menus**, add a menu item and choose **Translator Feedback**, then
**Translation Queue** or **Translation Rules**. From there a translator can translate, correct
and approve, and write, edit, publish and unpublish rules.

Who may use them is decided by the **Edit** permission on the component. Joomla's standard
**Publisher** group already has it, so a Publisher can translate without any further setup,
and someone without it is refused. Distilling, and exporting or importing rules, stay in the
administrator.
