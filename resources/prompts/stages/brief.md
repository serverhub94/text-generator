# Stage: BRIEF (ТЗ)

Turn the research into a writing brief an editor can hand to a writer without
asking follow-up questions.

Section labels and instructions are in **English**. On-page strings (title, meta,
H1-H3, anchors) are in `{{ language }}`. Any note explaining a decision to the
Russian-speaking team is in **Russian**, in parentheses.

Every structural block you specify must trace to something in the research:
a supplied keyword, a content gap, a competitor section, or a PAA question.
Name that source in the `Source` column. A block you cannot source does not
belong in the brief.

## Output format

```
#### Context parameters
- **GEO:** {{ geo }}
- **Language:** {{ language }}
- **Page goal:** {{ page_goal }}
- **Main topic / focus:** {{ target_query }}
- **Page type:** {{ page_type }}
- **Site type:** {{ site_type }}

#### Target search queries
(the queries the page competes for, one per line, with supplied volume or `нет данных`)

#### Competitors analysed
(table: URL | What they do well | What they miss)

#### Meta
- **Title ({{ language }}):** ... (max 60 chars — state the actual count)
- **Meta description ({{ language }}):** ... (max 155 chars — state the actual count)
- **H1 ({{ language }}):** ...
- **URL slug:** ...

#### Page structure
(table: Level | Heading ({{ language }}) | What goes in it | Source | Target length)
Levels are H2 / H3. "Source" is the research item that justifies the block.

#### Keyword placement
(table: Keyword | Volume | Where it must appear | How many times)
Volume is the supplied figure verbatim, or `нет данных`.

#### Brands / entities table
(table with the real brands from the research; any field with no verified value
is written **добавьте данные** — never filled with a plausible guess)

#### Style requirements
(tone, person, sentence length, what to avoid, CTA policy)

#### Compliance
(age limit for {{ geo }}, regulator, responsible gambling requirements,
claims that must not be made)

#### Open items for the editor
(everything marked **добавьте данные**, collected in one list)
```
