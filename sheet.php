<?php
require __DIR__ . '/vendor/autoload.php';

use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
function appendToSheet()
{
    try {
        // Parse JSON input if necessary
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $_POST = is_array($input) ? $input : [];
        }
        // Setup Google Client
        $client = new Client();
        $client->setAuthConfig(__DIR__ . '/google/credentials.json');
        $client->setScopes([Sheets::SPREADSHEETS]);

        $service = new Sheets($client);

        // Hardcoded env values
        $spreadsheetId = $_ENV['SPREADSHEET_ID'] ?? '';
        $sheetName = $_ENV['SPREADSHEET_RANGE'] ?? '';

        // Get data from POST

        $name = $_POST['name'] ?? '';
        $email = $_POST['email'] ?? '';
        $mobile = $_POST['mobile'] ?? '';

        $openaiApiKey = $_ENV['OPENAI_API_KEY'] ?? '';

        $parts = preg_split('/[@.]/', $email);
        $emailParts = implode(' ', $parts);

        $inputTexts = $name . ' ' . $emailParts;

        if (containsInappropriateContent($inputTexts, $openaiApiKey)) {
            echo json_encode(['status' => false, 'error' => 'Inappropriate content detected in Name or Email.']);
            return;
        }

        // Step 1: Read all data from sheet
        $response = $service->spreadsheets_values->get($spreadsheetId, $sheetName);
        $rows = $response->getValues() ?? [];

        $emailColumnIndex = 1;  // Column B
        $mobileColumnIndex = 2; // Column C
        $rowIndexToUpdate = null;
        $matchType = [];

        // Step 2: Search for existing email or mobile
        foreach ($rows as $index => $row) {
            $rowEmail = isset($row[1]) ? strtolower(trim($row[1])) : '';
            $rowMobile = isset($row[2]) ? strtolower(trim($row[2])) : '';

            $emailMatch = $rowEmail === strtolower(trim($email));
            $mobileMatch = $rowMobile === strtolower(trim($mobile));

            if ($emailMatch || $mobileMatch) {
                $rowIndexToUpdate = $index + 1;
                if ($emailMatch) $matchType[] = 'email';
                if ($mobileMatch) $matchType[] = 'mobile';
                break;
            }
        }

        if ($rowIndexToUpdate !== null) {
            // Step 3: Update existing row
            $range = $sheetName . '!A' . $rowIndexToUpdate . ':C' . $rowIndexToUpdate;
            $body = new ValueRange([
                'values' => [[$name, $email, $mobile]]
            ]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $service->spreadsheets_values->update($spreadsheetId, $range, $body, $params);

            echo json_encode(['status' => true, 'message' => ucfirst(implode(' and ', $matchType)) . ' found. Row updated.']);
        } else {
            // Step 4: Append new row
            $range = $sheetName;
            $body = new ValueRange([
                'values' => [[$name, $email, $mobile]]
            ]);
            $params = ['valueInputOption' => 'USER_ENTERED'];
            $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

            echo json_encode(['status' => true, 'message' => 'Email not found. New row added.']);
        }
    } catch (Exception $e) {
        error_log('Google Sheets Error: ' . $e->getMessage());
        echo json_encode(['status' => false, 'error' => 'Failed to write/update sheet.']);
    }
}

// Call function when form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    appendToSheet();
}


function containsInappropriateContent($text, $apiKey)
{
    $url = 'https://api.openai.com/v1/moderations';
    $data = [
        'input' => $text
    ];

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ];


    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpcode === 200) {
        $result = json_decode($response, true);
        return $result['results'][0]['flagged'] ?? false;
    }
    error_log("OpenAI Moderation API failed with response: $response");
    return false;
}
