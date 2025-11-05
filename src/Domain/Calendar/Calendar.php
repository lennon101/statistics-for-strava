<?php

declare(strict_types=1);

namespace App\Domain\Calendar;

use App\Domain\Activity\ActivityRepository;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class Calendar
{
    private function __construct(
        private Month $month,
        private ActivityRepository $activityRepository,
    ) {
    }

    public static function create(
        Month $month,
        ActivityRepository $activityRepository,
    ): self {
        return new self(
            month: $month,
            activityRepository: $activityRepository
        );
    }

    public function getMonth(): Month
    {
        return $this->month;
    }

    public function getDays(): Days
    {
        $previousMonth = $this->month->getPreviousMonth();
        $nextMonth = $this->month->getNextMonth();
        $numberOfDaysInPreviousMonth = $previousMonth->getNumberOfDays();

        // Optimization: Fetch all activities for the entire range in a single query
        // instead of querying for each day individually (N+1 query problem)
        $firstDayToShow = SerializableDateTime::createFromFormat(
            format: 'd-n-Y',
            datetime: (1 + $numberOfDaysInPreviousMonth - ($this->month->getWeekDayOfFirstDay() - 1)).'-'.$previousMonth->getMonth().'-'.$previousMonth->getYear(),
        );
        
        // Calculate the last day we need to show (up to 6 days into next month)
        $potentialDaysInNextMonth = (7 - (($this->month->getWeekDayOfFirstDay() + $this->month->getNumberOfDays() - 1) % 7)) % 7;
        $lastDayToShow = SerializableDateTime::createFromFormat(
            format: 'd-n-Y',
            datetime: max(1, $potentialDaysInNextMonth).'-'.$nextMonth->getMonth().'-'.$nextMonth->getYear(),
        );

        $allActivities = $this->activityRepository->findByDateRange($firstDayToShow, $lastDayToShow, null);
        
        // Group activities by date for fast lookup
        $activitiesByDate = [];
        foreach ($allActivities as $activity) {
            $dateKey = $activity->getStartDate()->format('Y-m-d');
            if (!isset($activitiesByDate[$dateKey])) {
                $activitiesByDate[$dateKey] = [];
            }
            $activitiesByDate[$dateKey][] = $activity;
        }

        $days = Days::empty();
        for ($i = 1; $i < $this->month->getWeekDayOfFirstDay(); ++$i) {
            // Prepend with days of previous month.
            $dayNumber = $numberOfDaysInPreviousMonth - ($this->month->getWeekDayOfFirstDay() - $i - 1);
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$previousMonth->getMonth().'-'.$previousMonth->getYear(),
            );
            $dateKey = $date->format('Y-m-d');
            $days->add(Day::create(
                dayNumber: $dayNumber,
                isCurrentMonth: false,
                activities: isset($activitiesByDate[$dateKey]) ? Activities::fromArray($activitiesByDate[$dateKey]) : Activities::empty()
            ));
        }

        for ($i = 0; $i < $this->month->getNumberOfDays(); ++$i) {
            $dayNumber = $i + 1;
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$this->month->getMonth().'-'.$this->month->getYear(),
            );
            $dateKey = $date->format('Y-m-d');
            $days->add(Day::create(
                dayNumber: $dayNumber,
                isCurrentMonth: true,
                activities: isset($activitiesByDate[$dateKey]) ? Activities::fromArray($activitiesByDate[$dateKey]) : Activities::empty()
            ));
        }

        for ($i = 0; $i < count($days) % 7; ++$i) {
            // Append with days of next month.
            $dayNumber = $i + 1;
            $date = SerializableDateTime::createFromFormat(
                format: 'd-n-Y',
                datetime: $dayNumber.'-'.$nextMonth->getMonth().'-'.$nextMonth->getYear(),
            );
            $dateKey = $date->format('Y-m-d');
            $days->add(Day::create(
                dayNumber: $dayNumber,
                isCurrentMonth: false,
                activities: isset($activitiesByDate[$dateKey]) ? Activities::fromArray($activitiesByDate[$dateKey]) : Activities::empty()
            ));
        }

        return $days;
    }
}
