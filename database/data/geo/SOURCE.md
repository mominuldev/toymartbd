# Bangladesh geography dataset: source and provenance (P3-DAT-03)

Seeded by `database/seeders/GeographySeeder.php`. Schema: DATABASE-DESIGN §3 (Customer · geography).

## Files

| File | Rows | Content |
|---|---|---|
| `divisions.json` | 8 | `id`, `name`, `name_bn`, `pcode` |
| `districts.json` | 64 | `id`, `division_id`, `name`, `name_bn`, `pcode` |
| `upazilas.json` | 495 | `id`, `district_id`, `name`, `name_bn`, `pcode` |
| `dhaka-zones.json` | — | upazila ids for `is_inside_dhaka_metro` / `is_dhaka_subcity`. **Provisional, see below** |
| `pcode-reconciliation.json` | 115 | every row whose name differs from OCHA's, with the match method |

## Primary source: names, Bangla names, ids

- **devfaysal/laravel-bangladesh-geocode** (the source DATABASE-DESIGN §3 names)
- Repo: <https://github.com/devfaysal/laravel-bangladesh-geocode>
- Commit **`29259425d39468d8cb434e647af707541762dd60`** (2026-11-01, merge of PR #19)
- Licence: **MIT** (`LICENSE.md`, © Faysal Ahamed)
- Files used: `src/Seeders/DivisionSeeder.php`, `DistrictSeeder.php` and `UpazilaSeeder.php`, fetched from
  `https://raw.githubusercontent.com/devfaysal/laravel-bangladesh-geocode/<commit>/<path>`.
  SHA-256: Division `16738768…cc`, District `ea7a9a8e…9222`, Upazila `25c61419…73cb`.
- The PHP arrays were **parsed as text, not executed**, and transcribed to JSON unchanged:
  `bn_name` was renamed to `name_bn`, and `url`, `lat` and `lon` were dropped. The composer package is **not**
  installed; we own the tables and models.
- `id`s are the dataset's own, and they are the stable upsert key. The ids are shared with the
  upstream `nuhil/bangladesh-geocode` for rows 1–491. Rows 492–495 are newer upazilas (Eidgaon, Madhyanagar,
  Dasar, Shayestaganj).

## Reconciliation source: official P-codes

- **OCHA/BBS COD-AB "Bangladesh – Subnational Administrative Boundaries"**,
  <https://data.humdata.org/dataset/cod-ab-bgd> (dataset `401d3fae-4262-48c9-891f-461fd776d49b`)
- `bgd_admin_boundaries.xlsx`, fetched 2026-11-11 (SHA-256 `a58bc267…7be45207`). Sheets `bgd_admin1..3`, version
  `v03`. Licence: **CC BY-IGO**. Contains no Bangla names.
- OCHA ADM3 has 507 units: **495 upazilas + 12 City Corporations**. The city corporations are not in
  the dataset and are not seeded.
- **Result: all 8 / 64 / 495 rows map 1:1 to a distinct OCHA P-code.** There are no orphans on either side,
  City Corporations excepted.
- Method: divisions and districts were matched by name within their parent, after normalisation and a hand alias
  table (below). Upazilas were matched by exact normalised name within their district (391). The rest were
  paired within the same district by mutual-best string similarity, and each pair was eyeballed (99 `fuzzy`).
  Five were set by hand (`manual`), each with its reason in `pcode-reconciliation.json`:
  - Comilla Sadar → Adarsha Sadar
  - Sadarsouth → Sadar Dakkhin
  - Matlab South → Matlab Dakkhin, and Matlab North → Matlab Uttar. The fuzzy matcher had crossed these; the
    Bangla names দক্ষিণ / উত্তর settled them.
  - Zianagar → Indurkani
- This was a **one-time offline reconciliation, documented here**. It is *not* a runtime name join. Courier zones
  are a separate namespace and are never matched by name (CLAUDE.md rule 32, `courier_locations`).

## Spellings: kept as the dataset has them, not "fixed"

| Level | Dataset (`name`) | Current official / OCHA |
|---|---|---|
| division | Chattagram | Chattogram |
| division | Barisal | Barishal |
| district | Comilla | Cumilla |
| district | Barisal | Barishal |
| district | Jhalakathi | Jhalokati |
| district | Chapainawabganj | Chapainababganj |
| district | Netrokona | Netrakona |
| district | Coxsbazar | Cox's Bazar |
| district | Chattogram, Jashore, Bogura | already current. Older spellings are Chittagong, Jessore, Bogra |
| upazila | Jessore Sadar, Bogra Sadar | Jashore Sadar, Bogura Sadar |
| upazila | Zianagar (জিয়ানগর) | **Indurkani**. The upazila was renamed, and the dataset keeps the old name |

Note the dataset's internal inconsistency: the division is "Chattagram" but its district is "Chattogram". The
full list of the 107 upazila variants is in `pcode-reconciliation.json`. A search or autocomplete feature should
match these variants. Address storage keys on `id`.

## Counts and currency

- Divisions **8**, districts **64**, upazilas **495**. Per division: Dhaka 13, Chattogram 11, Rajshahi 8,
  Khulna 10, Barishal 6, Sylhet 4, Rangpur 8, Mymensingh 4.
- 495 matches OCHA COD-AB's upazila count (dataset reviewed 2025-06-01, modified 2026-01-26). **Not verified:**
  whether any upazila was gazetted after that review. There is no machine-readable BBS/LGD list to check against;
  see docs/_research/agent4 §5.5.

## Dhaka delivery zones: PROVISIONAL, owner decision pending

DATABASE-DESIGN §3 puts `is_inside_dhaka_metro` and `is_dhaka_subcity` on upazilas for the three-tier charge (§5.1
`inside_dhaka_metro | dhaka_suburb | outside_dhaka`). The docs do **not** list which units fall in each tier.
What follows is a conservative rule:

- **`is_inside_dhaka_metro`: no upazila.** Dhaka city (Dhaka North and South City Corporations) is **not an
  upazila** in this dataset or in OCHA's. Dhaka district's 5 upazilas are Savar, Dhamrai, Keraniganj,
  Nawabganj and Dohar, which are all outside the city. Flagging any of them "metro" would be a guess. The
  §5.1 resolver therefore needs a rule for a Dhaka-city address, for example district Dhaka with no upazila
  (`addresses.upazila_id` is NULLable). Alternatively, a city-corporation/thana level could be added. **Owner
  decision.**
- **`is_dhaka_subcity`: all five upazilas of Dhaka district** — Savar (365), Dhamrai (366), Keraniganj (367),
  Nawabganj (368), Dohar (369). Savar, Keraniganj, Dohar and Nawabganj are the design's `DhakaOuter` list
  (docs/_design/03-storefront-spec.md A12.4); Dhamrai is not named there but lies between Savar and Manikganj,
  so pricing it at the outside-Dhaka tier would be the odd one out. **Owner to confirm Dhamrai.** (Updated
  2026-11-12, P4-CRT-03; previously only Savar and Keraniganj.)
- **Whole districts as suburb** (Gazipur, Narayanganj, Narsingdi, Munshiganj, Manikganj, per A12.4) are not
  flagged per upazila: they are the `delivery.suburb_district_ids` setting, and a district-level Dhaka address
  (no upazila) is the city, via `delivery.metro_district_ids`. See `App\Domain\Fulfilment\Services\DeliverySettings`.
- Every other upazila therefore falls to `outside_dhaka`, the highest tier. Edit `dhaka-zones.json`, which is
  keyed by id, and re-run the seeder to change this.
- These flags are **our** charge tier only. They say nothing about any courier's zone coverage.
