---
name: Aeronautical Operations System
colors:
  surface: '#f5faff'
  surface-dim: '#d1dbe3'
  surface-bright: '#f5faff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#ebf5fd'
  surface-container: '#e5eff7'
  surface-container-high: '#e0e9f2'
  surface-container-highest: '#dae4ec'
  on-surface: '#131d23'
  on-surface-variant: '#45474d'
  inverse-surface: '#283238'
  inverse-on-surface: '#e8f2fa'
  outline: '#75777e'
  outline-variant: '#c5c6ce'
  surface-tint: '#525f78'
  primary: '#010d23'
  on-primary: '#ffffff'
  primary-container: '#16233a'
  on-primary-container: '#7e8aa6'
  inverse-primary: '#bac7e5'
  secondary: '#1a648c'
  on-secondary: '#ffffff'
  secondary-container: '#92d0fd'
  on-secondary-container: '#045a81'
  tertiary: '#220017'
  on-tertiary: '#ffffff'
  tertiary-container: '#490034'
  on-tertiary-container: '#ce66a5'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d7e2ff'
  primary-fixed-dim: '#bac7e5'
  on-primary-fixed: '#0e1b32'
  on-primary-fixed-variant: '#3a4760'
  secondary-fixed: '#c8e6ff'
  secondary-fixed-dim: '#8fcefa'
  on-secondary-fixed: '#001e2f'
  on-secondary-fixed-variant: '#004c6e'
  tertiary-fixed: '#ffd8ea'
  tertiary-fixed-dim: '#ffaeda'
  on-tertiary-fixed: '#3c002a'
  on-tertiary-fixed-variant: '#7c215e'
  background: '#f5faff'
  on-background: '#131d23'
  surface-variant: '#dae4ec'
typography:
  headline-lg:
    fontFamily: Public Sans
    fontSize: 28px
    fontWeight: '700'
    lineHeight: 34px
    letterSpacing: -0.02em
  headline-md:
    fontFamily: Public Sans
    fontSize: 22px
    fontWeight: '700'
    lineHeight: 28px
    letterSpacing: -0.01em
  headline-sm:
    fontFamily: Public Sans
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 24px
  title-md:
    fontFamily: Public Sans
    fontSize: 15px
    fontWeight: '700'
    lineHeight: 20px
  title-sm:
    fontFamily: Public Sans
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 18px
  body-lg:
    fontFamily: Public Sans
    fontSize: 15px
    fontWeight: '400'
    lineHeight: 20px
  body-md:
    fontFamily: Public Sans
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  body-sm:
    fontFamily: Public Sans
    fontSize: 11px
    fontWeight: '400'
    lineHeight: 14px
  data-lg:
    fontFamily: JetBrains Mono
    fontSize: 16px
    fontWeight: '700'
    lineHeight: 20px
    letterSpacing: -0.02em
  data-md:
    fontFamily: JetBrains Mono
    fontSize: 13px
    fontWeight: '600'
    lineHeight: 16px
  data-sm:
    fontFamily: JetBrains Mono
    fontSize: 11px
    fontWeight: '500'
    lineHeight: 14px
  label-caps:
    fontFamily: JetBrains Mono
    fontSize: 10px
    fontWeight: '700'
    lineHeight: 12px
    letterSpacing: 0.06em
rounded:
  sm: 0.125rem
  DEFAULT: 0.25rem
  md: 0.375rem
  lg: 0.5rem
  xl: 0.75rem
  full: 9999px
spacing:
  gutter: 0.5rem
  gutter-tablet: 0.75rem
  gutter-desktop: 1rem
  margin: 0.75rem
  margin-tablet: 1.25rem
  margin-desktop: 1.75rem
  space-xs: 0.25rem
  space-sm: 0.375rem
  space-md: 0.75rem
  space-lg: 1rem
  space-xl: 1.5rem
---

## Brand & Style

This design system delivers an operational dispatch interface engineered for airline flight crews, schedulers, and station managers. The visual tone is grounded in aviation utility: precise, non-distracting, high-legibility, and strictly focused on complex operational coordination under time constraints.

