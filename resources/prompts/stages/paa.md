# Stage: PAA (People Also Ask)

Collect the **real** People-Also-Ask and related-question block for
`{{ target_query }}` on Google in `{{ geo }}`, in `{{ language }}`.

Use web search. Record the questions as they are actually phrased by Google —
do not translate them, do not tidy the grammar, do not invent plausible-sounding
questions to pad the list. If you can only confirm a handful, return that
handful and say how many you confirmed.

For each question, add a one-line note in **Russian** on whether the page should
answer it inline in a section, in an FAQ block, or not at all (and why).

## Output format

```
## PAA-вопросы (подтверждённые)
| Вопрос ({{ language }}) | Где отвечать | Комментарий (рус.) |

## Не подтверждено
(перечислить, если что-то не удалось подтвердить в выдаче)
```
