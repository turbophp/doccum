# Search

Keyword search goes through one seam, `App\Search\SearchIndex`, resolved by
`DoccumServiceProvider` from the active database driver — there is no
separate `SEARCH_*` environment variable to set, because the seam
deliberately follows `DB_CONNECTION` rather than adding a driver setting of
its own (spec §8, §13).

## What runs by default

| Database driver | Implementation | What you get |
|---|---|---|
| `sqlite` (the default) | `Fts5SearchIndex` | Real BM25 ranking, via SQLite's FTS5 extension, which is compiled into the base image's PHP build. No extra service, no extra configuration. |
| `pgsql` or `mysql` | `LikeSearchIndex` | A correct but unranked `LIKE`/`ILIKE` scan of the `search_documents` projection table — a full scan of every document on every query, with no relevance ordering. |

This split exists because the obvious alternative — Laravel Scout on its
database driver — would put nearly every self-hosted install on the `LIKE`
path: Scout's value is swapping in a hosted engine *later*, but its database
driver is what almost everyone would actually run, and on SQLite that is
the worst version of search doccum could ship as a default. Building
`Fts5SearchIndex` directly instead means the configuration nearly everyone
uses (SQLite) gets real ranking, and nothing about setting it up differs
from any other doccum install — it activates automatically the moment
`DB_CONNECTION=sqlite`, which it is unless you changed it.

If you have moved to PostgreSQL or MySQL for the database (see the
[configuration reference](configuration-reference.md#database)), search
keeps working immediately — just without ranking. Nothing breaks and
nothing needs reconfiguring; it's a safety net, described in spec §8 as
exactly that.

## Everything is one index

Directories, files, and property values are three different kinds of thing,
but they are searched through one projection table, `search_documents`,
built by `App\Services\SearchIndexer` and kept current by model observers
plus the `ExtractText` job. You do not maintain this table yourself: moving,
renaming, editing a property, or finishing text extraction all trigger a
reindex through `ReindexSearchDocument` automatically. There is nothing to
run after a restore, either, beyond the database itself — the projection
table is part of it.

## Permission filtering happens inside the query

`App\Services\Search::for()` is the only way anything in doccum searches. It
resolves the current viewer's reachable directories once per call and
passes that list into whichever `SearchIndex` implementation is active,
which is required to filter on it *inside* its own query — never by running
an unfiltered query and trimming the result set afterward. A search result
can never reference a directory, file, or property the searching user could
not otherwise see; there is no separate flag or mode that would make search
bypass this.

## Upgrading to a dedicated engine

Spec §13 reserves a `search` compose profile for a dedicated engine —
Typesense or Meilisearch — but which one ships is explicitly undecided as
of this writing (spec §16, "Deferred": *"Search engine selection (Typesense
vs Meilisearch) once real data exists"*). There is no dedicated-engine
implementation in this codebase yet, and this page will not claim one that
isn't there.

What is already true, and is the reason this upgrade is expected to be
low-friction when it happens: the `SearchIndex` seam is the *only* thing
`Services\Search` talks to, and the permission-filtering contract above
applies to any future implementation exactly as it applies to the two that
ship today. Adding a hosted engine means writing one more class behind the
seam and registering it in `DoccumServiceProvider` — not a new environment
variable, not a change to how `Services\Search::for()` is called from
Livewire components, and not a change to what a search result is allowed to
contain. Until that class exists, the SQLite/`LIKE` split above is the
whole story, and it is a genuinely usable one: BM25 ranking on the database
the large majority of installs run.