Taking direct cues from traditional physical air traffic control buff paper flight strips and aeronautical terminal charts, the aesthetic eliminates decorative consumer ornamentation—eschewing gradients, photographic backdrops, and artificial glassmorphism. Visual distinction relies entirely on clear hierarchical lines, structural left-accent indicators, fixed-pitch metrics, and deliberate sectional fills. The interface communicates institutional reliability, regulatory compliance, and rapid situational awareness.

## Colors

The palette is engineered for prolonged use in high-glare flight deck or low-light briefing room environments, prioritizing high-contrast legibility across dense data matrices.

- **Background Canvas (`#E9EEF1`)**: A calming, desaturated chart-grey providing clear structural separation from active flight modules without eye fatigue.
- **Surface / Card Container (`#FFFFFF`)**: Crisp, stark white baseline cards for unassigned states, navigation, and modal dialogues.
- **Flight Strip Buff (`#F5EBCF`)**: Warm tactile strip fill applied to active roster pairings, duty legs, and assigned flight envelopes to replicate ATC mechanical paper media.
- **Primary Typography (`#16233A`)**: Deep oceanic navy for flight numbers, ICAO/IATA airport identifiers, block times, and crew rank stamps.
- **Secondary Typography (`#56677A`)**: Slate grey for UTC timestamps, aircraft tail registrations, duty limits, and sub-labels.
- **Structural Dividing Lines (`#CAD4DC`)**: 1px sharp rules separating sector segments, crew lists, and tabular columns.

### Fleet & Duty State Accents
- **Q400 Fleet (`#2A6F97`)**: Steel aeronautical blue applied to turboprop sector bars and badge markers.
- **B737 Fleet (`#8C2F6B`)**: Chart magenta dedicated to jet sector indicators, long-haul legs, and cross-base movements.
- **Simulator & Recurrent Training (`#5B4B9A`)**: Deep purple for ground school, check rides, and simulator blocks.
- **Standby & Reserve (`#1F7A7A`)**: Deep teal identifying airport standby and home reserve readiness blocks.
- **Warning & Open Time (`#B86E00`)**: Amber highlight for open pairings, minimum rest warnings, and uncrewed sectors.
- **Regulatory Pass / Validated (`#2E7D4F`)**: Green confirmation for legal flight duty period (FDP) compliance and completed sign-offs.
- **Rule Violation / Leg Conflict (`#B3261E`)**: Red indicator for rest infractions, duty exceedance, and unresolved roster clashes.

## Typography

Typography prioritizes information density and zero-ambiguity character scanning. Layouts rely on two type systems:
1. **Public Sans**: Primary structural and interface typeface. Selected for neutral, legible grotesque shapes that mimic standard government and aviation operating systems.
2. **JetBrains Mono**: Operational data typeface. Enforced for flight numbers, ICAO route pairs (e.g., FWLI-FWKI), scheduled departure/arrival times (STD/STA), UTC references, and total duty times (FDP/Block). Tabular monospaced figures guarantee column alignment when scanning stacks of flight strips.

All line heights remain intentionally compressed to preserve vertical density on mobile displays. Uppercase strings with tracked-out spacing (`label-caps`) are strictly reserved for operational headers, state categories, and strip field tags.

## Layout & Spacing

The layout is built for high-density mobile displays, expanding into a multi-column roster matrix on tablet and desktop terminals.

- **Mobile Canvas**: A 4-column fluid layout with narrow 12px outer margins (`0.75rem`) and 8px gutters (`0.5rem`). Screen real estate is conserved for continuous chronological duty feeds.
- **Tablet / EFB (Electronic Flight Bag)**: 8-column layout utilizing a persistent split pane (left: monthly calendar roster timeline; right: selected duty day flight strip breakdown).
- **Desktop Dispatch Console**: 12-column layout displaying parallel timeline ribbons for crew lines alongside individual sector operational cards.

Vertical spacing follows a strict 4px/8px modular scale. Card gaps sit uniformly at `space-sm` (6px) or `space-md` (12px) to maximize the number of visible legs on screen without scrolling. Every interactive target maintains a minimum height of 44px regardless of dense visual presentation.

