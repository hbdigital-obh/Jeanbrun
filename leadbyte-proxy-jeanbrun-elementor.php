<?php
/**
 * LeadByte Proxy - Jeanbrun / Elementor Pro Webhook
 * Reçoit le webhook d'Elementor Pro et envoie le lead à l'API LeadByte REST v1.3
 *
 * ============================================================
 * CONFIGURATION DANS ELEMENTOR :
 * Formulaire → Actions après soumission → Webhook
 * URL : https://maprimefiscale.fr/leadbyte-proxy-jeanbrun-elementor.php
 *
 * IDs des champs à utiliser dans Elementor :
 *   Prénom        → firstName
 *   Nom           → lastName
 *   Email         → email
 *   Téléphone     → phone
 *   Code postal   → postalCode
 *   Ville         → city
 *   Objectif      → objectif    (valeurs : reduire-impots / retraite / revenus / patrimoine)
 *   Imposition    → revenus     (valeurs : moins_2500 / 2500_5000 / 5000_7500 / 7500_plus)
 *   Budget        → budget
 *   Projet immo   → projet_immo (valeurs : concret / reflexion / renseignement)
 * ============================================================
 */

// ============================================
// CONFIGURATION LEADBYTE
// ============================================
define('LEADBYTE_API_URL',   'https://adomos.leadbyte.com/restapi/v1.3/leads');
define('LEADBYTE_CAMPAIGN_ID', 'DEFISC');
define('LEADBYTE_API_KEY',   '1629d646d2d2928c2e791a1062480aee');
define('LEADBYTE_SID',       '1');
define('LEADBYTE_TESTMODE',  'yes'); // ⚠️ Mettre "no" en production

define('DEBUG_MODE', true); // ⚠️ Mettre false en production

$allowedDomains = [
    'https://lp-jeanbrun.maprimefiscale.fr',
    'https://maprimefiscale.fr',
    'https://www.maprimefiscale.fr',
];

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
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

