

<?php
require_once 'db.php';
session_start(); // Enable session for chat history

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    $userMessage = trim($data['message'] ?? '');

    if ($userMessage === '') {
        echo json_encode(['reply' => 'Message is empty.']);
        exit;
    }
    $stopWords = [
        '.',
        ',',
        '/',
        ':',
        ';',
        '!',
        'what',
        'where',
        'when',
        'how',
        'why',
        'who',
        'whom',
        'which',
        'whose',
        'is',
        'are',
        'was',
        'were',
        'am',
        'be',
        'been',
        'being',
        'do',
        'does',
        'did',
        'doing',
        'a',
        'an',
        'the',
        'and',
        'or',
        'but',
        'if',
        'then',
        'because',
        'so',
        'in',
        'on',
        'at',
        'to',
        'of',
        'for',
        'from',
        'by',
        'with',
        'about',
        'as',
        'this',
        'that',
        'these',
        'those',
        'it',
        'its',
        'they',
        'them',
        'their',
        'i',
        'you',
        'he',
        'she',
        'we',
        'us',
        'me',
        'my',
        'your',
        'his',
        'her',
        'our',
        'can',
        'could',
        'shall',
        'should',
        'will',
        'would',
        'may',
        'might',
        'must',
        'have',
        'has',
        'had',
        'having',
        'not',
        'no',
        'yes',
        'just',
        'only',
        'also',
        'very',
        'really',
        'too',
        'more',
        'most',
        'some',
        'any',
        'much',
        'many',
        'up',
        'down',
        'out',
        'over',
        'under',
        'again',
        'further',
        'once'
    ];


    $keywords = array_filter(explode(' ', strtolower($userMessage)), function ($word) use ($stopWords) {
        return !in_array($word, $stopWords) && strlen($word) > 2;
    });

    $searchQuery = '%' . implode('%', $keywords) . '%';
    $stmt = $pdo->prepare("
        SELECT post_content
        FROM wp_posts
        WHERE LOWER(post_content) LIKE ?
          AND post_type = 'page'
        ORDER BY LENGTH(post_content) ASC
    ");
    $stmt->execute([$searchQuery]);
    $html = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $results = array_map('extractPlainText', $html);
    $websiteContent = $results ? implode("\n\n", $results) : "Sorry, I don't have that information.";
    $tokens = str_word_count($websiteContent);

    // Initialize session chat history if not set
    if (!isset($_SESSION['chat_history'])) {
        $_SESSION['chat_history'] = [
            [
                "role" => "system",
                "content" => "You are the AI chat assistant of My Virtual Teams, a top tech company. Introduce yourself as AI Chat Assistant of My Virtual Teams when asked for you. Please don't introduce yourself in every response. Speak on behalf of the company using 'we' and 'our' and use the 'I' and 'me for yourself. Always follow the instructions below: 
                - Keep replies very short, clear, and professional.
                - Answer based on the website content & Question asked by Users.
                - Assume user details as:
                    - Name: {$data['name']}
                    - Email: {$data['email']}
                    - Mobile: {$data['mobile']}
                - If any adultery words are used in the question, say 'Please do not use any adultery words in your question'.
                - Help users with questions about our services: Shopify, Web/Mobile development, Blockchain, and Data Analytics. Use bullet or expandable lists only when needed. 
                - If a question isn't related to our services, say we may not have full info and invite them to ask about our services. 
                - Always replace 'localhost' with 'myvirtualteams.com'. 
                - Always use Company email as 'info@myvirtualteams.com' & contact Number as '+91 90410-11740' for company related queries."
            ]
        ];
    }

    // Add user message to chat history
    // Construct user prompt separately (include website content here)
    $userPrompt = "Website Content:\n\n{$websiteContent}\n\nUser Question: {$userMessage}";

    // Add only user question to history (cleaned version)
    $_SESSION['chat_history'][] = [
        "role" => "user",
        "content" => $userMessage
    ];

    // Prepare OpenAI request
   
    $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';// Use secure method in production
    $model = $_ENV['OPENAI_MODEL']; // Use gpt-4 if unsupported

    $payload = json_encode([
        'model' => $model,
        'messages' => array_merge(
            [$_SESSION['chat_history'][0]], // system message
            array_slice($_SESSION['chat_history'], 1), // recent messages
            [["role" => "user", "content" => $userPrompt]] // actual user input for this request
        ),
        'temperature' => 0,
    ]);

    // Send the request to OpenAI API
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);

    $apiResponse = curl_exec($ch);

    if (curl_errno($ch)) {
        echo json_encode(['reply' => 'Error communicating with AI: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }

    $responseData = json_decode($apiResponse, true);
    curl_close($ch);

    if (!isset($responseData['choices'][0]['message']['content'])) {
        echo json_encode(['reply' => 'No valid reply from AI.']);
        exit;
    }

    $reply = $responseData['choices'][0]['message']['content'];
    // Add assistant reply to chat history
    $_SESSION['chat_history'][] = [
        "role" => "assistant",
        "content" => $reply
    ];

    // Keep only the last 3 user+assistant pairs + system message
    $history = $_SESSION['chat_history'];
    $systemMessage = $history[0];
    $pairs = array_slice($history, 1); // remove system message
    $last6 = array_slice($pairs, -6); // keep last 3 pairs (6 messages)
    $_SESSION['chat_history'] = array_merge([$systemMessage], $last6);

    echo json_encode(['reply' => $reply]);
    exit;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Extract plain text from HTML content
function extractPlainText($html)
{
    $html = preg_replace('/\[[^\]]*\]/', '', $html);
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}
