<?php

/*
 * Plainer wording for the rules the forms use most. Only these lines are overridden; everything
 * else comes from Laravel's own file. Field-specific wording lives in App\Rules\FieldPattern.
 */
return [
    'integer' => ':Attribute must be a whole number, with no letters or kobo.',
    'email' => ':Attribute must look like ada@example.com.',
    'max' => [
        'numeric' => ':Attribute can\'t be more than :max.',
        'string' => ':Attribute can\'t be longer than :max characters.',
        'array' => ':Attribute can\'t have more than :max items.',
        'file' => ':Attribute can\'t be bigger than :max kilobytes.',
    ],
    'min' => [
        'numeric' => ':Attribute must be at least :min.',
        'string' => ':Attribute needs at least :min characters.',
        'array' => ':Attribute needs at least :min items.',
        'file' => ':Attribute must be at least :min kilobytes.',
    ],
    'between' => [
        'numeric' => ':Attribute must be between :min and :max.',
        'string' => ':Attribute must be between :min and :max characters.',
        'array' => ':Attribute must have between :min and :max items.',
        'file' => ':Attribute must be between :min and :max kilobytes.',
    ],
    'digits' => ':Attribute must be :digits digits.',

    // Field names as people know them (otherwise "budget_max" reads "budget max").
    'attributes' => [
        'budget_max' => 'budget',
        'mileage_km' => 'mileage',
        'make_id' => 'make',
        'vehicle_model_id' => 'model',
        'model_name' => 'model',
        'engine_cc' => 'engine size',
        'counter_amount' => 'counter-offer',
        'estimate_low' => 'low estimate',
        'estimate_high' => 'high estimate',
        'deposit_required' => 'deposit needed',
        'trade_in_value' => 'trade-in value',
        'reservation_deposit' => 'reservation deposit',
        'monthly_income' => 'monthly income',
        'monthly_commitments' => 'monthly commitments',
        'account_number' => 'account number',
        'account_name' => 'account name',
        'bank_name' => 'bank',
        'cac_number' => 'CAC number',
        'vin' => 'VIN',
        'whatsapp' => 'WhatsApp number',
        'phone' => 'phone number',
    ],
];
