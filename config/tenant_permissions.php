<?php

return [
    'permissions' => [
        'bookings.manage' => [
            'label' => 'Kalender og bookinger',
            'description' => 'Kan oprette, redigere, annullere og afslutte bookinger i dashboardet.',
        ],
        'services.manage' => [
            'label' => 'Ydelser',
            'description' => 'Kan oprette og vedligeholde ydelser samt lokal aktivering.',
        ],
        'availability.manage' => [
            'label' => 'Tilgaengelighed',
            'description' => 'Kan redigere aabningstider, lukkeperioder og dato-undtagelser.',
        ],
        'settings.location.manage' => [
            'label' => 'Lokale indstillinger',
            'description' => 'Kan redigere lokationens bookingtekst, adresse og offentlige kontaktoplysninger.',
        ],
        'settings.global.manage' => [
            'label' => 'Globale indstillinger',
            'description' => 'Kan redigere branding, farver og globale booking-indstillinger.',
        ],
        'users.manage' => [
            'label' => 'Brugere',
            'description' => 'Kan oprette, redigere og slette medarbejderbrugere.',
        ],
        'users.permissions.manage' => [
            'label' => 'Rolle-rettigheder',
            'description' => 'Kan redigere hvilke roller der har adgang til hvilke områder.',
        ],
    ],

    'defaults' => [
        'owner' => [
            'bookings.manage' => true,
            'services.manage' => true,
            'availability.manage' => true,
            'settings.location.manage' => true,
            'settings.global.manage' => true,
            'users.manage' => true,
            'users.permissions.manage' => true,
        ],
        'location_manager' => [
            'bookings.manage' => true,
            'services.manage' => true,
            'availability.manage' => true,
            'settings.location.manage' => true,
            'settings.global.manage' => false,
            'users.manage' => true,
            'users.permissions.manage' => false,
        ],
        'manager' => [
            'bookings.manage' => true,
            'services.manage' => true,
            'availability.manage' => true,
            'settings.location.manage' => true,
            'settings.global.manage' => false,
            'users.manage' => true,
            'users.permissions.manage' => false,
        ],
        'staff' => [
            'bookings.manage' => true,
            'services.manage' => false,
            'availability.manage' => false,
            'settings.location.manage' => false,
            'settings.global.manage' => false,
            'users.manage' => false,
            'users.permissions.manage' => false,
        ],
    ],

    'editable_roles' => [
        'location_manager',
        'manager',
        'staff',
    ],

    'user_management' => [
        // Hvilke roller en given rolle må oprette/redigere/slette i brugerstyring.
        'manageable_roles' => [
            'owner' => ['owner', 'location_manager', 'manager', 'staff'],
            'location_manager' => ['manager', 'staff'],
            'manager' => ['staff'],
            'staff' => [],
        ],
    ],
];
