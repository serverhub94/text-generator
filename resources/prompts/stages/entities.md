# Stage: ENTITIES (V7 mono, step 1)

Map the entity graph for `{{ target_query }}` in `{{ geo }}`.

A mono page ranks on topical completeness: Google must see that the page covers
the entities a real authority on this subject would cover.

Identify:

- **The core entity** — the brand, product or concept the page is about.
- **Mandatory co-entities** — what must appear for the page to read as complete:
  regulator(s) for `{{ geo }}`, payment methods actually used in that market,
  game providers, device/platform terms, currency.
- **Differentiating entities** — what strong competitors mention that weaker
  ones miss.
- **Entities to avoid** — brands, claims or jurisdictions that would create
  legal or compliance exposure in `{{ geo }}`.

Verify against live pages where you can. Anything you could not verify goes in a
separate list marked `не подтверждено`.

## Output format

Entity names in `{{ language }}`; all commentary in **Russian**.

```
## Ядро
## Обязательные сущности
| Сущность | Тип | Почему обязательна |
## Дифференцирующие сущности
## Чего избегать
## Не подтверждено
```
