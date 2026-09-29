<?php

// Only the keys LotLink changes; the rest come from Filament. The remember cookie lasts
// auth.guards.web.remember (a week by default).
return [
    'form' => [
        'remember' => [
            'label' => 'Keep me signed in for a week',
        ],
    ],
];
