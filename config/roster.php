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
];
