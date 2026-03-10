<?php
header("Content-Type: application/json");

// 1. INPUT HANDLING
if (isset($_GET['ip'])) {
    $ip = trim($_GET['ip']);
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ipList = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ip = trim($ipList[0]);
} else {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

$locale = isset($_GET['locale']) ? strtolower($_GET['locale']) : 'both';
if (!in_array($locale, ['de','en','both'])) {
    $locale = 'both';
}

// 2. VALIDATION & BINARY CONVERSION
$targetBin = @inet_pton($ip);
if ($targetBin === false) {
    echo json_encode(["error" => "Invalid IP address"]);
    exit;
}
$isIPv4 = (strlen($targetBin) === 4);

// 3. CACHING
$cacheDir = __DIR__ . '/cache';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheKey = ($isIPv4 ? 'v4_' : 'v6_') . md5($ip) . '_' . $locale;
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$cacheTtl = 8 * 3600;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) <= $cacheTtl)) {
    readfile($cacheFile);
    exit;
}

// 4. DATABASE LOOKUP (BINARY SEARCH)
$dbFile = __DIR__ . "/iptoasn.tsv";
$handle = @fopen($dbFile, "r");

if (!$handle) {
    echo json_encode(["error" => "Could not open ASN database"]);
    exit;
}

$result = null;
$fileSize = filesize($dbFile);
$low = 0;
$high = $fileSize;

while ($low <= $high) {
    $mid = (int)(($low + $high) / 2);
    fseek($handle, $mid);
    
    if ($mid != 0) {
        fgets($handle); 
    }
    
    $line = fgets($handle);
    
    if (!$line) {
        $high = $mid - 1;
        continue;
    }
    
    $parts = explode("\t", $line);
    if (count($parts) < 5) {
        break; 
    }

    $startBin = @inet_pton($parts[0]);
    $endBin   = @inet_pton($parts[1]);
    $rowIsV4 = (strlen($startBin) === 4);
    
    if ($isIPv4 && !$rowIsV4) {
        $high = $mid - 1;
        continue;
    }
    if (!$isIPv4 && $rowIsV4) {
        $low = $mid + 1;
        continue;
    }

    if ($targetBin < $startBin) {
        $high = $mid - 1;
    } elseif ($targetBin > $endBin) {
        $low = $mid + 1;
    } else {
        $asn = trim($parts[2]);
        $cc = trim($parts[3]);
        $desc = trim($parts[4]);
        
        $readable = AsnConfig::getDescription($asn, $desc);
        
        $result = [
            "ip" => $ip,
            "asn" => $asn,
            "country" => $cc,
            "country_name" => getCountryDisplay($cc, $locale),
            "description" => $readable,
            "logo" => AsnConfig::getLogoUrl($asn, $readable)
        ];
        break;
    }
}

fclose($handle);

if ($result) {
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
    $json = json_encode(["error" => "ASN not found for given IP"]);
}

file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;


class AsnConfig
{
    private static array $asnMap = [
        "3320" => "Deutsche Telekom", "45090" => "Tencent", "132203" => "Tencent",
        "12876" => "Scaleway", "17012" => "PayPal", "39832" => "Opera",
        "31898" => "Oracle", "14593" => "Starlink", "216246" => "Aeza",
        "62041" => "Telegram", "26141" => "CubePath", "60781" => "Leaseweb",
        "262287" => "Latitude", "206996" => "ZAP-Hosting", "8767" => "M-net", 
        "6805" => "o2", "213200" => "Tube Hosting", "50436" => "Pyur", "42652" => "Deutsche Glasfaser", "60294" => "Deutsche Glasfaser", "8220" => "COLT", "29802" => "HIVELOCITY"
    ];

    private static array $textMap = [
        "CLOUDFLARENET" => "Cloudflare, Inc.", "AKAMAI-AS" => "Akamai",
        "GOOGLE" => "Google", "META" => "Meta", "FACEBOOK" => "Meta",
        "AMAZON" => "Amazon", "APPLE" => "Apple", "MICROSOFT" => "Microsoft",
        "DIGITALOCEAN" => "DigitalOcean", "HETZNER" => "Hetzner",
        "OVH" => "OVHcloud", "LINODE" => "Linode", "QUAD9-AS-1" => "Quad9",
        "BAHN-AS-BLN" => "Deutsche Bahn", "BAHN-WIFI-AS" => "Deutsche Bahn Public Wifi",
        "VERSATEL" => "1&1 Deutschland", "IONOS" => "IONOS", "Clouvider" => "Clouvider",
        "HUAWEI CLOUDS" => "Huawei Cloud", "LEASEWEB" => "Leaseweb",
        "EXTRAVM-LLC" => "Extra VM", "TORSERVERS-NET" => "TORServers.net",
        "GO-DADDY-COM-LLC" => "GoDaddy", "CONTABO" => "Contabo GmbH",
        "ONE-NETWORK" => "dogado GmbH", "TELEHOUSE-AS" => "Telehouse",
        "LATITUDE-SH" => "Latitude", "FERDINANDZINK" => "Tube Hosting",
        "Akamai Connected Cloud" => "Akamai", "VULTR" => "Vultr",
        "Vodafone" => "Vodafone", "RELIABLESITE" => "ReliableSite",
        "TELESYSTEM-AS" => "Telesystem", "NETCOLOGNE" => "NetCologne",
        "DATAFOREST" => "Dataforest", "TMW-GLOBAL-NETWORKS" => "TMW Global Networks"
    ];

