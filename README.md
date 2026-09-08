# Travel Engine — multi-supplier comparison and booking for WordPress

A supplier-agnostic travel comparison and booking engine, built as a WordPress
plugin so it drops into an existing managed WordPress site without replacing
the theme.

---

## Two modes, one engine

The same search, matching and comparison serve both business models. The mode
is a setting, because the two need completely different supplier agreements:

| | **Compare & refer out** (default) | **Sell directly** |
|---|---|---|
| The customer books | on the supplier's own site | here |
| You take payment | no | yes |
| Revenue | affiliate commission | margin on the net rate |
| Supplier agreement | affiliate programme | reseller / merchant API |
| Commission added to displayed price | **never** | yes, as a visible line |
| Checkout, revalidation, confirmation email | not used | used |

**Referral mode adds no commission to the displayed price.** Marking up a figure
shown under a named supplier's row is not a markup — it is a wrong price
attributed to somebody else. Revenue in that model comes from the affiliate
click, which is why every outbound click is logged with the price the visitor
actually saw.

A merchant API returns a **net rate for you to resell** and publishes no
customer-facing page to link to. Booking.com Demand and Expedia Rapid are both
merchant APIs. Connect one in referral mode and the engine says so on the
results page — those rates are shown for comparison but carry no link, because
there is nowhere to send anyone. That notice is how a site discovers it has
connected the wrong kind of API for the model it is running.

---

## What it does

| Step | What happens |
|---|---|
| **Search** | The request fans out to every connected supplier in parallel-safe isolation. A supplier that errors or times out is dropped from that search and reported, never allowed to blank the page. Results are cached briefly; nothing else is. |
| **Normalise** | Each adapter maps its supplier's response into one `GTC_Offer` shape, so the comparison, the checkout and the booking record never learn a supplier's field names. |
| **Match** | Equivalent products from different suppliers are clustered into one card with a per-supplier price comparison, instead of the same hotel appearing four times. |
| **Refer** *(referral mode)* | Each row links to that supplier's own booking page through a logged redirect, so clicks can be reconciled against a commission statement. |
| **Look** *(merchant mode)* | When the customer selects an offer, the engine re-prices it live against the supplier before the checkout page renders. Never cached. |
| **Book** *(merchant mode)* | Payment is **authorised**, the supplier reservation is placed, and only then is the payment **captured**. Every transition is written to the database before the next call is made. |
| **Confirm** *(merchant mode)* | Booking and supplier references, the full itemised price, and a confirmation email. |

## Categories

Categories are modules. The engine, cache, matcher, checkout and booking store
are all category-agnostic, so a category goes live as soon as an adapter
declares it:

hotels · flights · vacation rentals · car rentals · airport transfers ·
tours, attractions & activities · travel insurance · cruises

The admin Overview screen shows which categories have a live supplier behind
them and which do not.

---

## The price is never a single number

The brief a comparison site lives or dies by is *show the customer what they
will actually pay*. So `GTC_Price` carries every component separately:

- the net rate,
- each tax and fee, each marked payable **now** or **at the property**,
- your commission, added as its own visible line.

Two consequences fall out of that, both deliberate:

- **The itemised lines always add up to the charged total.** The self-check
  asserts it.
- **Cards are ranked on grand total, not on what we charge.** Otherwise a
  supplier that defers its taxes to the property wins the card while costing
  the customer more.

---

## The supplier price comparison

Clustering the same hotel is only half of it. Each card then splits its rates
into **comparable classes** and puts one row per supplier inside each, so the
customer is looking at supplier A against supplier B *on the same product*.

