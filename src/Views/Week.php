<?php

declare(strict_types=1);

namespace benhall14\phpCalendar\Views;

use benhall14\phpCalendar\Event;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Carbon\CarbonPeriod;
use DateTimeInterface;

class Week extends View
{
    /**
     * @var list<Event>
     */
    protected array $usedEvents = [];

    /**
     * @var array{color: string, startDate: (string|CarbonInterface), timeInterval: int, endTime: string, startTime: string}
     */
    private array $options = [
        'color' => '',
        'startDate' => '',
        'timeInterval' => 0,
        'startTime' => '',
        'endTime' => '',
    ];

    protected function findEvents(CarbonInterface $start, CarbonInterface $end): array
    {
        $callback = fn(Event $event): bool => $event->start->betweenIncluded($start, $end)
            || $event->end->betweenIncluded($start, $end)
            || $end->betweenIncluded($event->start, $event->end);

        return array_filter($this->calendar->getEvents(), $callback);
    }

    /**
     * Returns the calendar output as a week view.
     *
     * @param array{color?: string, startDate?: (string|DateTimeInterface), timeInterval?: int, endTime?: string, startTime?: string} $options
     */
    public function render(array $options): string
    {
        $this->options = $this->initializeOptions($options);

        $startDate = $this->sanitizeStartDate($this->options['startDate']);
        $carbonPeriod = $startDate->locale($this->config->locale)->toPeriod(7);
        $calendar = [
            '<div class="weekly-calendar-container">',
            '<table class="weekly-calendar calendar ' . $this->options['color'] . ' ' . $this->config->table_classes . '">',
            $this->makeHeader($carbonPeriod),
            '<tbody>',
            $this->renderBlocks($carbonPeriod),
            '</tbody>',
            '</table>',
            '</div>',
        ];

        return implode('', $calendar);
    }

    /**
     * Get an array of time slots.
     *
     * @return list<string>
     */
    protected function getTimes(): array
    {
        $start_time = Carbon::createFromFormat('H:i', $this->options['startTime']);
        $end_time = Carbon::createFromFormat('H:i', $this->options['endTime']);
        if ($start_time->equalTo($end_time)) {
            $end_time->addDay();
        }

        $carbonPeriod = CarbonInterval::minutes($this->options['timeInterval'])
            ->toPeriod($this->options['startTime'], $end_time);

        $times = [];
        foreach ($carbonPeriod->toArray() as $carbon) {
            $times[] = $carbon->format('H:i');
        }

        return array_unique($times);
    }

    protected function sanitizeStartDate(CarbonInterface $startDate): CarbonInterface
    {
        if ($this->config->starting_day !== $startDate->dayOfWeek) {
            if (0 === $this->config->starting_day) {
                $startDate->previous('sunday');
            } elseif (1 == $this->config->starting_day) {
                $startDate->previous('monday');
            }
        }

        return $startDate;
    }

    protected function makeHeader(CarbonPeriod $carbonPeriod): string
    {
        $headerString = '<thead>';

        $headerString .= '<tr class="calendar-header">';

        $headerString .= '<th></th>';

        /* @var Carbon $date */
        foreach ($carbonPeriod->toArray() as $carbon) {
            if ($this->config->dayShouldBeHidden($carbon)) {
                continue;
            }

            $headerString .= '<th class="cal-th cal-th-' . strtolower($carbon->englishDayOfWeek) . '">';
            $headerString .= '<div class="cal-weekview-dow">' . ucfirst($carbon->localeDayOfWeek) . '</div>';
            $headerString .= '<div class="cal-weekview-day">' . $carbon->day . '</div>';
            $headerString .= '<div class="cal-weekview-month">' . ucfirst($carbon->localeMonth) . '</div>';
            $headerString .= '</th>';
        }

        $headerString .= '</tr>';

        return $headerString . '</thead>';
    }


