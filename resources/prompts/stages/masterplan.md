# Stage: MASTER PLAN (V7 mono, step 3)

Build the master plan for a single long-form mono page on `{{ target_query }}`.

A mono page is one self-contained authority page — not a hub with thin children.
It must answer the whole intent in one place, in commercial tone, without first
person, with the CTA woven into the body rather than bolted on at the end.

Using the entity map and the research:

1. Decide the narrative spine — the order a reader actually needs, not the order
   competitors happen to use.
2. Assign every mandatory entity to exactly one section. An entity that appears
   nowhere is a gap; an entity in three sections is dilution.
3. For each section: heading in `{{ language }}`, purpose, entities covered,
   keywords placed, target length, and the source that justifies it.
4. Place the CTAs — where and in what form, given that the tone stays editorial.
5. Note where the page will differ structurally from every competitor read, and
   why that difference serves the reader.

## Output format

Headings in `{{ language }}`, all reasoning in **Russian**.

```
## Спайн страницы
## Карта секций
| # | H2/H3 ({{ language }}) | Назначение | Сущности | Ключи | Объём | Источник |
## Размещение CTA
## Чем отличаемся от конкурентов
## Риски и что проверить руками
```
