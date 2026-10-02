# Product requirements — October 2026

Malawi Airlines Roster Builder supports schedulers, crew control, pilots and cabin crew. Shared Laravel APIs own operational data and decisions. This document preserves the prototype scope; docs/roadmap.md distinguishes shipped setup from future product work.

## Planning
Select a calendar month from six months back to 24 months ahead. Views: whole month, days 1–14, or 15–end. GMT is the default display; local is GMT+2 by default and adjustable. View/range/timezone filters apply consistently to every export.
Expand active patterns into trips. Each trip requires one CPT, one FO and the aircraft cabin count. Hardest seats first; choose lowest-cost legal crew, then rebalance hours. Never automatically break a rule; leave open time with candidate rejection reasons. Preserve manual assignments and exclusions on rebuild.

## Default configurable rules
Report 60 minutes before first departure; release 30 minutes after last arrival. Maximum daily duty 13 hours; minimum rest 12 hours, including night stops; rolling seven-day duty 60 hours; calendar-month block 100 hours; maximum consecutive duty days 5; minimum monthly days off 8 (leave counts). Maximum weekly days off 4, Monday–Sunday, with prorated edge weeks and leave excluded; 7 disables this rule. Planned standby may fill workload gaps only when legal and not on protected days off.
Position, aircraft rating, base, licence, medical and recurrent expiry all constrain eligibility. Missing documents must have an explicit policy before automatic assignment.

## Fairness
Score = block hours + 1.5 × trips + 4 for late finish after 18:00 followed by start before 07:00 − 1 for adjacent duties. Show pilot/cabin block ranges, assignment rationale, next-best candidates and rejected reasons.

## Operational data
Aircraft types: unique code, cabin requirement 0–20, roster palette. Prevent deletion while referenced by flights, ratings or airframes.
Airframes: unique registration (e.g. 7Q-TBA), aircraft type, airframe hours, notes. Status is available, in maintenance, grounded (AOG) or unavailable; any status other than available needs a reason, and every change is audited with its time. New airframes start available; status is never set through the general edit form. Airframes with maintenance history cannot be deleted (mark unavailable instead). Flights show a warning when every registered airframe of their type is unavailable.
Maintenance records: airframe, check type (line check, A-check, C-check, scheduled inspection, component/hard-time, servicing, other), description, performed date, optional airframe hours at completion, next due date and/or next due airframe hours, notes, recorded-by user. The latest record of each check type per airframe sets its next due point. Alerts: overdue when the base-local date has passed or airframe hours reach the due hours; due soon within 14 days or 50 hours (config/roster.php). Alerts never change aircraft status automatically.
Flight enable/disable: a flight pattern can be disabled and re-enabled without resubmitting its legs; disabled patterns stay on file and must not be planned.
Flights: unique uppercase code, aircraft, operating weekdays (Monday=0), connected legs with airports and base-local times, up to four duty days, returning to base. Add/edit/copy/delete; split/join stops; returns and night stops. Validate duty/rest and warn on turnarounds below 20 minutes or bases lacking rated captains.
Crew: name, optional email, CPT/FO/CC, base, aircraft ratings, document expiry dates. Cabin can be all-rated. Show expired, expires in period, and within 30 days afterwards.
Day planning: leave, simulator, standby and protected day off; timed activities count as duty. Date ranges and notes supported.
Manual assignment: replace/remove/undo; legal choices first; persist locks; flag overrides; assigning a flight clears standby atomically.

## Rosters and outputs
Whole grid and individual view, rank/base filters, duty details and flags. Summary: filled/open seats, breaks, expiry alerts, pilot/cabin ranges.
Print landscape roster or per-person pages. HTML, CSV, individual/selected/full PDFs with authorized airline logo, continuation pages, UTC ICS events. Email attachment delivery and logs, with Crew Control signature. Share-sheet fallback for mobile is a future client concern. No email is sent from demo seeders.

## Import and recovery
CSV comma/semicolon/tab, aliases for columns, flexible dates including Excel serial dates, operating-day formats, display-zone entry conversion. Preview every row before commit. Merge or replace by kind; match crew by name and flights by code with ambiguity handling. Download templates. Versioned JSON backup/restore with validation, authorization, atomic replacement and audit. Preserve compatibility through an explicit crew-roster-v2 importer, not unrestricted mass assignment.

## Demonstration data
Q400 (2 cabin), B737 (3 cabin); LLW and BLZ bases; LB1, LB2, LN1, LH1, BJ1, LJ1, LD1, LK1, LA1 (night stop). 48 fictional crew (12 CPT, 12 FO, 24 CC), example.com emails, activities and expiry examples. Four fictional airframes (7Q-DMA–7Q-DMD) across all statuses with overdue, due-soon and current maintenance examples. No default production credentials. The Malawi Airlines logo supplied in logo/ is served from public/images/malawi-airlines-logo.png; confirm usage rights before external distribution.
