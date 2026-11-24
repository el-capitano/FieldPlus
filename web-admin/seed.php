<?php
// web-admin/seed.php
require_once __DIR__ . '/db.php';

try {
    $pdo = getDbConnection();
    initDatabaseSchema($pdo);
    echo "Database schema initialized.\n";

    $forms = [
        [
            'id' => 'form_site_inspection',
            'title' => 'Site Inspection & Quality Audit',
            'category' => 'Engineering & QA',
            'description' => 'Comprehensive on-site facility inspection including structural integrity, safety controls, GPS location, evidence photos, and inspector signoff.',
            'version' => 1,
            'is_active' => 1,
            'schema_json' => json_encode([
                'id' => 'form_site_inspection',
                'title' => 'Site Inspection & Quality Audit',
                'category' => 'Engineering & QA',
                'description' => 'Comprehensive on-site facility inspection including structural integrity, safety controls, GPS location, evidence photos, and inspector signoff.',
                'version' => 1,
                'sections' => [
                    [
                        'id' => 'sec_general',
                        'title' => '1. Facility & Visit Details',
                        'description' => 'Basic site identification and timing',
                        'fields' => [
                            [
                                'id' => 'site_name',
                                'type' => 'text',
                                'label' => 'Site / Facility Name',
                                'placeholder' => 'e.g. Riyadh Central Warehouse A3',
                                'required' => true,
                                'help' => 'Enter the official registered site name'
                            ],
                            [
                                'id' => 'facility_type',
                                'type' => 'dropdown',
                                'label' => 'Facility Classification',
                                'options' => ['Commercial Office', 'Industrial Warehouse', 'Retail Branch', 'Residential Complex', 'Data Center'],
                                'required' => true
                            ],
                            [
                                'id' => 'inspection_date',
                                'type' => 'date',
                                'label' => 'Inspection Date',
                                'required' => true
                            ],
                            [
                                'id' => 'inspection_time',
                                'type' => 'time',
                                'label' => 'Inspection Time',
                                'required' => false
                            ],
                            [
                                'id' => 'inspector_name',
                                'type' => 'text',
                                'label' => 'Lead Inspector Name',
                                'placeholder' => 'e.g. John Doe',
                                'required' => true
                            ]
                        ]
                    ],
                    [
                        'id' => 'sec_geo_environment',
                        'title' => '2. Geo-Location & Environment',
                        'description' => 'Accurate field location and site conditions',
                        'fields' => [
                            [
                                'id' => 'site_gps',
                                'type' => 'gps',
                                'label' => 'Capture Exact Site Coordinates',
                                'required' => true,
                                'help' => 'Ensure device has clear view of the sky for best accuracy'
                            ],
                            [
                                'id' => 'outdoor_temp',
                                'type' => 'number',
                                'label' => 'Ambient Temperature (°C)',
                                'placeholder' => 'e.g. 32.5',
                                'required' => false
                            ],
                            [
                                'id' => 'weather_condition',
                                'type' => 'radio',
                                'label' => 'Weather Condition',
                                'options' => ['Clear / Sunny', 'Overcast', 'Rainy', 'Dust / Sandstorm'],
                                'required' => true
                            ]
                        ]
                    ],
                    [
                        'id' => 'sec_compliance',
                        'title' => '3. Safety Compliance & Findings',
                        'description' => 'Safety checks and hazard assessment',
                        'fields' => [
                            [
                                'id' => 'fire_safety_compliant',
                                'type' => 'switch',
                                'label' => 'Fire Safety Equipment Installed & Validated?',
                                'required' => true,
                                'default' => false
                            ],
                            [
                                'id' => 'hazards_detected',
                                'type' => 'checkbox',
                                'label' => 'Hazards Detected on Site',
                                'options' => [
                                    'Electrical Wiring Exposed',
                                    'Emergency Exits Blocked',
                                    'Structural Cracks / Damage',
                                    'Water Leakage',
                                    'Improper Chemical Storage',
                                    'None / Clean'
                                ],
                                'required' => false
                            ],
                            [
                                'id' => 'inspection_notes',
                                'type' => 'textarea',
                                'label' => 'Detailed Field Observations',
                                'placeholder' => 'Write detailed notes, recommendations or deficiencies found...',
                                'required' => false
                            ]
                        ]
                    ],
                    [
                        'id' => 'sec_evidence_signoff',
                        'title' => '4. Photographic Evidence & Sign-off',
                        'description' => 'Visual attachments and official signatures',
                        'fields' => [
                            [
                                'id' => 'site_exterior_photo',
                                'type' => 'file',
                                'file_type' => 'image',
                                'label' => 'Site Exterior Photo',
                                'required' => true,
                                'help' => 'Take a clear wide-angle photo of the building facade'
                            ],
                            [
                                'id' => 'defect_photo',
                                'type' => 'file',
                                'file_type' => 'image',
                                'label' => 'Defect / Equipment Photo (Optional)',
                                'required' => false
                            ],
                            [
                                'id' => 'inspector_signature',
                                'type' => 'signature',
                                'label' => 'Inspector Digital Signature',
                                'required' => true,
                                'help' => 'Sign on screen to certify the visit findings'
                            ]
                        ]
                    ]
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ],
        [
            'id' => 'form_meter_audit',
            'title' => 'Utility & Meter Verification Survey',
            'category' => 'Utility Operations',
            'description' => 'Field verification for electric/water smart meters, tampering inspection, and customer acknowledgment.',
            'version' => 1,
            'is_active' => 1,
            'schema_json' => json_encode([
                'id' => 'form_meter_audit',
                'title' => 'Utility & Meter Verification Survey',
                'category' => 'Utility Operations',
                'description' => 'Field verification for electric/water smart meters, tampering inspection, and customer acknowledgment.',
                'version' => 1,
                'sections' => [
                    [
                        'id' => 'sec_customer',
                        'title' => 'Customer & Meter Information',
                        'fields' => [
                            [
                                'id' => 'account_no',
                                'type' => 'text',
                                'label' => 'Account / Subscription #',
                                'placeholder' => 'e.g. ACC-98214',
                                'required' => true
                            ],
                            [
                                'id' => 'customer_name',
                                'type' => 'text',
                                'label' => 'Customer Name',
                                'placeholder' => 'Full name',
                                'required' => true
                            ],
                            [
                                'id' => 'meter_serial',
                                'type' => 'text',
                                'label' => 'Physical Meter Serial Number',
                                'required' => true
                            ],
                            [
                                'id' => 'meter_type',
                                'type' => 'dropdown',
                                'label' => 'Meter Model / Type',
                                'options' => ['Smart Digital 3-Phase', 'Smart Digital 1-Phase', 'Legacy Mechanical', 'Industrial Heavy'],
                                'required' => true
                            ],
                            [
                                'id' => 'current_kwh',
                                'type' => 'number',
                                'label' => 'Current Reading (kWh)',
                                'placeholder' => '0.00',
                                'required' => true
                            ]
                        ]
                    ],
                    [
                        'id' => 'sec_verification',
                        'title' => 'Audit & Evidence',
                        'fields' => [
                            [
                                'id' => 'meter_gps',
                                'type' => 'gps',
                                'label' => 'Capture Meter GPS Location',
                                'required' => true
                            ],
                            [
                                'id' => 'tampering_detected',
                                'type' => 'switch',
                                'label' => 'Any signs of seal breakage or bypassing?',
                                'required' => false
                            ],
                            [
                                'id' => 'meter_photo',
                                'type' => 'file',
                                'file_type' => 'image',
                                'label' => 'Photo of Meter Face Display',
                                'required' => true
                            ],
                            [
                                'id' => 'customer_signature',
                                'type' => 'signature',
                                'label' => 'Customer / Witness Signature',
                                'required' => false
                            ]
                        ]
                    ]
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ],
        [
            'id' => 'form_crop_survey',
            'title' => 'Agricultural Crop & Field Monitor',
            'category' => 'Agriculture',
            'description' => 'Field scouting survey to record crop health, irrigation condition, pests, and harvest estimates.',
            'version' => 1,
            'is_active' => 1,
            'schema_json' => json_encode([
                'id' => 'form_crop_survey',
                'title' => 'Agricultural Crop & Field Monitor',
                'category' => 'Agriculture',
                'description' => 'Field scouting survey to record crop health, irrigation condition, pests, and harvest estimates.',
                'version' => 1,
                'sections' => [
                    [
                        'id' => 'sec_field_info',
                        'title' => 'Farm & Plot Details',
                        'fields' => [
                            [
                                'id' => 'farmer_name',
                                'type' => 'text',
                                'label' => 'Farmer / Farm Owner Name',
                                'required' => true
                            ],
                            [
                                'id' => 'plot_id',
                                'type' => 'text',
                                'label' => 'Plot / Block ID',
                                'required' => true
                            ],
                            [
                                'id' => 'crop_type',
                                'type' => 'dropdown',
                                'label' => 'Crop Variety',
                                'options' => ['Wheat', 'Barley', 'Date Palm', 'Alfalfa', 'Corn', 'Citrus / Orchard', 'Greenhouse Vegetables'],
                                'required' => true
                            ],
                            [
                                'id' => 'field_gps',
                                'type' => 'gps',
                                'label' => 'Field Coordinates',
                                'required' => true
                            ]
                        ]
                    ],
                    [
                        'id' => 'sec_agri_health',
                        'title' => 'Crop Health & Irrigation',
                        'fields' => [
                            [
                                'id' => 'irrigation_method',
                                'type' => 'radio',
                                'label' => 'Irrigation Method',
                                'options' => ['Pivot Sprinkler', 'Drip System', 'Furrow / Flood', 'Rainfed'],
                                'required' => true
                            ],
                            [
                                'id' => 'soil_moisture_pct',
                                'type' => 'number',
                                'label' => 'Soil Moisture (%)',
                                'placeholder' => '0 - 100',
                                'required' => false
                            ],
                            [
                                'id' => 'pest_present',
                                'type' => 'switch',
                                'label' => 'Pest / Insect Infestation Present?',
                                'required' => true
                            ],
                            [
                                'id' => 'crop_sample_photo',
                                'type' => 'file',
                                'file_type' => 'image',
                                'label' => 'Crop Canopy / Sample Photo',
                                'required' => true
                            ],
                            [
                                'id' => 'agronomist_signature',
                                'type' => 'signature',
                                'label' => 'Agronomist Signature',
                                'required' => true
                            ]
                        ]
                    ]
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ]
    ];

    $stmt = $pdo->prepare("
        INSERT INTO `forms` (`id`, `title`, `description`, `category`, `version`, `is_active`, `schema_json`)
        VALUES (:id, :title, :description, :category, :version, :is_active, :schema_json)
        ON DUPLICATE KEY UPDATE
            `title` = VALUES(`title`),
            `description` = VALUES(`description`),
            `category` = VALUES(`category`),
            `version` = VALUES(`version`),
            `is_active` = VALUES(`is_active`),
            `schema_json` = VALUES(`schema_json`)
    ");

    foreach ($forms as $form) {
        $stmt->execute($form);
        echo "Seeded form: [{$form['id']}] {$form['title']}\n";
    }

    echo "Database seeding completed successfully.\n";
} catch (Exception $e) {
    echo "Seeding error: " . $e->getMessage() . "\n";
    exit(1);
}
