<?php

declare(strict_types=1);

namespace soockee\phpCalendar\Views;

use soockee\phpCalendar\Calendar;
use soockee\phpCalendar\Config;
use soockee\phpCalendar\Event;
use Carbon\CarbonInterface;
use DateTimeInterface;

abstract class View
{
    public function __construct(
        protected Config $config,
        protected Calendar $calendar,
    ) {
    }

    /**
     * Find an event from the internal pool.
     *
     * @return array<Event> either an array of events or false
     */
    abstract protected function findEvents(CarbonInterface $start, CarbonInterface $end): array;

    /**
     * Returns the calendar as a month view.
     *
     * @param array{color?: string, startDate?: (string|DateTimeInterface)} $options
     */
    abstract public function render(array $options): string;
}