    protected function renderBlocks(CarbonPeriod $carbonPeriod): string
    {
        $today   = Carbon::now();
        $content = '';
        $times   = $this->getTimes();              // e.g. ["10:00", "11:00", …]
        $days    = $carbonPeriod->toArray();         // Each Carbon day for the week

        // $occupied will keep track of rows already occupied by a multi-timeslot event for each day.
        // The structure is: [ 'YYYY-MM-DD' => [rowIndex, rowIndex, ...], ... ]
        $occupied = [];

        // Loop over each timeslot (each row)
        foreach ($times as $rowIndex => $time) {
            $content .= '<tr>';

            // Render the first cell with the time label
            $start_time = $time;
            $end_time   = date('H:i', strtotime($time . ' + ' . $this->options['timeInterval'] . ' minutes'));
            $content   .= '<td class="cal-weekview-time-th"><div>' . $start_time . ' - ' . $end_time . '</div></td>';

            // Loop over each day (each column)
            foreach ($days as $carbon) {
                if ($this->config->dayShouldBeHidden($carbon)) {
                    continue;
                }

                // Use a key based on the day’s date to track occupied rows
                $dayKey = $carbon->format('Y-m-d');

                // If this row (timeslot) is already occupied for this day, skip rendering a <td>
                if (isset($occupied[$dayKey]) && in_array($rowIndex, $occupied[$dayKey])) {
                    continue;
                }

                // Set the current cell’s datetime (assumes event boundaries align with timeslot boundaries)
                $datetime = $carbon->setTimeFrom($time);

                // Find events that start in this timeslot.
                // (Adjust this logic if you need to detect events that “started” earlier but span into this slot.)
                $events = $this->findEvents(
                    $datetime,
                    $datetime->clone()->addMinutes($this->options['timeInterval'])
                );

                // If there is at least one event starting now, pick the first one
                if (!empty($events)) {
                    $event = reset($events);

                    // Calculate how many minutes the event lasts from the current cell’s datetime.
                    $minutes = $event->end->diffInMinutes($datetime);

                    // Determine the number of timeslots (rows) this event spans.
                    $interval = $this->options['timeInterval']; // e.g. 60 minutes
                    $rowspan  = intval(ceil($minutes / $interval));

                    // Mark subsequent rows for this day as occupied by this event
                    for ($i = $rowIndex + 1; $i < $rowIndex + $rowspan; $i++) {
                        $occupied[$dayKey][] = $i;
                    }

                    // Optionally, add a CSS class for the current hour
                    $today_class = $carbon->isSameHour($today) ? ' today' : '';

                    // Render the cell with the event and the rowspan attribute
                    $content .= '<td class="cal-weekview-time ' . $today_class . '" rowspan="' . $rowspan . '">';
                    $content .= $this->renderEvent($event, $carbon, $rowspan);
                    $content .= '</td>';
                } else {
                    // No event for this cell; render an empty cell.
                    $today_class = $carbon->isSameHour($today) ? ' today' : '';
                    $content .= '<td class="cal-weekview-time ' . $today_class . '">';
                    $content .= '<div></div>';
                    $content .= '</td>';
                }
            }
            $content .= '</tr>';
        }

        return $content;
    }

    /**
     * Renders an event. If the event spans multiple timeslots,
     * adds a custom class and a visual indicator.
     */
    protected function renderEvent(Event $event, CarbonInterface $dateTime, int $rowspan): string
    {
        $classes = '';

        // Render the event summary only once.
        if (in_array($event, $this->usedEvents)) {
            $eventSummary = '&nbsp;';
        } else {
            $eventSummary = $event->summary;
            $this->usedEvents[] = $event;
        }

        // Add custom classes based on the event’s position.
        // (Adjust the conditions if you want to support events that span
        // days versus events that span multiple rows in a single day.)
        if ($event->start->isSameDay($dateTime)) {
            // The cell where the event starts
            $classes .= $event->mask ? ' mask-start' : '';
            $classes .= ' ' . $event->classes;
        } elseif ($dateTime->betweenExcluded($event->start, $event->end)) {
            // This condition may not actually be hit if we only render at the start cell.
            $classes .= $event->mask ? ' mask' : '';
        } elseif ($dateTime->isSameDay($event->end)) {
            $classes .= $event->mask ? ' mask-end' : '';
        }

        // If the event spans multiple rows, add an extra class.
        if ($rowspan > 1) {
            $classes .= ' multi-row-event';
        }

        return '<div class="cal-weekview-event ' . $classes . '">' . $eventSummary . '</div>';
    }


    /**
     * @param array{color?: string, startDate?: (string|DateTimeInterface), timeInterval?: int, endTime?: string, startTime?: string} $options
     *
     * @return array{color: string, startDate: (string|CarbonInterface), timeInterval: int, endTime: string, startTime: string}
     */
    public function initializeOptions(array $options): array
    {
        return [
            'color' => $options['color'] ?? '',
            'startDate' => $this->sanitizeStartDate(Carbon::parse($options['startDate'] ?? null)),
            'timeInterval' => $options['timeInterval'] ?? $this->config->time_interval,
            'startTime' => $options['startTime'] ?? $this->config->start_time,
            'endTime' => $options['endTime'] ?? $this->config->end_time,
        ];
    }
}
