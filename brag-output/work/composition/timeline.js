// Video timeline: chapters with their how-to steps and timed events. Shared by the page (window.TL) and the
// audio script (evaluated in Node). Event fields: at (s from chapter start), shot, step (index of the active
// step), cur (cursor target: box key of the current shot or {x,y,w,h} in 1600x900 app px), click, hl (highlight
// ring target or null), cam ({f: target|null, z}), type ({box, text, dur}).
(function (root) {
  const R = (x, y, w, h) => ({ x, y, w, h });
  const chapters = [
    { id: 'intro', dur: 6 },
    { id: 'signin', dur: 8, eyebrow: 'Get started', title: 'Sign in', path: '/login', steps: [
      'Enter your email or username',
      'Enter your password',
      'Sign in. Forgot it? “Forgot your password?” emails a reset link',
    ], ev: [
      { at: 0, shot: 'login-empty', step: 0, cam: { f: R(1000, 200, 440, 480), z: 1.0 } },
      { at: 0.5, cam: { f: R(1000, 200, 440, 480), z: 1.45 } },
      { at: 1.1, cur: 'id', click: true, hl: 'id' },
      { at: 1.3, type: { box: 'id', text: 'scheduler', dur: 1.0 } },
      { at: 2.9, step: 1, cur: 'pass', click: true, hl: 'pass' },
      { at: 3.2, shot: 'login-filled' },
      { at: 4.6, step: 2, cur: 'submit', click: true, hl: 'submit' },
      { at: 6.2, cur: R(1113, 634, 124, 16), hl: R(1113, 634, 124, 16) },
    ] },
    { id: 'dashboard', dur: 10, eyebrow: 'Get started', title: 'Operations dashboard', path: '/dashboard', steps: [
      'The KPI strip: seat coverage, conflicts, fleet, flights and documents',
      'Last, current and next roster. Open one to review it',
      'Needs attention: aircraft out of service and expiring documents',
    ], ev: [
      { at: 0, shot: 'dash-top', step: 0, cam: { f: null, z: 1 } },
      { at: 0.7, cur: R(330, 330, 300, 110), hl: R(28, 323, 1544, 129) },
      { at: 3.4, step: 1, cur: 'current', hl: R(553, 543, 494, 205) },
      { at: 6.6, step: 2, shot: 'dash-lower', hl: R(28, 328, 1544, 360), cur: R(1220, 380, 60, 30) },
    ] },
    { id: 'airports', dur: 7, eyebrow: 'Set up', title: 'Airports', path: '/airports', steps: [
      'Admin settings → Airports',
      'Add airport: code, name and UTC offset',
      'Tick “Crew base” where routes start and end',
    ], ev: [
      { at: 0, shot: 'airports', step: 0, cam: { f: null, z: 1 } },
      { at: 0.6, cur: R(176, 323, 107, 44), hl: R(176, 323, 107, 44) },
      { at: 2.2, step: 1, cur: 'add', click: true, hl: 'add' },
      { at: 2.45, shot: 'airports-dialog', hl: null, cam: { f: R(480, 237, 640, 427), z: 1.5 } },
      { at: 4.6, step: 2, cur: 'check', click: true, hl: 'base' },
    ] },
    { id: 'flights', dur: 12, eyebrow: 'Set up', title: 'Flight routes', path: '/flights', steps: [
      'Flight routes → Add flight, or edit one',
      'Flight code, aircraft type and operating days',
      'Connected legs in GMT. Tick a night stop at an outstation',
      'Switch a route off to stop planning it. Its legs are kept',
    ], ev: [
      { at: 0, shot: 'flights', step: 0, cam: { f: null, z: 1 } },
      { at: 1.0, cur: 'add', click: true, hl: 'add' },
      { at: 1.25, shot: 'flights-dialog', hl: null, cam: { f: R(320, 89, 960, 290), z: 1.5 } },
      { at: 2.4, step: 1, cur: R(352, 305, 360, 40), hl: R(340, 300, 400, 50) },
      { at: 5.0, step: 2, cam: { f: R(330, 500, 940, 200), z: 1.45 }, hl: R(980, 535, 140, 32), cur: R(985, 540, 20, 20), click: true },
      { at: 8.4, step: 3, shot: 'flights', cam: { f: null, z: 1 }, hl: 'toggle', cur: 'toggle' },
    ] },
    { id: 'fleet', dur: 9, eyebrow: 'Set up', title: 'Fleet', path: '/aircraft', steps: [
      'Every airframe, its status and its next maintenance check',
      'Availability → Available or Not available, with a reason',
      'Aircraft that are not available are never planned',
    ], ev: [
      { at: 0, shot: 'fleet', step: 0, cam: { f: null, z: 1 } },
      { at: 0.7, cur: R(560, 330, 100, 60), hl: 'dmb' },
      { at: 3.0, step: 1, cur: 'avail', click: true, hl: 'avail' },
      { at: 3.25, shot: 'fleet-dialog', hl: null, cam: { f: R(480, 211, 640, 477), z: 1.5 } },
      { at: 4.2, cur: R(800, 330, 280, 60), click: true, hl: R(795, 318, 300, 80) },
      { at: 6.2, step: 2, hl: R(495, 400, 610, 200), cur: R(700, 470, 100, 40) },
    ] },
    { id: 'maintenance', dur: 9, eyebrow: 'Set up', title: 'Maintenance', path: '/maintenance', steps: [
      'Overdue and due-soon checks are flagged: 14 days or 50 hours ahead',
      'Record completion: date, airframe hours and the work done',
      'Set the next due date or airframe hours',
    ], ev: [
      { at: 0, shot: 'maint', step: 0, cam: { f: null, z: 1 } },
      { at: 0.7, cur: R(400, 560, 200, 40), hl: R(28, 525, 1544, 322) },
      { at: 3.3, step: 1, cur: 'complete', click: true, hl: 'complete' },
      { at: 3.55, shot: 'maint-dialog', hl: null, cam: { f: R(432, 96, 736, 708), z: 1.22 } },
      { at: 6.0, step: 2, hl: R(450, 455, 710, 80), cur: R(470, 500, 200, 30) },
    ] },
    { id: 'crew', dur: 10, eyebrow: 'Set up', title: 'Crew directory', path: '/crew', steps: [
      'Crew → Add crew member, or edit one',
      'Position, base and aircraft ratings',
      'Weekly working hours and licence, medical and recurrent expiry',
      'Documents expiring within 30 days are flagged',
    ], ev: [
      { at: 0, shot: 'crew', step: 0, cam: { f: null, z: 1 } },
      { at: 1.0, cur: 'add', click: true, hl: 'add' },
      { at: 1.25, shot: 'crew-dialog', hl: null, cam: { f: R(432, 80, 736, 330), z: 1.55 } },
      { at: 2.0, step: 1, hl: R(445, 185, 710, 230), cur: R(600, 260, 120, 40) },
      { at: 4.8, step: 2, cam: { f: R(432, 400, 736, 330), z: 1.55 }, hl: R(445, 460, 710, 230), cur: R(1050, 540, 100, 40) },
      { at: 7.6, step: 3, shot: 'crew', cam: { f: null, z: 1 }, hl: 'expiring', cur: 'expiring' },
    ] },
    { id: 'rules', dur: 8, eyebrow: 'Set up', title: 'Duty rules', path: '/rules', steps: [
      'Report, release and maximum daily duty',
      'Rest, rolling 7-day duty, monthly block and days off',
      'Save. Each new roster keeps a snapshot of these rules',
    ], ev: [
      { at: 0, shot: 'rules', step: 0, cam: { f: R(47, 480, 990, 140), z: 1.5 }, hl: R(47, 488, 986, 117) },
      { at: 0.6, cur: 'daily' },
      { at: 2.6, step: 1, cam: { f: R(47, 620, 990, 220), z: 1.45 }, hl: R(47, 630, 986, 200), cur: 'rest' },
      { at: 5.2, step: 2, shot: 'rules2', cam: { f: null, z: 1 }, hl: 'save', cur: 'save' },
    ] },
    { id: 'newroster', dur: 10, eyebrow: 'Rosters', title: 'Create a roster', path: '/roster', steps: [
      'Roster → New roster',
      'Choose 1 week, 2 weeks or a calendar month',
      'Pick the start date and check the dates it covers',
      'Create & build: the generator fills every seat with legal crew',
    ], ev: [
      { at: 0, shot: 'roster-top', step: 0, cam: { f: null, z: 1 } },
      { at: 1.1, cur: 'newRoster', click: true, hl: 'newRoster' },
      { at: 1.35, shot: 'roster-create', hl: null, cam: { f: R(432, 232, 736, 436), z: 1.55 } },
      { at: 2.3, step: 1, hl: R(452, 340, 700, 95) },
      { at: 3.6, cur: R(945, 350, 200, 70), click: true },
      { at: 3.85, shot: 'roster-create2' },
      { at: 5.2, step: 2, hl: R(452, 465, 700, 110), cur: R(460, 475, 220, 36) },
      { at: 7.6, step: 3, hl: 'submit', cur: 'submit' },
    ] },
    { id: 'build', dur: 12, eyebrow: 'Rosters', title: 'Build and review', path: '/roster', steps: [
      'Rebuild any time: locked seats and flown trips are kept',
      'Coverage, open seats and rule conflicts are checked live',
      'Open time lists seats no legal crew member can fill',
      'Click a duty: the inspector shows who flies it, and why',
    ], ev: [
      { at: 0, shot: 'roster-top', step: 0, cam: { f: null, z: 1 }, hl: 'build' },
      { at: 0.7, cur: 'build' },
      { at: 2.7, step: 1, hl: R(28, 570, 1544, 130), cur: R(400, 620, 60, 40) },
      { at: 5.2, step: 2, shot: 'roster-grid', cam: { f: null, z: 1 }, hl: R(29, 186, 1162, 128), cur: R(120, 200, 60, 20) },
      { at: 7.6, step: 3, shot: 'roster-grid-scrolled', hl: null },
      { at: 8.3, cur: 'duty', click: true, hl: 'duty' },
      { at: 8.55, shot: 'roster-inspect', hl: R(1212, 109, 360, 560), cam: { f: R(1000, 109, 572, 620), z: 1.25 } },
    ] },
    { id: 'seat', dur: 10, eyebrow: 'Rosters', title: 'Change a seat', path: '/roster', steps: [
      'In the inspector, click Change',
      'Legal crew come first, lowest fairness score first',
      'Picking someone who breaks a rule needs a recorded reason',
      '“Never on this trip” and “Undo last change” are one click away',
    ], ev: [
      { at: 0, shot: 'roster-inspect', step: 0, cam: { f: R(1000, 300, 572, 500), z: 1.4 } },
      { at: 1.0, cur: 'change', click: true, hl: 'change' },
      { at: 1.25, shot: 'roster-seat', hl: null, cam: { f: R(432, 125, 736, 650), z: 1.3 } },
      { at: 2.0, step: 1, hl: R(457, 240, 686, 134), cur: R(1080, 262, 60, 30) },
      { at: 4.6, step: 2, hl: R(457, 381, 686, 272), cur: R(1080, 420, 60, 30) },
      { at: 7.2, step: 3, shot: 'roster-inspect', cam: { f: R(1000, 300, 572, 500), z: 1.4 }, hl: 'never', cur: 'never' },
    ] },
    { id: 'plan', dur: 8, eyebrow: 'Rosters', title: 'Plan leave, SIM and standby', path: '/roster', steps: [
      'Click an empty day in a crew member’s row',
      'Choose leave, protected day off, simulator or standby',
      'Add times so SIM and standby count as duty',
    ], ev: [
      { at: 0, shot: 'roster-plan-hover', step: 0, cam: { f: R(300, 200, 600, 300), z: 1.3 } },
      { at: 0.9, cur: 'cell', click: true, hl: 'cell' },
      { at: 1.15, shot: 'roster-plan', hl: null, cam: { f: R(480, 113, 640, 674), z: 1.3 } },
      { at: 2.0, step: 1, hl: 'type', cur: 'type' },
      { at: 4.6, step: 2, hl: R(500, 470, 600, 80), cur: R(900, 500, 100, 30) },
    ] },
    { id: 'views', dur: 9, eyebrow: 'Rosters', title: 'Individual, trips and conflicts', path: '/roster', steps: [
      'Individual: one person’s days, with a PDF page and calendar file',
      'Trips: every rotation with its crew and open seats',
      'Conflicts: each rule break, with a button to fix it',
    ], ev: [
      { at: 0, shot: 'roster-view-crew-grid', step: 0, cam: { f: null, z: 1 } },
      { at: 0.6, cur: R(364, 171, 99, 38), click: true, hl: R(364, 171, 99, 38) },
      { at: 0.85, shot: 'roster-view-individual' },
      { at: 3.3, step: 1, cur: 'tab', click: true, hl: 'tab' },
      { at: 3.55, shot: 'roster-view-trips' },
      { at: 6.1, step: 2, cur: R(527, 171, 91, 38), click: true, hl: R(527, 171, 91, 38) },
      { at: 6.35, shot: 'roster-view-conflicts' },
    ] },
    { id: 'publish', dur: 10, eyebrow: 'Rosters', title: 'Publish and share', path: '/roster', steps: [
      'Publish to crew once it is built and no conflict is unaccepted',
      'Download CSV, the PDF grid or one PDF page per crew member',
      'Print it, or email each crew member their roster and calendar',
    ], ev: [
      { at: 0, shot: 'roster-top', step: 0, cam: { f: null, z: 1 } },
      { at: 0.8, cur: 'publish', hl: 'publish' },
      { at: 3.4, step: 1, shot: 'roster-outputs', cam: { f: R(29, 700, 760, 120), z: 1.65 }, hl: R(42, 732, 242, 48), cur: 'pdf' },
      { at: 6.5, step: 2, hl: 'print', cur: 'print' },
    ] },
    { id: 'hours', dur: 8, eyebrow: 'Track', title: 'Crew hours', path: '/hours', steps: [
      'Block and duty hours from published rosters',
      'This week, month, last 28 days, year and 12 months',
      'Switch between Pilots and Cabin crew',
    ], ev: [
      { at: 0, shot: 'hours', step: 0, cam: { f: null, z: 1 }, hl: R(28, 323, 1544, 129) },
      { at: 0.6, cur: R(330, 360, 120, 60) },
      { at: 2.7, step: 1, hl: R(300, 548, 1272, 50), cur: R(600, 560, 80, 30) },
      { at: 5.4, step: 2, cur: 'cabin', click: true, hl: R(1162, 490, 169, 38) },
      { at: 5.65, shot: 'hours-cabin' },
    ] },
    { id: 'reports', dur: 9, eyebrow: 'Track', title: 'Reports', path: '/reports', steps: [
      'Seven reports, from roster coverage to the audit log',
      'Pick a report and a period of up to a year',
      'Run it, then download CSV or PDF',
    ], ev: [
      { at: 0, shot: 'reports', step: 0, cam: { f: null, z: 1 }, hl: R(47, 392, 1276, 40) },
      { at: 0.6, cur: 'coverage' },
      { at: 2.8, step: 1, cur: R(651, 392, 163, 40), click: true, hl: R(651, 392, 163, 40) },
      { at: 3.05, shot: 'reports-docs' },
      { at: 3.8, hl: R(975, 484, 360, 36), cur: R(1030, 490, 80, 24) },
      { at: 6.0, step: 2, hl: R(1341, 484, 212, 36), cur: 'pdf' },
    ] },
    { id: 'import', dur: 8, eyebrow: 'Admin', title: 'Import and backup', path: '/data', steps: [
      'Import crew, flight patterns or day planning from CSV',
      'Use the template, then preview every row before saving',
      'Administrators can download a backup or restore one',
    ], ev: [
      { at: 0, shot: 'data', step: 0, cam: { f: null, z: 1 }, hl: R(47, 395, 1506, 70) },
      { at: 0.6, cur: 'kind' },
      { at: 2.6, step: 1, hl: R(1306, 603, 247, 44), cur: 'preview' },
      { at: 5.2, step: 2, hl: 'backup', cur: 'backup' },
    ] },
    { id: 'accounts', dur: 9, eyebrow: 'Admin', title: 'User accounts', path: '/accounts', steps: [
      'Admin settings → Create account',
      'Administrator, scheduler, crew control, pilot or cabin crew',
      'Link pilots and cabin crew to a crew profile: they see only their own roster',
    ], ev: [
      { at: 0, shot: 'accounts', step: 0, cam: { f: null, z: 1 } },
      { at: 1.0, cur: 'add', click: true, hl: 'add' },
      { at: 1.25, shot: 'accounts-dialog', hl: null, cam: { f: R(432, 45, 736, 400), z: 1.5 } },
      { at: 2.0, step: 1, hl: R(450, 160, 700, 170), cur: R(820, 165, 110, 60) },
      { at: 4.4, step: 2, hl: R(450, 350, 700, 70), cur: R(600, 380, 200, 24) },
    ] },
    { id: 'settings', dur: 7, eyebrow: 'Admin', title: 'Your settings', path: '/dashboard', steps: [
      'Switch between light and dark themes',
      'Click your name to change your password',
    ], ev: [
      { at: 0, shot: 'theme-light', step: 0, cam: { f: null, z: 1 } },
      { at: 0.8, cur: 'toggle', click: true, hl: 'toggle' },
      { at: 1.05, shot: 'theme-dark' },
      { at: 3.4, step: 1, cur: R(1368, 11, 158, 44), click: true, hl: R(1368, 11, 158, 44) },
      { at: 3.65, shot: 'theme-account', hl: null, cam: { f: R(448, 239, 704, 421), z: 1.4 } },
    ] },
    { id: 'outro', dur: 6 },
  ];
  // Bottom progress sections (chapter ids each one spans).
  const sections = [
    { label: 'Get started', ids: ['signin', 'dashboard'] },
    { label: 'Set up', ids: ['airports', 'flights', 'fleet', 'maintenance', 'crew', 'rules'] },
    { label: 'Rosters', ids: ['newroster', 'build', 'seat', 'plan', 'views', 'publish'] },
    { label: 'Track', ids: ['hours', 'reports'] },
    { label: 'Admin', ids: ['import', 'accounts', 'settings'] },
  ];
  let t = 0;
  for (const c of chapters) { c.start = t; t += c.dur; }
  root.TL = { chapters, sections, total: t, fps: 30 };
})(typeof window !== 'undefined' ? window : globalThis);
