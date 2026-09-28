<?php

/*
 * Makes and models most often seen on Nigerian car lots (Tokunbo, Nigerian-used and new).
 * Format: make => [model => body type]. Dealers can add models that are missing; those
 * wait for admin review in /admin.
 */

return [
    'Acura' => ['ILX' => 'sedan', 'MDX' => 'suv', 'RDX' => 'suv', 'TL' => 'sedan', 'TLX' => 'sedan', 'TSX' => 'sedan', 'ZDX' => 'suv'],
    'Audi' => ['A3' => 'sedan', 'A4' => 'sedan', 'A6' => 'sedan', 'A8' => 'sedan', 'Q3' => 'suv', 'Q5' => 'suv', 'Q7' => 'suv', 'Q8' => 'suv'],
    'BMW' => ['1 Series' => 'hatchback', '3 Series' => 'sedan', '5 Series' => 'sedan', '7 Series' => 'sedan', 'X1' => 'suv', 'X3' => 'suv', 'X5' => 'suv', 'X6' => 'suv', 'X7' => 'suv'],
    'Chevrolet' => ['Camaro' => 'coupe', 'Captiva' => 'suv', 'Cruze' => 'sedan', 'Equinox' => 'suv', 'Malibu' => 'sedan', 'Silverado' => 'pickup', 'Tahoe' => 'suv', 'Traverse' => 'suv'],
    'Dodge' => ['Caravan' => 'van', 'Challenger' => 'coupe', 'Charger' => 'sedan', 'Durango' => 'suv', 'Journey' => 'suv', 'Ram' => 'pickup'],
    'Ford' => ['Edge' => 'suv', 'Escape' => 'suv', 'Expedition' => 'suv', 'Explorer' => 'suv', 'F-150' => 'pickup', 'Focus' => 'sedan', 'Fusion' => 'sedan', 'Mustang' => 'coupe', 'Ranger' => 'pickup', 'Transit' => 'van'],
    'GMC' => ['Acadia' => 'suv', 'Sierra' => 'pickup', 'Terrain' => 'suv', 'Yukon' => 'suv'],
    'Honda' => ['Accord' => 'sedan', 'City' => 'sedan', 'Civic' => 'sedan', 'CR-V' => 'suv', 'Crosstour' => 'hatchback', 'Element' => 'suv', 'Fit' => 'hatchback', 'HR-V' => 'suv', 'Odyssey' => 'van', 'Pilot' => 'suv', 'Ridgeline' => 'pickup'],
    'Hyundai' => ['Accent' => 'sedan', 'Creta' => 'suv', 'Elantra' => 'sedan', 'Santa Fe' => 'suv', 'Sonata' => 'sedan', 'Tucson' => 'suv', 'Veracruz' => 'suv'],
    'Infiniti' => ['FX35' => 'suv', 'G35' => 'sedan', 'JX35' => 'suv', 'Q50' => 'sedan', 'QX4' => 'suv', 'QX56' => 'suv', 'QX60' => 'suv', 'QX70' => 'suv', 'QX80' => 'suv'],
    'Innoson' => ['Fox' => 'hatchback', 'G5' => 'suv', 'G6' => 'suv', 'G80' => 'suv', 'IVM Caris' => 'sedan', 'Umu' => 'bus'],
    'Jeep' => ['Cherokee' => 'suv', 'Compass' => 'suv', 'Grand Cherokee' => 'suv', 'Liberty' => 'suv', 'Patriot' => 'suv', 'Wrangler' => 'suv'],
    'Kia' => ['Cerato' => 'sedan', 'Forte' => 'sedan', 'Optima' => 'sedan', 'Picanto' => 'hatchback', 'Rio' => 'sedan', 'Sorento' => 'suv', 'Soul' => 'hatchback', 'Sportage' => 'suv'],
    'Land Rover' => ['Defender' => 'suv', 'Discovery' => 'suv', 'LR4' => 'suv', 'Range Rover' => 'suv', 'Range Rover Evoque' => 'suv', 'Range Rover Sport' => 'suv', 'Range Rover Velar' => 'suv'],
    'Lexus' => ['ES 330' => 'sedan', 'ES 350' => 'sedan', 'GS 350' => 'sedan', 'GX 460' => 'suv', 'GX 470' => 'suv', 'IS 250' => 'sedan', 'LS 460' => 'sedan', 'LX 570' => 'suv', 'LX 600' => 'suv', 'NX 200t' => 'suv', 'NX 300' => 'suv', 'RX 300' => 'suv', 'RX 330' => 'suv', 'RX 350' => 'suv', 'RX 450h' => 'suv'],
    'Mazda' => ['CX-5' => 'suv', 'CX-7' => 'suv', 'CX-9' => 'suv', 'Mazda3' => 'sedan', 'Mazda6' => 'sedan', 'MPV' => 'van', 'Tribute' => 'suv'],
    'Mercedes-Benz' => ['A-Class' => 'hatchback', 'C-Class' => 'sedan', 'CLA' => 'sedan', 'E-Class' => 'sedan', 'G-Class' => 'suv', 'GL' => 'suv', 'GLA' => 'suv', 'GLC' => 'suv', 'GLE' => 'suv', 'GLK' => 'suv', 'GLS' => 'suv', 'M-Class' => 'suv', 'S-Class' => 'sedan', 'Sprinter' => 'van'],
    'Mitsubishi' => ['Galant' => 'sedan', 'L200' => 'pickup', 'Lancer' => 'sedan', 'Montero' => 'suv', 'Outlander' => 'suv', 'Pajero' => 'suv'],
    'Nissan' => ['Almera' => 'sedan', 'Altima' => 'sedan', 'Armada' => 'suv', 'Frontier' => 'pickup', 'Maxima' => 'sedan', 'Murano' => 'suv', 'Navara' => 'pickup', 'Pathfinder' => 'suv', 'Patrol' => 'suv', 'Quest' => 'van', 'Rogue' => 'suv', 'Sentra' => 'sedan', 'Versa' => 'sedan', 'X-Trail' => 'suv', 'Xterra' => 'suv'],
    'Peugeot' => ['206' => 'hatchback', '301' => 'sedan', '307' => 'hatchback', '406' => 'sedan', '407' => 'sedan', '508' => 'sedan', '2008' => 'suv', '3008' => 'suv', '5008' => 'suv'],
    'Porsche' => ['Cayenne' => 'suv', 'Macan' => 'suv', 'Panamera' => 'sedan'],
    'Suzuki' => ['Alto' => 'hatchback', 'Grand Vitara' => 'suv', 'Swift' => 'hatchback', 'Vitara' => 'suv'],
    'Toyota' => ['4Runner' => 'suv', 'Avalon' => 'sedan', 'Avensis' => 'sedan', 'Camry' => 'sedan', 'C-HR' => 'suv', 'Corolla' => 'sedan', 'Coaster' => 'bus', 'FJ Cruiser' => 'suv', 'Fortuner' => 'suv', 'Hiace' => 'bus', 'Highlander' => 'suv', 'Hilux' => 'pickup', 'Land Cruiser' => 'suv', 'Land Cruiser Prado' => 'suv', 'Matrix' => 'hatchback', 'Prius' => 'hatchback', 'RAV4' => 'suv', 'Sequoia' => 'suv', 'Sienna' => 'van', 'Tacoma' => 'pickup', 'Tundra' => 'pickup', 'Venza' => 'suv', 'Yaris' => 'hatchback'],
    'Volkswagen' => ['Golf' => 'hatchback', 'Jetta' => 'sedan', 'Passat' => 'sedan', 'Polo' => 'hatchback', 'Sharan' => 'van', 'Tiguan' => 'suv', 'Touareg' => 'suv'],
    'Volvo' => ['S60' => 'sedan', 'S80' => 'sedan', 'XC60' => 'suv', 'XC90' => 'suv'],
];
