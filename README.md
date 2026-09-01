# Property Search

A local tool for house hunting on [utahrealestate.com](https://www.utahrealestate.com).
It scrapes listings into a local MySQL database, filters them on criteria their own
search cannot express, then walks you through the survivors one at a time so you can keep
the good ones and cross the rest off with a reason you will still understand in a month.

Built to run on one laptop. There is no login, no users table, and no multi-tenancy.

## Why this exists

Their public search rounds off exactly the things that matter most:

| What you want | What their search offers | What this app does |
| --- | --- | --- |
| At least 3,500 sq ft | 3,000+ or 4,000+, nothing between | Asks for 3,000+, then filters to the exact figure from each listing page |
| At least 0.25 acres | Coarse buckets | Reads the stated lot size to two decimals |
| No HOA | No filter at all | Reads the monthly dues off each listing and excludes anything above your cap |
| Finished, or finishing before I move | No filter | Reads their "To Be Built" / "Under Construction" badge and hunts the description for a completion date |

On a real Grantsville search this cut 65 results down to 8 that genuinely qualified. The
other 57 were too small, carried an HOA, or were not going to be standing in time.

## Requirements

- PHP 8.3+ with the `pdo_mysql` and `pdo_sqlite` extensions
- MySQL 8+ (Herd bundles one)
- Composer
- Node 20+

## Setup

```bash
composer install
npm install
cp .env.example .env        # skip if .env already exists
php artisan key:generate
mysql -u root -e 'create database if not exists property_search'
php artisan migrate
php artisan db:seed          # creates the starting search profile
npm run build
```

If you use [Laravel Herd](https://herd.laravel.com), the app is already served at
`http://property-search.test`. Otherwise:

```bash
php artisan serve
```

### A note on the database

Everything lives in MySQL, in a database called `property_search`, configured through the
usual `DB_*` entries in `.env`. Herd rewrites that block when it manages the site, which is
worth knowing, because being pointed at a schema that exists but is empty looks exactly
like every scraped listing having disappeared.

That mistake is impossible to spot from the screen, so the app refuses to guess. If the
configured database holds no listings and no search profile while
`database/database.sqlite` still holds listings, every page fails with an error naming both
databases and saying nothing has been deleted, rather than quietly showing an empty queue.
Correct `DB_DATABASE` and reload. Console commands skip the check, since `migrate` and
`db:seed` legitimately start from an empty schema.

The test suite is the other exception. It runs against an in-memory SQLite database so it
needs no server and can never touch your real data, which is why the SQLite connection in
`config/database.php` reads its own `DB_DATABASE_PATH` rather than sharing `DB_DATABASE`.

The pre-MySQL data is still sitting in `database/database.sqlite`, and that check is the
only thing that reads it. Keep it: it is both a backup of everything from before the move
and the reference that tells a misconfigured `.env` apart from real data loss.

Backing up your work is now `mysqldump property_search`.

## Getting listings

Run a scrape from the terminal:

```bash
php artisan listings:scrape
```

Or press **Scrape now** in the app. That queues a job, so a queue worker needs to be
running for it to start:

```bash
php artisan queue:work
```

`composer run dev` starts the Vite dev server, a queue worker, and log tailing together,
which is the easiest way to work on the app.

A run does three passes: it pages through the search results to discover listings, fetches
each listing's page for the exact figures, then puts anything new on the map. Detail pages
are re-fetched only when they are older than `URE_DETAIL_TTL_HOURS`. To force a full
refresh after changing a parser:

```bash
php artisan listings:scrape --fresh-details
```

Requests are spaced out by `URE_DELAY_MS` (1.2s by default). A first run over one city
takes a couple of minutes; later runs are faster because most detail pages are still
fresh.

### Putting listings on the map

Their listing pages do not publish coordinates, so addresses are resolved separately
against [Nominatim](https://nominatim.openstreetmap.org). This happens automatically at the
end of a scrape, and can be run on its own:

```bash
php artisan listings:geocode
```

Nominatim is free and allows one request a second, which the geocoder enforces itself. If
it starts refusing requests anyway, the command stops early and says so rather than
recording every remaining address as unknown; wait a while and run it again. Nothing is
lost, because coordinates are only ever written on success.

Three outcomes are possible per listing, and the review screen shows which one you got:

| Result | What the map shows |
| --- | --- |
| Exact house match | A pin on the house |
| Street match only | The same map, labelled "Street only — exact house not mapped" |
| No match | No map, just a Google Maps lookup link |

Brand-new subdivisions are the usual cause of the last two: OpenStreetMap often has no
data for a street that was laid down months ago. On a real Grantsville run, 45 of 65
listings matched exactly, 7 to the street, and 13 not at all.

## Using it

**Overview** shows how many listings are waiting, your shortlist, recent price drops, and
a "So close" section for listings that fail on exactly one criterion, which is a good hint
that a criterion is slightly too strict.

**Review** is the main screen. It shows one listing at a time, cheapest first, with the
full photo gallery, every spec, the description, and the reasons it passed or failed your
criteria. A link next to the address opens the original listing on UtahRealEstate.com, and
the map in the sidebar opens the address in Google Maps when clicked. Decide with the mouse
or the keyboard:

| Key | Action |
| --- | --- |
| `F` | Keep as favorite |
| `M` | Mark as a maybe |
| `X` | Cross off — opens the reason panel |
| `S` | Skip to the next one |
| `P` | Back to the previous one |
| `←` `→` | Previous / next photo |
| `Enter` | Full-screen photos |
| `Esc` | Close the overlay |
| `O` | Open the listing on utahrealestate.com |

Crossing a listing off requires a reason: pick a preset, type your own, or both. The
reason is then shown wherever that listing appears again, so a house ruled out in August
can still explain itself in October. Changes are recorded as a history on the listing, and
**Undo** steps back through it one change at a time, restoring the reason that was in force
before. Undoing removes that step from the history, so repeated undo keeps walking
backwards rather than piling up entries.

Decisions are yours and scraping never touches them. Re-running a scrape refreshes prices,
status and specs while leaving your decisions, reasons and notes alone.

**All listings** is the full archive with filters, including the listings that failed your
criteria and the ones that have gone off market.

**Criteria** is where the exact numbers live. Saving re-checks every stored listing
immediately, so tightening or relaxing a rule reshuffles the review queue without
re-scraping. Run a scrape afterwards to pull in anything new that a looser rule now
covers.

## How the scraping works

Their search is stateful, which is worth knowing before changing anything:

1. `GET /search/map.search/type/1` starts a session.
2. `POST /search/chained.update` with `all=1` pushes the whole criteria set into that
   session. The city has to go through as `param=city` with `tx=true` so their server
   translates the name into its internal id, and the response echoes back `city` as a
   number to confirm it stuck.
3. `POST /search/map.inline.results/pg/{n}/...` returns 50 cards a page, reading criteria
   from the session.

Posting criteria directly at the results endpoint returns HTTP 200 with an unfiltered
statewide list. That is the dangerous failure mode: it looks like a successful scrape
while filling the database with the wrong houses. `UreClient` therefore treats a missing
city in the `chained.update` response as a hard error, and `tests/Feature/ScrapeProtocolTest.php`
locks the whole sequence down.

At the time of writing their `robots.txt` disallows only `/*pdf*` and `/auth/login*`,
neither of which this app touches. Requests are deliberately slow and sequential; please
keep them that way, and re-check that file before pointing this at anything new.

### Where the numbers come from

- **Square footage and per-level breakdown** — the `#prop_size` list.
- **Lot size** — the "Lot Size: n Acres" line, falling back to a square-foot figure.
- **HOA dues and property tax** — their inline mortgage-calculator config, which carries
  clean numbers. Its `beds` and `baths` keys are transposed, so those two are read from
  the visible overview instead.
- **Construction status** — the `To Be Built` / `Under Construction` badge on the photo,
  which is absent once a home is finished.
- **Completion date** — best-effort parsing of the description ("will complete by the end
  of March", "ready in 30 days"). It is a guess and is labelled as one in the UI. A
  listing badged "To Be Built" is never given an invented date, because ground has not
  broken.

## Layout

```
app/
  Console/Commands/ScrapeListingsCommand.php   the listings:scrape command
  Console/Commands/GeocodeListingsCommand.php  the listings:geocode command
  Enums/                                       Decision, RejectionReason
  Http/Controllers/                            dashboard, review, listings, criteria, scrape
  Jobs/RunScrapeJob.php                        queued scrape for the "Scrape now" button
  Models/                                      Listing, ListingPhoto, SearchProfile, ...
  Services/
    CriteriaEvaluator.php                      the exact filtering, with readable reasons
    DecisionRecorder.php                       decisions plus their audit trail
    Geocoder.php                               addresses to coordinates, politely
    ListingIngestor.php                        upserts scraped data, never touches decisions
    ListingScraper.php                         orchestrates a run
    Ure/
      UreClient.php                            their stateful search protocol
      SearchResultParser.php                   result cards
      ListingDetailParser.php                  the listing page
      CompletionEstimator.php                  finished, or finishing when?
  Support/StaticMap.php                        which map tiles cover a point
  Support/DatabaseGuard.php                    catches being pointed at an empty database
tests/
  Fixtures/                                    real saved pages, so parser breakage is caught
```

## Tests

```bash
php artisan test
```

The parser tests run against real saved pages from their site, so if they change their
markup the tests fail loudly instead of the app quietly storing nulls and dropping
listings out of your queue. Refresh a fixture by saving a current page over the file in
`tests/Fixtures/`.

## Configuration

Scraper settings live in `config/ure.php` and geocoding in `config/geocoding.php`. Both can
be overridden in `.env`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `URE_DELAY_MS` | `1200` | Pause between requests |
| `URE_TIMEOUT` | `30` | Per-request timeout in seconds |
| `URE_MAX_DETAILS_PER_RUN` | `400` | Cap on detail pages fetched in one run |
| `URE_DETAIL_TTL_HOURS` | `12` | How long a fetched detail page stays fresh |
| `GEOCODING_DELAY_MS` | `1100` | Pause between address lookups |
| `GEOCODING_TIMEOUT` | `15` | Per-lookup timeout in seconds |
| `GEOCODING_USER_AGENT` | `PropertySearch/1.0 ...` | Identifies this app to Nominatim, which requires it |

## Notes and limits

- One city per search is their limit, so a profile with several cities runs one search
  each. Add neighbouring towns on the Criteria page.
- Completion dates for unfinished homes are inferred from marketing copy. Treat them as a
  prompt to ask the builder, not as fact.
- Listings that disappear from search results are marked off market rather than deleted,
  so a house you liked is still there with its history if it comes back.
- Map previews are stitched from OpenStreetMap tile images. Google refuses to be embedded
  without an API key, and OpenStreetMap's own embeddable map needs WebGL, which is not
  guaranteed to be available; plain images always draw. Clicking one still opens Google
  Maps, which is the better map to browse around in.
- The data belongs to their MLS. This is a personal research tool; do not republish what
  it collects.
