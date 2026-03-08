<?php
/**
 * LeadByte Proxy - Jeanbrun / maprimefiscale.fr
 * Proxy entre le formulaire LP Jeanbrun et l'API LeadByte REST v1.3
 */

// ============================================
// CONFIGURATION LEADBYTE
// ============================================
define('LEADBYTE_API_URL', 'https://adomos.leadbyte.com/restapi/v1.3/leads');
define('LEADBYTE_CAMPAIGN_ID', 'JEANBRUN'); // ⚠️ Remplacer par le vrai Campaign ID
define('LEADBYTE_API_KEY', '1629d646d2d2928c2e791a1062480aee'); // ⚠️ Remplacer si clé différente pour Jeanbrun
define('LEADBYTE_SID', '1');
define('LEADBYTE_TESTMODE', 'yes'); // ⚠️ Mettre "no" en production

$allowedDomains = [
    'https://maprimefiscale.fr',
    'https://www.maprimefiscale.fr',
    'https://adomos.fr',
    'https://www.adomos.fr',
];

define('DEBUG_MODE', true); // ⚠️ Mettre false en production

// ============================================
// HEADERS CORS
// ============================================
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowedDomains)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (DEBUG_MODE && (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false)) {
    header("Access-Control-Allow-Origin: $origin");
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================
// VÉRIFICATIONS
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
if (strpos($contentType, 'application/json') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Content-Type doit être application/json']);
    exit;
}

// ============================================
// RÉCUPÉRATION DES DONNÉES
// ============================================
$jsonInput = file_get_contents('php://input');
$formData = json_decode($jsonInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalide: ' . json_last_error_msg()]);
    exit;
}

// ============================================
// VALIDATION CHAMPS REQUIS
// ============================================
$requiredFields = ['firstName', 'lastName', 'email', 'phone'];
$missingFields = [];
foreach ($requiredFields as $field) {
    if (empty($formData[$field])) {
        $missingFields[] = $field;
    }
}
if (!empty($missingFields)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Champs manquants: ' . implode(', ', $missingFields)]);
    exit;
}

// ============================================
// FONCTIONS HELPER
// ============================================
function formatPhone($phone) {
    return preg_replace('/[^0-9+]/', '', $phone);
}

/**
 * Mapper l'objectif du prospect (champ "Quel est votre objectif ?")
 */
function mapObjectif($objectif) {
    $mapping = [
        'reduire-impots' => 'Réduire mes impôts',
        'retraite'       => 'Préparer ma retraite',
        'revenus'        => 'Générer des revenus',
        'patrimoine'     => 'Constituer un patrimoine',
    ];
    return isset($mapping[$objectif]) ? $mapping[$objectif] : $objectif;
}

/**
 * Mapper l'imposition annuelle (champ "Votre imposition annuelle")
 */
function mapImposition($revenus) {
    $mapping = [
        'moins_2500' => 'Moins de 2 500€',
        '2500_5000'  => '2 500€ – 5 000€',
        '5000_7500'  => '5 000€ – 7 500€',
        '7500_plus'  => '7 500€ – plus de 10 000€',
    ];
    return isset($mapping[$revenus]) ? $mapping[$revenus] : $revenus;
}

/**
 * Mapper le projet immobilier (champ "Avez-vous un projet ?")
 */
function mapProjetImmo($projet) {
    $mapping = [
        'concret'      => 'Projet concret (< 6 mois)',
        'reflexion'    => 'En réflexion (6–18 mois)',
        'renseignement'=> 'Recherche d\'information',
    ];
    return isset($mapping[$projet]) ? $mapping[$projet] : $projet;
}

// ============================================
// PRÉPARATION DU PAYLOAD LEADBYTE
// ============================================
$leadBytePayload = [
    // Identifiants campagne
    'campid' => LEADBYTE_CAMPAIGN_ID,
    'sid'    => LEADBYTE_SID,
    'ssid'   => isset($formData['ssid']) ? $formData['ssid'] : '',

    // Coordonnées
    'Email'      => $formData['email'],
    'First_Name' => $formData['firstName'],
    'Last_Name'  => $formData['lastName'],
    'Phone_1'    => formatPhone($formData['phone']),

    // Système
    'IP_Address' => $_SERVER['REMOTE_ADDR'],
    'Source'     => 'maprimefiscale.fr',
    'Opt-in_Date'=> date('Y-m-d H:i:s'),
    'optin_url'  => 'www.maprimefiscale.fr',
    'optin'      => '1',

    // ---- Champs spécifiques Jeanbrun ----
    // Objectif du prospect
    'projet_type'       => mapObjectif(isset($formData['objectif']) ? $formData['objectif'] : ''),

    // Imposition annuelle (remplace "revenus imposables")
    'impots_paye'       => mapImposition(isset($formData['revenus']) ? $formData['revenus'] : ''),

    // Budget d'investissement
    'budget_invest'     => isset($formData['budget']) ? $formData['budget'] : '',

    // Maturité du projet immobilier
    'projet_immobilier' => mapProjetImmo(isset($formData['projet_immo']) ? $formData['projet_immo'] : ''),

    // Tracking UTM
    'utm_source'   => isset($formData['utm_source'])   ? $formData['utm_source']   : 'organic',
    'utm_medium'   => isset($formData['utm_medium'])   ? $formData['utm_medium']   : '',
    'utm_campaign' => isset($formData['utm_campaign']) ? $formData['utm_campaign'] : 'jeanbrun-2026',
    'utm_content'  => isset($formData['utm_content'])  ? $formData['utm_content']  : '',
    'utm_term'     => isset($formData['utm_term'])     ? $formData['utm_term']     : '',

    // Click ID (Leadbyte tracking / c3)
    'ssid2'        => isset($formData['c3']) ? $formData['c3'] : '',
];

// ============================================
// LOGS DEBUG
// ============================================
if (DEBUG_MODE) {
    $logFile = __DIR__ . '/leadbyte-jeanbrun-logs.txt';
    $logEntry  = "\n" . str_repeat('=', 80) . "\n";
    $logEntry .= date('[Y-m-d H:i:s]') . " NOUVELLE SOUMISSION JEANBRUN\n";
    $logEntry .= str_repeat('=', 80) . "\n";
    $logEntry .= "DONNÉES REÇUES:\n" . json_encode($formData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    $logEntry .= "PAYLOAD LEADBYTE:\n" . json_encode($leadBytePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// ============================================
// ENVOI VERS LEADBYTE
// ============================================
$ch = curl_init(LEADBYTE_API_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($leadBytePayload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X_KEY: ' . LEADBYTE_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_FOLLOWLOCATION => true,
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ============================================
// GESTION RÉPONSE
// ============================================
if (DEBUG_MODE) {
    $logEntry  = "RÉPONSE LEADBYTE — HTTP $httpCode\n$response\n";
    if ($curlError) $logEntry .= "ERREUR CURL: $curlError\n";
    $logEntry .= str_repeat('=', 80) . "\n\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

if ($curlError) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur connexion LeadByte: ' . $curlError]);
    exit;
}

$leadByteResponse = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code($httpCode);
    echo json_encode([
        'success'      => ($httpCode >= 200 && $httpCode < 300),
        'http_code'    => $httpCode,
        'raw_response' => $response,
        'message'      => 'Lead envoyé (réponse non-JSON)',
    ]);
    exit;
}

$isSuccess = ($httpCode >= 200 && $httpCode < 300);
if (isset($leadByteResponse['status'])) {
    $isSuccess = in_array($leadByteResponse['status'], ['success', 'ok']);
}

http_response_code($httpCode);
echo json_encode([
    'success'           => $isSuccess,
    'http_code'         => $httpCode,
    'leadbyte_response' => $leadByteResponse,
    'message'           => $isSuccess ? 'Lead envoyé avec succès' : 'Erreur envoi lead',
]);

// ============================================
// BACKUP LOCAL
// ============================================
$backupFile = __DIR__ . '/leads-jeanbrun-backup.json';
$leads = [];
if (file_exists($backupFile)) {
    $leads = json_decode(file_get_contents($backupFile), true) ?: [];
}
$leads[] = [
    'timestamp'          => date('c'),
    'form_data'          => $formData,
    'leadbyte_payload'   => $leadBytePayload,
    'leadbyte_response'  => $leadByteResponse,
    'http_code'          => $httpCode,
    'success'            => $isSuccess,
    'ip'                 => $_SERVER['REMOTE_ADDR'],
    'user_agent'         => $_SERVER['HTTP_USER_AGENT'],
];
if (count($leads) > 1000) {
    $leads = array_slice($leads, -1000);
}
file_put_contents($backupFile, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit;
?>
