<?php

namespace Tests\Unit\Support;

use App\Support\Roster\Section;
use PHPUnit\Framework\TestCase;

/**
 * Captain sections as leg ranges of a trip's schedule snapshot (pure calculations, no framework): the section's
 * own report and release from the trip's margins, base-local times and dates with the explicit offset (also
 * across local midnight), whole-trip ranges left alone, and the leg signature used to detect pattern changes.
 */
class SectionTest extends TestCase
{
    public function test_a_section_reports_before_its_own_first_leg_and_is_released_after_its_last(): void
    {
        $section = Section::restrict($this->doubleLoop(), 3, 4);

        $this->assertSame('LLW-MZU-LLW', $section['route']);
        $this->assertSame(['10:30', '14:40', '2026-10-05', ['2026-10-05']], [$section['duties'][0]['report_local'], $section['duties'][0]['release_local'], $section['duties'][0]['date'], $section['duties'][0]['dates']]);
        $this->assertSame(['2026-10-05T08:30:00+00:00', '2026-10-05T12:40:00+00:00'], [$section['report'], $section['release']]);
        $this->assertSame([120, 250], [$section['block_minutes'], $section['duty_minutes']]);
        $this->assertSame(['from_leg' => 3, 'to_leg' => 4, 'legs' => 4, 'from_airport' => 'LLW', 'to_airport' => 'LLW', 'trip_route' => 'LLW-BLZ-LLW-MZU-LLW'], $section['section']);
    }

    public function test_local_times_and_dates_follow_the_offset_across_midnight(): void
    {
        $night = ['code' => 'LN9', 'route' => 'LLW-BLZ-LLW', 'utc_offset_minutes' => 120, 'duties' => [[
            'trip_day' => 1, 'date' => '2026-10-05', 'dates' => ['2026-10-05', '2026-10-06'],
            'report' => '2026-10-05T21:00:00+00:00', 'release' => '2026-10-06T01:00:00+00:00', 'report_local' => '23:00', 'release_local' => '03:00', 'block_minutes' => 120, 'duty_minutes' => 240,
            'legs' => [
                ['from_airport' => 'LLW', 'to_airport' => 'BLZ', 'departs' => '2026-10-05T22:00:00+00:00', 'arrives' => '2026-10-05T23:00:00+00:00', 'departs_local' => '00:00', 'arrives_local' => '01:00', 'block_minutes' => 60],
                ['from_airport' => 'BLZ', 'to_airport' => 'LLW', 'departs' => '2026-10-05T23:30:00+00:00', 'arrives' => '2026-10-06T00:30:00+00:00', 'departs_local' => '01:30', 'arrives_local' => '02:30', 'block_minutes' => 60],
            ],
        ]]];

        $section = Section::restrict($night, 2, 2);

        $this->assertSame(['00:30', '03:00', '2026-10-06', ['2026-10-06']], [$section['duties'][0]['report_local'], $section['duties'][0]['release_local'], $section['duties'][0]['date'], $section['duties'][0]['dates']]);
    }

    public function test_the_whole_trip_is_not_a_section(): void
    {
        $trip = $this->doubleLoop();

        $this->assertSame($trip, Section::restrict($trip, 1, 4));
        $this->assertSame($trip, Section::restrict($trip, null, null));
        $this->assertFalse(Section::isPartial($trip, 1, 4));
        $this->assertTrue(Section::isPartial($trip, 1, 2));
    }

    public function test_the_signature_lists_the_legs_so_a_changed_pattern_is_noticed(): void
    {
        $this->assertSame('LLW>BLZ,BLZ>LLW,LLW>MZU,MZU>LLW', Section::signature($this->doubleLoop()));
        $this->assertSame(['BLZ', 'LLW'], Section::ends($this->doubleLoop(), 2, 4));
    }

    /**
     * LLW-BLZ-LLW then LLW-MZU-LLW on Monday 5 October 2026, base UTC+2: report 07:00 LT, release 14:40 LT
     * (60 minutes before the first departure, 30 after the last arrival).
     *
     * @return array<string, mixed>
     */
    private function doubleLoop(): array
    {
        $leg = fn (string $from, string $to, string $departs, string $arrives, string $departsLocal, string $arrivesLocal): array => ['from_airport' => $from, 'to_airport' => $to,
            'departs' => '2026-10-05T'.$departs.':00+00:00', 'arrives' => '2026-10-05T'.$arrives.':00+00:00', 'departs_local' => $departsLocal, 'arrives_local' => $arrivesLocal, 'block_minutes' => 60];

        return ['code' => 'LB5', 'route' => 'LLW-BLZ-LLW-MZU-LLW', 'base' => 'LLW', 'utc_offset_minutes' => 120,
            'report' => '2026-10-05T05:00:00+00:00', 'release' => '2026-10-05T12:40:00+00:00', 'block_minutes' => 240, 'duty_minutes' => 460,
            'duties' => [[
                'trip_day' => 1, 'date' => '2026-10-05', 'dates' => ['2026-10-05'],
                'report' => '2026-10-05T05:00:00+00:00', 'release' => '2026-10-05T12:40:00+00:00', 'report_local' => '07:00', 'release_local' => '14:40', 'block_minutes' => 240, 'duty_minutes' => 460,
                'legs' => [
                    $leg('LLW', 'BLZ', '06:00', '07:00', '08:00', '09:00'), $leg('BLZ', 'LLW', '07:40', '08:40', '09:40', '10:40'),
                    $leg('LLW', 'MZU', '09:30', '10:30', '11:30', '12:30'), $leg('MZU', 'LLW', '11:10', '12:10', '13:10', '14:10'),
                ],
            ]]];
    }
}
