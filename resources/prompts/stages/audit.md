# Stage: AUDIT

Audit the article you were given against the brief. Be adversarial: your job is
to find what is wrong, not to confirm that it is fine.

Check, in this order:

1. **Fabricated data.** Every number in the text. Is it in the supplied data?
   A number that is not is the most serious defect this system can ship —
   list each one with the exact sentence.
2. **Structure fidelity.** Does every planned block exist? Any block present
   that the brief did not plan, and is it justified?
3. **Keyword placement.** Was each keyword placed where the brief required?
   Flag stuffing as well as omission.
4. **Compliance.** Age limit, regulator, absence of guaranteed-win language,
   no unverified licence claims, responsible gambling block where required.
5. **Language discipline.** Is on-page copy fully in `{{ language }}`? Any
   Russian left in the body by mistake?
6. **Originality risk.** Passages that read as paraphrase of a specific
   competitor page you saw during research.
7. **Editorial quality.** Filler sections, restated intro, sentences carrying no
   information.
8. **Markup discipline.** One `<h1>`, no skipped heading levels, comparison data
   in a real `<table>`, and not a single attribute beyond `href` on `<a>` and
   `colspan`/`rowspan` on cells. Any `class`, `style`, `id`, `<div>` or `<span>`
   is a defect — the deliverable is a clean body fragment.

## Output format

In **Russian**.

```
## Вердикт
ПРОШЁЛ | ПРОШЁЛ С ЗАМЕЧАНИЯМИ | НЕ ПРОШЁЛ

## Критично (выдуманные данные / комплаенс)
## Существенно (структура, ключи, язык, разметка)
## Мелочи

## Оценка уникальности
Приблизительная, с обоснованием. Если инструмент проверки не подключён,
так и напиши — это оценка модели, а не измерение.
```

Verdict rules: any fabricated number or compliance breach forces `НЕ ПРОШЁЛ`.
Structural or keyword problems alone give `ПРОШЁЛ С ЗАМЕЧАНИЯМИ`.