    public static function getDescription(string $asn, string $desc): string
    {
        if (isset(self::$asnMap[$asn])) {
            return self::$asnMap[$asn];
        }

        $descClean = strpos($desc, ' ') !== false 
            ? implode(" ", array_slice(preg_split('/\s+/', trim($desc)), 1))
            : trim($desc);

        foreach (self::$textMap as $needle => $replacement) {
            if (stripos($descClean, $needle) !== false) {
                return $replacement;
            }
        }

        return $descClean;
    }

    public static function getLogoUrl(string $asn, string $readableDesc): string
    {
        $base = "https://cdn.t-w.dev/img/%s.webp";

        if (isset(self::$asnMap[$asn])) {
            return sprintf($base, rawurlencode(self::$asnMap[$asn]));
        }

        static $logoMatchMap = null;
        if ($logoMatchMap === null) {
            $logoMatchMap = array_flip([
                "NETCOLOGNE" => "NetCologne", "CLOUDFLARE" => "Cloudflare",
                "AKAMAI" => "Akamai", "FASTLY" => "Fastly", "GOOGLE" => "Google",
                "META" => "Meta", "FACEBOOK" => "Meta", "AMAZON" => "Amazon",
                "AWS" => "Amazon", "APPLE" => "Apple", "MICROSOFT" => "Microsoft",
                "DIGITALOCEAN" => "DigitalOcean", "HETZNER" => "Hetzner",
                "OVH" => "OVHcloud", "LINODE" => "Linode", "VULTR" => "Vultr",
                "LEASEWEB" => "Leaseweb", "CONTABO" => "Contabo", "IONOS" => "IONOS",
                "GO-DADDY" => "GoDaddy", "QUAD9" => "Quad9", "DEUTSCHE TELEKOM" => "Deutsche Telekom",
                "TELEKOM" => "Deutsche Telekom", "VODAFONE" => "Vodafone",
                "TORSERVERS" => "TORServers", "RELIABLESITE" => "ReliableSite",
                "CLOUVIDER" => "Clouvider", "HUAWEI" => "Huawei Cloud",
                "DOGADO" => "dogado", "TELEHOUSE" => "Telehouse",
                "DATAFOREST" => "Dataforest", "TMW-GLOBAL-NETWORKS" => "TMW Global Networks"
            ]);
        }

        $descNorm = trim($readableDesc);
        foreach ($logoMatchMap as $needle => $canonical) {
            if (mb_stripos($descNorm, $needle) !== false) {
                return sprintf($base, rawurlencode($canonical));
            }
        }

        return sprintf($base, rawurlencode(mb_substr($descNorm ?: "Unknown", 0, 64, 'UTF-8')));
    }
}

function getCountryDisplay($cc, $locale = 'both') {
    static $countryMap = [
        "DE" => ["de" => "Deutschland", "en" => "Germany"],
        "US" => ["de" => "Vereinigte Staaten", "en" => "United States"],
        "GB" => ["de" => "Vereinigtes Königreich", "en" => "United Kingdom"],
        "FR" => ["de" => "Frankreich", "en" => "France"],
        "NL" => ["de" => "Niederlande", "en" => "Netherlands"],
        "IT" => ["de" => "Italien", "en" => "Italy"],
        "ES" => ["de" => "Spanien", "en" => "Spain"]
    ];

    $ccUp = strtoupper(trim($cc));
    
    if (isset($countryMap[$ccUp])) {
        $entry = $countryMap[$ccUp];
        if ($locale === 'de') return $entry['de'] ?? $ccUp;
        if ($locale === 'en') return $entry['en'] ?? $ccUp;
        return ($entry['de'] ?? $ccUp) . " / " . ($entry['en'] ?? $ccUp);
    }
    return $ccUp;
}
