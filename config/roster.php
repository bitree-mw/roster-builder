<?php

return [
    /** Optional local demo scheduler created by DemoSeeder; both must be set, and never in production. */
    'demo_email' => env('DEMO_EMAIL'),
    'demo_password' => env('DEMO_PASSWORD'),

    /** Crew documents expiring within this many days are flagged as due soon. */
    'document_warning_days' => 30,

    /** Maintenance becomes "due soon" within these calendar days or airframe hours of its next due point. */
    'maintenance_due_soon_days' => 14,
    'maintenance_due_soon_hours' => 50,

    /**
     * Weekly roster planning window: weeks that can be created, counted from the current week (about six
     * months back and two years ahead). Weeks that have ended are kept as read-only history.
     */
    'planning_weeks_back' => 26,
    'planning_weeks_ahead' => 104,

    /** Most weeks returned by one GET /roster-periods?from=&to= request (the week timeline). */
    'max_weeks_listed' => 60,

    /**
     * Planned standby: when a crew member would have more days off in a week than the duty rules' "maximum
     * weekly days off" (prorated for leave and part weeks), the generator plans standby on free days, only
     * when legal. Each standby starts at this base-local time and lasts this many hours (it counts as duty).
     */
    'standby_start_local' => '06:00',
    'standby_hours' => 8,

    /**
     * Print the Malawi Airlines logo on PDF documents. Confirm usage rights before distributing PDFs
     * outside the airline; set ROSTER_PDF_LOGO=false to print without it.
     */
    'pdf_logo' => (bool) env('ROSTER_PDF_LOGO', true),

    /** Signature used at the end of roster emails to crew. */
    'mail_signature' => env('ROSTER_MAIL_SIGNATURE', 'Crew Control, Malawi Airlines'),
];