// ============================================
// LECTURE DU PAYLOAD ELEMENTOR
// Elementor Pro envoie : Content-Type application/json
// Structure : { "fields": [{"id": "...", "value": "..."}, ...], "meta": {...} }
// ============================================
$rawInput = file_get_contents('php://input');
$payload  = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($payload['fields'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payload Elementor invalide ou champ "fields" manquant.']);
    exit;
}

// Convertir le tableau fields en tableau associatif id => value
$fields = [];
foreach ($payload['fields'] as $field) {
    if (isset($field['id']) && isset($field['value'])) {
        $fields[$field['id']] = $field['value'];
    }
}

// ============================================
// VALIDATION CHAMPS REQUIS
// ============================================
$requiredFields = ['firstName', 'lastName', 'email', 'phone'];
$missingFields  = [];
foreach ($requiredFields as $f) {
    if (empty($fields[$f])) {
        $missingFields[] = $f;
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

function mapObjectif($v) {
    $map = [
        'reduire-impots' => 'Réduire mes impôts',
        'retraite'       => 'Préparer ma retraite',
        'revenus'        => 'Générer des revenus',
        'patrimoine'     => 'Constituer un patrimoine',
    ];
    return isset($map[$v]) ? $map[$v] : $v;
}

function mapImposition($v) {
    $map = [
        'moins_2500' => 'Moins de 2 500€',
        '2500_5000'  => '2 500€ – 5 000€',
        '5000_7500'  => '5 000€ – 7 500€',
        '7500_plus'  => '7 500€ – plus de 10 000€',
    ];
    return isset($map[$v]) ? $map[$v] : $v;
}

function mapProjetImmo($v) {
    $map = [
        'concret'       => 'Projet concret (< 6 mois)',
        'reflexion'     => 'En réflexion (6–18 mois)',
        'renseignement' => "Recherche d'information",
    ];
    return isset($map[$v]) ? $map[$v] : $v;
}

// Récupérer l'IP réelle (Elementor peut envoyer l'IP dans meta)
$ipAddress = $_SERVER['REMOTE_ADDR'];
if (isset($payload['meta']['remote_ip']) && !empty($payload['meta']['remote_ip'])) {
    $ipAddress = $payload['meta']['remote_ip'];
}

// Récupérer le referrer
$referrer = isset($payload['referrer']) ? $payload['referrer'] : 'https://maprimefiscale.fr';

// ============================================
// PRÉPARATION DU PAYLOAD LEADBYTE
// ============================================
$leadBytePayload = [
    'campid' => LEADBYTE_CAMPAIGN_ID,
    'sid'    => LEADBYTE_SID,
    'ssid'   => isset($fields['ssid']) ? $fields['ssid'] : '',

    'Email'      => $fields['email'],
    'First_Name' => $fields['firstName'],
    'Last_Name'  => $fields['lastName'],
    'Phone_1'    => formatPhone($fields['phone']),
    'Postcode'   => isset($fields['postalCode']) ? $fields['postalCode'] : '',
    'Town/City'  => isset($fields['city'])       ? $fields['city']       : '',

    'IP_Address'  => $ipAddress,
    'Source'      => 'maprimefiscale.fr',
    'Opt-in_Date' => date('Y-m-d H:i:s'),
    'optin_url'   => $referrer,
    'optin'       => '1',

    'projet_type'       => mapObjectif(isset($fields['objectif'])    ? $fields['objectif']    : ''),
    'impots_paye'       => mapImposition(isset($fields['revenus'])   ? $fields['revenus']     : ''),
    'budget_invest'     => isset($fields['budget'])                  ? $fields['budget']      : '',
    'projet_immobilier' => mapProjetImmo(isset($fields['projet_immo']) ? $fields['projet_immo'] : ''),

    'utm_source'   => isset($fields['utm_source'])   ? $fields['utm_source']   : 'organic',
    'utm_medium'   => isset($fields['utm_medium'])   ? $fields['utm_medium']   : '',
    'utm_campaign' => isset($fields['utm_campaign']) ? $fields['utm_campaign'] : 'jeanbrun-2026',
    'utm_content'  => isset($fields['utm_content'])  ? $fields['utm_content']  : '',
    'utm_term'     => isset($fields['utm_term'])      ? $fields['utm_term']     : '',
    'ssid2'        => isset($fields['c3'])            ? $fields['c3']           : '',
];

// ============================================
// LOGS DEBUG
// ============================================
if (DEBUG_MODE) {
    $logFile   = __DIR__ . '/leadbyte-jeanbrun-elementor-logs.txt';
    $logEntry  = "\n" . str_repeat('=', 80) . "\n";
    $logEntry .= date('[Y-m-d H:i:s]') . " SOUMISSION ELEMENTOR\n";
    $logEntry .= str_repeat('=', 80) . "\n";
    $logEntry .= "PAYLOAD BRUT ELEMENTOR:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    $logEntry .= "CHAMPS EXTRAITS:\n"         . json_encode($fields,  JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    $logEntry .= "PAYLOAD LEADBYTE:\n"        . json_encode($leadBytePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
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
$backupFile = __DIR__ . '/leads-jeanbrun-elementor-backup.json';
$leads = [];
if (file_exists($backupFile)) {
    $leads = json_decode(file_get_contents($backupFile), true) ?: [];
}
$leads[] = [
    'timestamp'         => date('c'),
    'fields'            => $fields,
    'leadbyte_payload'  => $leadBytePayload,
    'leadbyte_response' => $leadByteResponse ?? null,
    'http_code'         => $httpCode,
    'success'           => $isSuccess,
    'ip'                => $ipAddress,
    'user_agent'        => $_SERVER['HTTP_USER_AGENT'] ?? '',
];
if (count($leads) > 1000) {
    $leads = array_slice($leads, -1000);
}
file_put_contents($backupFile, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit;
?>