The class key is **room grade + board basis + cancellation terms** — never the
raw room name, because suppliers name the same room differently ("Standard
Double Room" against "Double Room"). Grade is extracted from the name against a
fixed vocabulary; an unqualified name resolves to *standard*, since that is what
a supplier lists when it does not qualify the grade.

Grade has to be in the key. Leave it out and a standard room and a superior room
at the same board basis land in one class, and the engine reports the gap
between two different rooms as a saving — a real number that means nothing. An
unrecognised grade splits into its own class: that costs a comparison, but it
never invents one.

Classes only one supplier quotes are not a comparison, so they fold away behind
a toggle rather than sitting in the comparison as if something had been
compared. A single-supplier card carries no saving badge at all.

The headline **Save $X** on a card is the largest gap inside a single
multi-supplier class. Note that this is *not* the same as the gap between each
supplier's cheapest rate, and neither figure bounds the other — cheapest-vs-
cheapest can pair a non-refundable rate against a refundable one. Only the
like-for-like figure is advertised.

---

## Duplicate matching

Two suppliers describe the same hotel under two names, two property ids and
two slightly different coordinates. `GTC_Dedupe` clusters them.

It is deliberately conservative, because a **false merge hides a real property
and can send a customer to the wrong hotel** — much worse than a false split.
A cross-supplier pair merges only if:

1. both suppliers publish the same id in a shared namespace (GIATA or
   equivalent) — authoritative, matched immediately; **or**
2. they are within the distance gate (default 150 m) **and** clear the name
   similarity gate (default 0.82).

If they share an id namespace and *disagree* within it, that is a positive
statement that they are different properties, and no amount of name or
distance similarity will merge them.

Name similarity combines token-set containment (robust to word order and to one
supplier appending a district or brand) with a character-level ratio (robust to
spelling and transliteration), taking the larger — each covers the other's blind
spot. Comparison is blocked by geo cell, so large result sets do not go
quadratic.

Both gates are adjustable in Settings.

---

## Suppliers

Adding a supplier means writing one class against `GTC_Provider` and
registering it. Nothing else changes.

```php
add_action( 'gtc_register_providers', function ( $registry ) {
    $registry->add( new My_Supplier_Adapter() );
} );
```

### Shipped adapters

| Adapter | Status |
|---|---|
| `sandbox_alpha`, `sandbox_beta` | Fully working. No network calls. Two overlapping views of the same fictional properties, so the whole platform can be demonstrated and regression-tested before any contract is signed. |
| `booking_demand` — Booking.com Demand API | Written against the published Search/Look/Book model and wired in. **Not yet verified against a live endpoint.** |
| `expedia_rapid` — Expedia Rapid | Written against Rapid's published shape and wired in. **Not yet verified against a live endpoint.** |

Both real adapters carry that caveat at the top of the file, and it is not
boilerplate. These APIs are issued under partner agreements; the exact paths,
field names and error envelopes are fixed by the version of the specification
the supplier gives you on approval. Everything version-specific lives in the
`map_*()` and `endpoint()` methods so it can be corrected in one place.

**Do not enable either in production until a test booking has been placed and
cancelled end to end in that supplier's test environment.**

Expedia Rapid additionally prices a *list of property ids*, not a free-text
destination. Turning "Lisbon" into a property list is a mapping the site owns,
built from Rapid's content feed — supply it via the `gtc_rapid_property_ids`
and `gtc_rapid_property_content` filters. Until then that adapter returns
nothing rather than guessing an id.

---

## Payments (merchant mode only)

Gateways implement `GTC_Gateway`. The engine authorises before calling the
supplier and captures after the supplier confirms, so the two expensive
failures both have a defined resolution:

- **Supplier fails after authorisation** → the hold is released, the booking is
  marked `supplier_failed`, and the customer is told they were not charged. If
  the release itself fails, `gtc_booking_needs_attention` fires and operations
  is emailed.
- **Capture fails after the supplier confirmed** → the reservation is *not*
  cancelled out from under the customer. It is flagged for a human.

A sandbox gateway ships so the whole path can be exercised without a processor:

| Card | Behaviour |
|---|---|
| `4111 1111 1111 1111` | authorises and captures |
| `4000 0000 0000 0002` | declines at authorisation |
| `4000 0000 0000 0069` | authorises, then fails at capture |

---

## What stops a wrong charge

- The browser holds only a search hash and an offer key. Rate tokens, supplier
  ids and prices are recovered server-side. A tampered request can change
  *which* offer is selected; it cannot change its price or its supplier.
- Checkout sends back the exact total the customer saw. If it no longer matches
  the live total, the charge is refused and the customer is shown the change.
- A revalidation older than 10 minutes is re-run against the supplier before
  any money moves.
- A price rise requires explicit re-acceptance in the UI before the pay button
  re-enables.
- Every supplier Book carries an idempotency key, and a confirmed booking that
  is submitted twice returns the original reservation.
- Bookings live in their own table, not as posts, so nothing that walks posts
  can expose them.
- The supplier log redacts credentials and traveller PII before writing.

---

## Installation

1. Copy `gtc-travel-engine/` into `wp-content/plugins/` and activate.
   Activation creates the tables and three pages (search, checkout,
   confirmation) and wires them up.
2. **Travel Engine → Suppliers** — enable the sandbox suppliers to see it work,
   or enter real credentials once you have them.
3. **Travel Engine → Settings** — currency, commission, cache TTL, matching
   thresholds, gateway, pages.

Shortcodes, if you would rather place them yourself:
`[gtc_search]` · `[gtc_checkout]` · `[gtc_confirmation]`

Requires WordPress 6.4+ and PHP 8.0+.

---

## Self-check

```
php wp-cli.phar eval-file tests/engine-check.php --path=site
```

51 assertions covering the fan-out, matching, comparison, pricing,
revalidation, booking, referral and audit paths.

Every duplicate-matching assertion is **paired** — one case that must merge and
one that must not. A matcher that merged everything would pass the positives
alone, so the negative controls are what give the positives their meaning:

- reversed word order **merges**; two different hotels 60 m apart **do not**
- names sharing almost nothing **merge** on a common GIATA id — and the check
  confirms their name similarity alone (0.31) would not have merged them
- adjacent towers whose names are effectively identical (similarity 1.00) **do
  not** merge, because the suppliers publish different GIATA ids

And on the comparison: a superior room is **never** placed in the same class as
a standard one, while differently worded base rooms **still** meet — the pair
that proves the grade axis restricts without disabling. A single-supplier card
is checked to advertise no saving.

On referral mode, the mode switch itself is the thing under test, so it is
checked in both positions: commission is absent from a referral price **and**
present on the same search in merchant mode. A check that only looked at the
referral price would pass just as happily against a markup setting left at zero.
The outbound deeplink is asserted to reach the server but never the browser
payload, and the click log is asserted to store a hash rather than an IP.

Likewise on the money path: a valid card books; a total the customer never
accepted is refused and leaves no payment and no reservation; a declined card
leaves no supplier reservation; a double submit returns the same reservation.

---

## Extension points

| Filter / action | Use |
|---|---|
| `gtc_register_providers` | register supplier adapters |
| `gtc_gateways` | register payment gateways |
| `gtc_categories` | add or relabel categories |
| `gtc_markup_rule` | per-supplier, per-category or per-destination commission |
| `gtc_fx_rate` | supply an exchange rate (there is no built-in rate source — see below) |
| `gtc_provider_credentials` | serve keys from wp-config or a secrets manager instead of the database |
| `gtc_outbound_url` | decorate the referral link — affiliate sub-id, campaign tag |
| `gtc_rate_limit_per_minute` | tune the public endpoint rate limit |
| `gtc_booking_confirmed` | push to a CRM, ledger or supplier reconciliation |
| `gtc_booking_needs_attention` | escalate a booking that took money and needs a human |

### On currency

There is deliberately **no built-in FX source**. Shipping scraped or hard-coded
rates would misprice real bookings. Wire a rate provider to `gtc_fx_rate` — the
ECB feed, your treasury rate, or the supplier's own multi-currency response.
Until one exists, an offer quoted in another currency is hidden with a notice
rather than converted at a guessed rate.
