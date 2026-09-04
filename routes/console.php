<?php

use Illuminate\Support\Facades\Schedule;

// Прогоны нужны только чтобы забрать результат по ссылке — дальше это мусор.
Schedule::command('textgen:prune')->dailyAt('04:00');
