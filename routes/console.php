<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('einvoicing:refresh-statuses')->everyThirtyMinutes()->withoutOverlapping();