## Elevation & Depth

This system avoids floating drop shadows and blur-filtered depth. Elevation is purely structural, communicated through flat, physical layering:

- **Level 0 (Base Canvas)**: Tinted `#E9EEF1` chart plane. All non-interactive backgrounds reside here.
- **Level 1 (Card & Flight Strip Plane)**: High-contrast `#FFFFFF` or `#F5EBCF` surfaces defined by a crisp 1px solid border (`#CAD4DC`). Zero shadow.
- **Level 2 (Active Drag / Selected State)**: Applied when rearranging crew assignments or lifting a leg strip. Represented by a 1px solid stroke (`#16233A`) and a crisp, offset hard edge (0px blur, 2px vertical offset at 15% opacity), reinforcing the feel of physical card stock lifted off a dispatch console.
- **Level 3 (Alert Sheets & Overlays)**: System drawers and duty validation dialogs sit atop a 40% alpha `#16233A` blackout overlay, encased in a 1.5px solid border with zero ambient blur.

## Shapes

Corner radii are restrained, technical, and compact:
- Standard components (buttons, flight strip containers, status indicators) use a tight 4px (`rounded-sm`) to 6px (`rounded-md`) radius.
- Segmented strip sub-cells and internal data matrices use 0px sharp inner joints bounded within a continuous rounded container.
- Floating modal sheets and pull drawers use 8px to 10px on top corners, preventing visual softness and aligning with industrial equipment interfaces.

## Components

### Flight Strip Cards
The centerpiece of the roster. Modeled after physical air traffic control strips:
- **Surface**: Buff paper fill (`#F5EBCF`) with a 1px border (`#CAD4DC`).
- **Left Accent Indicator**: A solid 5px vertical bar running the full card height, color-coded by operation type:
  - `#2A6F97` for Dash 8-Q400 sectors.
  - `#8C2F6B` for Boeing 737 sectors.
  - `#5B4B9A` for Simulator/Training events.
  - `#1F7A7A` for Reserve/Standby periods.
  - `#B86E00` for Open/Uncrewed duties.
- **Internal Grid**: Divided into 3 horizontal zones using 1px `#CAD4DC` rules:
  1. *Header Zone*: Flight Number (e.g., `3W 102`) and aircraft type badge in `data-md` bold, alongside STD/STA times.
  2. *Sector Zone*: Origin and Destination IATA/ICAO blocks (e.g., `LLW / FWKI → BLZ / FWCL`) with large font sizing, accompanied by block hour metrics.
  3. *Crew/Remarks Zone*: Captain, First Officer, and Cabin Crew initials or vacancy warnings in `label-caps`.

### Buttons
- **Primary Operational Button**: High-contrast `#16233A` background, white text, 4px border radius, 44px minimum touch height, uppercase `label-caps` bold text.
- **Secondary / Action Button**: White background, 1.5px stroke of `#16233A`, deep navy label.
- **Destructive / Unassign Button**: Background `#B3261E` with crisp white text for legally prohibited actions or drop-duty confirmations.

### Form Inputs & Selectors
- **Input Fields**: Crisp `#FFFFFF` surface with a 1px `#CAD4DC` border, transitioning to a 2px `#16233A` focus border. Labels sit outside the field using uppercase `label-caps`.
- **Segmented Duty Filters**: Compact horizontal multi-segment switches (e.g., `ALL | Q400 | B737 | SBY`) using 1px internal borders, with the active segment marked in `#16233A` fill and white text.

### Badges & Status Chips
- Square-cornered or 3px-radii pill badges.
- Strict muted background tint (15% opacity) with a solid high-contrast text color corresponding to fleet or regulatory state.
- Warning badges feature an alert icon paired with tabular violation timers (e.g., `! REST: -01:15`).

### Checkboxes & Segment Toggles
- Custom square selectors (18x18px) with a 1.5px solid stroke in `#16233A`. 
- Checked state uses a solid `#16233A` fill with a sharp white checkmark. Enforces a 44x44px invisible tap area for rapid one-handed mobile auditing.