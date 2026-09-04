# Stage: ARTICLE

Write the finished page. The deliverable is the HTML that goes between `<body>`
and `</body>` — the editor pastes it straight into the CMS.

## Non-negotiables

- Body copy, headings, meta and anchors: `{{ language }}`.
- Follow the structure you were given. If you must deviate, say what you changed
  and why in the `NOTES` section — never inside the HTML.
- Every numeric claim traces to supplied data. Anything else is `нет данных` or
  is simply not stated. A sentence that needs a number you do not have gets
  rewritten to not need it — it does not get an invented one.
- Any field an editor must fill by hand: `<strong>добавьте данные</strong>`.
- Respect the compliance floor: age limit, no guaranteed-win language, no
  unverified licence claims.
- Do not write a generic introduction that restates the H1. Open on the thing
  the reader came for.

## HTML rules

The markup is clean and structural. Nothing about presentation belongs in it.

- Allowed tags, and only these: `h1` `h2` `h3` `h4` `p` `ul` `ol` `li`
  `table` `thead` `tbody` `tr` `th` `td` `strong` `em` `a` `blockquote` `br` `hr`.
- **No attributes at all**, with two exceptions: `href` on `<a>`, and
  `colspan` / `rowspan` on table cells. No `class`, no `style`, no `id`,
  no `target`, no `rel`, no `data-*`, no inline CSS.
- No `<!DOCTYPE>`, no `<html>`, `<head>`, `<body>`, `<script>`, `<style>`,
  `<div>`, `<span>`, `<section>`, `<figure>`, `<img>`.
- Exactly one `<h1>`. Subheadings descend without skipping a level.
- Comparison data goes in a real `<table>`, not a list pretending to be one.
- Emphasis carries meaning: `<strong>` for a fact that matters, `<em>` for a
  term. Neither is decoration.
- Write the HTML plainly, one block element per line. Do not wrap the document
  in a code fence.

## Output format

Three sections, in this order, with the markers exactly as shown.

```
===META===
title: <title tag, market language>
description: <meta description, market language>
url: <proposed URL slug>
===HTML===
<h1>…</h1>
<p>…</p>
…
===NOTES===
- Что заполнить руками:
- На чём основана структура:
- Чего избежал из-за отсутствия данных:
```

`NOTES` is in Russian and is not published — it is the editor's handover.
Nothing outside these three sections: no preamble, no closing remark.
