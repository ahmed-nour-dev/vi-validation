<?php

return [
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be a string.',
    'integer' => 'The :attribute must be an integer.',
    'numeric' => 'The :attribute must be a number.',
    'boolean' => 'The :attribute field must be true or false.',
    'array' => 'The :attribute must be an array.',
    'date' => 'The :attribute is not a valid date.',
    'json' => 'The :attribute must be a valid JSON string.',
    'email' => 'The :attribute must be a valid email address.',
    'alpha' => 'The :attribute must only contain letters.',
    'alpha_num' => 'The :attribute must only contain letters and numbers.',
    'regex' => 'The :attribute format is invalid.',
    'url' => 'The :attribute must be a valid URL.',
    'uuid' => 'The :attribute must be a valid UUID.',
    'ip' => 'The :attribute must be a valid IP address.',
    'in' => 'The selected :attribute is invalid.',
    'not_in' => 'The selected :attribute is invalid.',
    'between' => [
        'numeric' => 'The :attribute must be between :min and :max.',
        'string' => 'The :attribute must be between :min and :max characters.',
        'array' => 'The :attribute must have between :min and :max items.',
    ],
    'size' => [
        'numeric' => 'The :attribute must be :size.',
        'string' => 'The :attribute must be :size characters.',
        'array' => 'The :attribute must contain :size items.',
    ],
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'string' => 'The :attribute must be at least :min characters.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'max' => [
        'numeric' => 'The :attribute must not be greater than :max.',
        'string' => 'The :attribute must not be greater than :max characters.',
        'array' => 'The :attribute must not have more than :max items.',
    ],
    'confirmed' => 'The :attribute confirmation does not match.',
    'same' => 'The :attribute and :other must match.',
    'different' => 'The :attribute and :other must be different.',
    'file' => 'The :attribute must be a file.',
    'image' => 'The :attribute must be an image.',
    'mimes' => 'The :attribute must be a file of type: :values.',
    'max_file_size' => 'The :attribute must not be greater than :max kilobytes.',
    
    // Conditional required rules
    'required_if' => 'The :attribute field is required when :other is :values.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    
    // Comparison rules
    'gt' => [
        'numeric' => 'The :attribute must be greater than :value.',
        'string' => 'The :attribute must be greater than :value characters.',
        'array' => 'The :attribute must have more than :value items.',
    ],
    'gte' => [
        'numeric' => 'The :attribute must be greater than or equal to :value.',
        'string' => 'The :attribute must be greater than or equal to :value characters.',
        'array' => 'The :attribute must have :value items or more.',
    ],
    'lt' => [
        'numeric' => 'The :attribute must be less than :value.',
        'string' => 'The :attribute must be less than :value characters.',
        'array' => 'The :attribute must have less than :value items.',
    ],
    'lte' => [
        'numeric' => 'The :attribute must be less than or equal to :value.',
        'string' => 'The :attribute must be less than or equal to :value characters.',
        'array' => 'The :attribute must not have more than :value items.',
    ],
    
    // Date rules
    'after' => 'The :attribute must be a date after :date.',
    'after_or_equal' => 'The :attribute must be a date after or equal to :date.',
    'before' => 'The :attribute must be a date before :date.',
    'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
    
    // String rules
    'starts_with' => 'The :attribute must start with one of the following: :values.',
    'ends_with' => 'The :attribute must end with one of the following: :values.',
    'digits' => 'The :attribute must be :digits digits.',
    'digits_between' => 'The :attribute must be between :min and :max digits.',
    
    // Acceptance rules
    'accepted' => 'The :attribute must be accepted.',
    'declined' => 'The :attribute must be declined.',
    
    // Other rules
    'filled' => 'The :attribute field must have a value.',
    'present' => 'The :attribute field must be present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'contains' => 'The :attribute field is missing a required value.',
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'in_array' => 'The :attribute field must exist in :other.',
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'ipv4' => 'The :attribute must be a valid IPv4 address.',
    'ipv6' => 'The :attribute must be a valid IPv6 address.',
];
