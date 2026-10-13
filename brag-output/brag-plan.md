# Brag plan: Malawi Airlines Roster Builder, the complete how-to

**User direction:** "a video that covers almost all needed features and how to", chosen length 2–3 min (tutorial).
This overrides the usual 15–25 s brag length on purpose. Tone: `polished` / `app-store`, meaning calm, exact, and in the
crew-control voice of the app itself.

## Planning answers
- **What is it?** A crew-control workstation for Malawi Airlines: flight patterns, fleet readiness, crew licensing and an
  automatic roster generator that fills every seat with legal crew.
- **Who is it for?** Schedulers, crew control and administrators. Pilots and cabin crew sign in to see their own published roster.
- **What sets it apart?** The generator never breaks a duty rule. It leaves a seat open and says why. Every change is
  audited, and the duty inspector explains why each person was picked.
- **Most impressive claim (app copy):** "The generator fills every seat with legal crew, balancing each person's working hours."
- **Visual hook:** the navy sign-in hero with the LLW → BLZ flight strip, then the live roster grid.
- **Real UI shown:** 41 captures of the running app (roster-builder.test) with the demo data. Dialogs are opened but never saved.
- **Share line:** "Every screen of the Malawi Airlines Roster Builder in three minutes: from sign-in to a published roster."

## Visual identity
Navy hero `#16233a` with a faint chart grid. Tail green `#3f9b4f` marks the active step and the highlight ring. Wordmark red
`#c8312a` appears once, on the logo underline. Strip buff `#f5ebcf` is used for chapter tags. Type is Segoe UI (what the app
renders here) with Cascadia Mono for eyebrows, matching the app's mono labels.
Layout: a step rail on the left (chapter eyebrow, title, numbered steps that tick off), the real app in a browser window
on the right (cursor, click ripples, highlight ring, gentle camera zooms), and a section progress bar along the bottom.

## Storyboard (≈180 s, 30 fps, 1920×1080)
| # | Chapter | s | Screens | How-to steps |
|---|---|---|---|---|
| 0 | Hook | 5 | Logo, hero line, LLW→BLZ strip | "Crew rosters, flight patterns and fleet readiness in one console." |
| 1 | Sign in | 8 | login empty → typed | email or username → password → Sign in (forgot password link) |
| 2 | Dashboard | 10 | dashboard top, lower | KPI strip · last/current/next roster · needs attention |
| 3 | Airports | 7 | airports, add dialog | Admin settings → Airports · code, name, UTC offset · crew base |
| 4 | Flight routes | 12 | list, LA1 editor | Add flight · days · connected legs in GMT, night stops · enable switch |
| 5 | Fleet | 9 | airframes, availability dialog | airframe cards · Availability + reason · not planned |
| 6 | Maintenance | 9 | due list, record dialog | overdue / due soon · record completion · next due |
| 7 | Crew | 10 | directory, edit dialog | add crew · position/base/ratings · weekly hours, documents |
| 8 | Duty rules | 8 | rules form | limits · rest & days off · save (snapshotted per roster) |
| 9 | New roster | 10 | roster top, create dialog | New roster · 1w/2w/month · start date · Create & build |
| 10 | Build & review | 12 | KPIs, grid, inspector | Rebuild keeps locks · Open time row · inspector rationale |
| 11 | Change a seat | 10 | seat dialog | Change · legal first by fairness · reason for overrides · exclude/undo |
| 12 | Plan days | 8 | plan dialog | empty day · leave/day off/SIM/standby · timed = duty |
| 13 | Views | 9 | individual, trips, conflicts | three views |
| 14 | Publish & share | 10 | publish, outputs bar | Publish · CSV/PDF/crew pages · print/email + calendar |
| 15 | Crew hours | 7 | hours pilots/cabin | block & duty windows |
| 16 | Reports | 9 | reports ×3 | pick report · period ≤ 1 year · CSV/PDF |
| 17 | Import & backup | 8 | data | CSV import with preview · backup/restore |
| 18 | Accounts | 8 | accounts, create dialog | account types · crew see own roster only |
| 19 | Your settings | 6 | light → dark, my account | theme · change password |
| 20 | Outro | 6 | logo, app copy line | "Development build · not an approved scheduling system" footnote (honest) |

Transitions: within a chapter screens crossfade (dialogs open over the same page). Between chapters the window dips
through the canvas colour and the step rail swaps out and then in. The bottom bar tracks the five sections: Start · Set up · Rosters · Track · Admin.

## Sound
Original synthesized bed in D major at 92 BPM: Dmaj7 – Bm7 – Gmaj7 – A6sus pads, a soft sub bass and a plucked arpeggio,
with light drums entering after the intro and dropping out for the outro. Clicks are soft, short ticks tuned to sit under
the music. Each chapter change gets a quiet airy swell and a two-note chime in key. One shared reverb, soft-limited.
