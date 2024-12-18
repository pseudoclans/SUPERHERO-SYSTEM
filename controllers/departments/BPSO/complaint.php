<?php
session_start();
require_once '../../../vendor/autoload.php'; // Include Composer autoloader

use Aws\DynamoDb\DynamoDbClient;
use Dotenv\Dotenv;

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();

// Get the AWS credentials from the environment
$awsAccessKeyId = $_ENV['AWS_ACCESS_KEY_ID'];
$awsSecretAccessKey = $_ENV['AWS_SECRET_ACCESS_KEY'];
$awsRegion = $_ENV['AWS_REGION'];

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Capture the form data
    $case_complainants = [];
    $case_respondents = [];
    $bcpc_children_infos = [];  // Array to hold BCPC child info

    // Handle complainant data
    if (isset($_POST['complainant_name']) && is_array($_POST['complainant_name'])) {
        foreach ($_POST['complainant_name'] as $index => $complainant_name) {
            $is_complainant_resident = $_POST['is_complainant_resident'][$index] ?? '';
            $case_complainants[] = [
                'name' => $complainant_name,
                'address' => $_POST['complainant_address'][$index] ?? '',
                'is_complainant_resident' => $is_complainant_resident // Default to empty if not provided
            ];
        }
    }

    // Handle respondent data
    if (isset($_POST['respondent_name']) && is_array($_POST['respondent_name'])) {
        foreach ($_POST['respondent_name'] as $index => $respondent_name) {
            $is_respondent_resident = $_POST['is_respondent_resident'][$index] ?? '';
            $case_respondents[] = [
                'name' => $respondent_name,
                'address' => $_POST['respondent_address'][$index] ?? '',
                'is_respondent_resident' => $is_respondent_resident
            ];
        }
    }

    // Handle BCPC child data
    if (isset($_POST['child_name']) && is_array($_POST['child_name'])) {
        foreach ($_POST['child_name'] as $index => $child_name) {
            // Validate and sanitize child age
            $child_age = $_POST['child_age'][$index] ?? '';  // Get the child age
            if (!is_numeric($child_age)) {
                // Handle the case where child_age is not a valid number (e.g., set to 0 or skip)
                $child_age = 0;  // or use a default value like 0 if the age is invalid
            }

            $bcpc_children_infos[] = [
                'child_name' => $child_name,
                'child_age' => (string) $child_age,  // Ensure it's a string number
                'child_gender' => $_POST['child_gender'][$index] ?? '',
                'child_address' => $_POST['child_address'][$index] ?? ''
            ];
        }
    }
    // Handle case_type as an array of objects (case_type and initial_case)
    $caseType = [];
    if (isset($_POST['case_type']) && is_array($_POST['case_type'])) {
        foreach ($_POST['case_type'] as $index => $type) {
            $caseType[] = [
                'case_type' => $type,  // case_type field
                'initial_case' => $_POST['initial_case'][$index] ?? '', // Default to empty if not provided
            ];
        }
    }

    // Convert incident_case_time to ISO 8601 format
    $incidentCaseTime = '';
    if (isset($_POST['incident_case_time'])) {
        try {
            $timeObject = DateTime::createFromFormat('H:i', $_POST['incident_case_time']);
            if ($timeObject) {
                $incidentCaseTime = $timeObject->format('H:i:sP');
            } else {
                throw new Exception("Invalid time format.");
            }
        } catch (Exception $e) {
            echo "Error converting time: " . $e->getMessage();
            exit;
        }
    }

    // Convert incident_case_issued to ISO 8601 format
    $incidentCaseIssued = '';
    if (isset($_POST['incident_date'])) {
        try {
            $dateObject = DateTime::createFromFormat('Y-m-d', $_POST['incident_date']);
            if ($dateObject) {
                $incidentCaseIssued = $dateObject->format('Y-m-d');
            } else {
                throw new Exception("Invalid date format.");
            }
        } catch (Exception $e) {
            echo "Error converting date: " . $e->getMessage();
            exit;
        }
    }

    // Get the current timestamp for case creation
    $caseCreated = (new DateTime())->format(DateTime::ATOM);

    // Get drug-related information for BADAC cases (optional)
    $caseDrugRelatedDescription = $_POST['case_drug_related_description'] ?? '';

    // Generate a random case number (purely numeric)
    $case_number = rand(1000000000, 9999999999); // 10-digit random number

    // Prepare the data to be sent
    $postData = [
        'case_complainants' => $case_complainants,
        'case_respondents' => $case_respondents,
        'case_type' => $caseType, // Now it holds the array of objects
        'case_number' => $case_number,  // Random numeric case number
        'place_of_incident' => $_POST['place_of_incident'] ?? '',
        'incident_case_issued' => $incidentCaseIssued,
        'incident_case_time' => $incidentCaseTime,
        'case_description' => $_POST['case_description'] ?? '',
        'case_drug_related_information' => $caseDrugRelatedDescription, // BADAC attribute
        'affiliated_dept_case' => $_POST['special_case'] ?? 'None',
        'case_status' => 'Ongoing',
        'case_created' => $caseCreated,
        'bcpc_children_infos' => !empty($bcpc_children_infos) ? ['L' => array_map(fn($c) => [
            'M' => [
                'child_name' => ['S' => $c['child_name']],
                'child_age' => ['N' => (string) $c['child_age']], // Ensure child age is a valid numeric value // Ensure it's a number
                'child_gender' => ['S' => $c['child_gender']],
                'child_address' => ['S' => $c['child_address']]
            ]
        ], $bcpc_children_infos)] : []  // Ensure this is only included if not empty
    ];

    // Determine the DynamoDB tables
    $tableNames = ['bms_bpso_portal_complaint_records']; // Always include BPSO table

    // Check if BADAC or BCPC is selected and add corresponding table(s)
    if ($postData['affiliated_dept_case'] === 'BCPC' || $postData['affiliated_dept_case'] === 'BADAC & BCPC') {
        $tableNames[] = 'bms_bcpc_portal_complaint_records';
    }

    if ($postData['affiliated_dept_case'] === 'BADAC' || $postData['affiliated_dept_case'] === 'BADAC & BCPC') {
        $tableNames[] = 'bms_badac_portal_complaint_records';
    }

    if ($postData['affiliated_dept_case'] === 'BCPC') {
        $tableNames[] = 'bms_bcpc_portal_complaint_records';
    }

    if ($postData['affiliated_dept_case'] === 'BADAC') {
        $tableNames[] = 'bms_badac_portal_complaint_records';
    }

    if ($postData['affiliated_dept_case'] === 'VAWC') {
        $tableNames[] = 'bms_vawc_portal_case_records';
    }

    // Initialize the DynamoDbClient
    $dynamoDbClient = new DynamoDbClient([
        'region' => $awsRegion,
        'version' => 'latest',
        'credentials' => [
            'key' => $awsAccessKeyId,
            'secret' => $awsSecretAccessKey,
        ]
    ]);

    $item = [
        // Correct case_number as a numeric value (N)
        'case_number' => ['N' => (string) $postData['case_number']], // Random numeric case number

        'case_complainants' => ['L' => array_map(fn($c) => ['M' => [
            'name' => ['S' => $c['name']],
            'address' => ['S' => $c['address']],
            'is_complainant_resident' => ['S' => $c['is_complainant_resident']],
        ]], $case_complainants)],

        'case_respondents' => ['L' => array_map(fn($r) => ['M' => [
            'name' => ['S' => $r['name']],
            'address' => ['S' => $r['address']],
            'is_respondent_resident' => ['S' => $r['is_respondent_resident']],
        ]], $case_respondents)],

        'case_type' => ['L' => array_map(fn($t) => [
            'M' => [
                'case_type' => ['S' => $t['case_type']],
                'initial_case' => ['S' => $t['initial_case']],
            ]
        ], $caseType)],

        'place_of_incident' => ['S' => $postData['place_of_incident']],
        'incident_case_issued' => ['S' => $postData['incident_case_issued']],
        'incident_case_time' => ['S' => $postData['incident_case_time']],
        'case_description' => ['S' => $postData['case_description']],
        'case_drug_related_information' => ['S' => $postData['case_drug_related_information']],
        'affiliated_dept_case' => ['S' => $postData['affiliated_dept_case']],
        'case_status' => ['S' => $postData['case_status']],
        'case_created' => ['S' => $postData['case_created']],
        'bcpc_children_infos' => $postData['bcpc_children_infos'],
    ];

    // Put the item into DynamoDB for each table
    try {
        foreach ($tableNames as $tableName) {
            $result = $dynamoDbClient->putItem([
                'TableName' => $tableName,
                'Item' => $item
            ]);
        }
        // Redirect to the success page
        header('Location: http://localhost:3000/views/dashboard/departments/BPSO/Complaint%20main/complaints.php');
        exit;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        exit;
    }
}
