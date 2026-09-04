# Stage: RESEARCH

Produce the research report that everything downstream depends on. This report
is also a deliverable the team reads directly.

Use the web search and web fetch tools to look at the **live** SERP for
`{{ target_query }}` as it appears in `{{ geo }}` in `{{ language }}`. Read the
strong competitors' actual pages — Ahrefs gives addresses and metrics, but not
the text on the page.

## What to do

1. **Intent.** What does a player behind `{{ target_query }}` actually want, and
   what page type does Google currently reward for it? Base this on the live
   SERP you just read, not on assumption.

2. **Competitors.** Take the real SERP. Filter out the noise: incidental pages
   that happen to rank, obvious PBNs, and giant domains ranking on unrelated
   authority rather than on-topic relevance. For each competitor that survives,
   record its URL, page type, heading skeleton, which brands it shows, and
   anything structurally distinctive.

3. **Keywords.** Organise the supplied keywords into primary / secondary / LSI.
   Keep every supplied volume figure attached to its keyword, verbatim.
   Do not add keywords with invented volumes — you may note an additional
   keyword you observed, but its volume is `нет данных`.

4. **Questions and entities.** Player questions visible in the SERP, plus the
   entities that matter in this market: brands, regulators, payment methods,
   game providers.

5. **Content gap.** Which topics or keywords do competitors cover that the
   supplied data or `{{ domain }}` does not? Each substantial gap normally
   becomes its own block of the future page.

6. **Reality check.** Compare `{{ domain }}` against the top of the SERP on
   authority. Say honestly whether content alone can compete here, or whether
   links have to come first. If DR figures were not supplied, say `нет данных`
   and base the judgement on what you can observe, stating that limitation.

## Output format

Markdown. Working data (keywords, headings, questions, brands) in
`{{ language }}`; every explanation, conclusion and warning in **Russian**.

```
## 1. Интент
## 2. Конкуренты в выдаче
## 3. Семантика
### Primary
### Secondary
### LSI
## 4. Вопросы игроков и сущности
## 5. Content gap
## 6. Реалити-чек по авторитету
## 7. Выводы для структуры страницы
```

In section 2, give a table: `URL | Тип страницы | Ключевые H2 | Бренды | Замечание`.
In section 3, give a table: `Ключ | Частотность | Группа`, using `нет данных`
wherever the human supplied no figure.
