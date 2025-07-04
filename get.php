<?php
// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

// Define the list of subscription URLs
$subscriptionUrls = [
    "https://www.namira.dev/api/subscription?country=ir",
    "https://www.namira.dev/api/subscription?country=de",
    "https://namira.dev/api/subscription?country=us",
    "https://namira.dev/api/subscription?country=fr",
    "https://namira.dev/api/subscription?country=gb",
    "https://namira.dev/api/subscription?country=nl",
    "https://namira.dev/api/subscription?country=ca",
    "https://namira.dev/api/subscription?country=se",
    "https://namira.dev/api/subscription?country=es",
    "https://namira.dev/api/subscription?country=fi",
    "https://raw.githubusercontent.com/mahdibland/V2RayAggregator/master/sub/sub_merge.txt",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/vmess",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/vless",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/tuic",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/trojan",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/shadowsocks",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/reality",
    "https://raw.githubusercontent.com/V2RAYCONFIGSPOOL/V2RAY_SUB/main/v2ray_configs.txt",
    "https://raw.githubusercontent.com/Surfboardv2ray/Proxy-sorter/main/output/converted.txt",
    "https://raw.githubusercontent.com/PacketCipher/TVC/main/subscriptions/xray/normal/mix"
];

// Include the functions file
require "functions.php";

echo "Fetching configs from subscription URLs...\n";
$allConfigs = fetchConfigsFromUrls($subscriptionUrls);
echo "Fetched " . count($allConfigs) . " configs from subscription URLs.\n\n";

// Fetch the JSON data for Telegram channels
$sourcesArray = json_decode(
    file_get_contents("channels.json"),
    true
);

// Function to process a batch of Telegram sources
function processTelegramBatch($batchSources, $sourcesArray, &$telegramConfigsList)
{
    // Initialize cURL multi handle
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    // Add individual cURL handles to the multi handle
    foreach ($batchSources as $source) {
        $url = "https://t.me/s/" . $source;
        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        // It's good practice to set timeouts for Telegram fetching too, similar to fetchConfigsFromUrls
        curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds connection timeout for Telegram
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 20);      // 20 seconds overall timeout for Telegram
        $curlHandles[$source] = $curlHandle;
        curl_multi_add_handle($multiHandle, $curlHandle);
    }

    // Execute the multi handle
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running);

    // Get the content from the individual cURL handles
    foreach ($curlHandles as $source => $curlHandle) {
        $httpCode = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $tempData = curl_multi_getcontent($curlHandle);
        $error = curl_error($curlHandle);
        $errno = curl_errno($curlHandle);

        if ($errno !== CURLE_OK || $httpCode >= 400) {
            echo "Warning: Failed to fetch from Telegram source $source. HTTP Code: $httpCode. Error: $error (Code: $errno)\n";
        } else {
            $type = implode("|", $sourcesArray[$source]);
            $tempExtract = extractLinksByType($tempData, $type);
            if (!is_null($tempExtract) && !empty($tempExtract)) {
                // Ensure $telegramConfigsList[$source] is an array before merging
                if (!isset($telegramConfigsList[$source]) || !is_array($telegramConfigsList[$source])) {
                    $telegramConfigsList[$source] = [];
                }
                $telegramConfigsList[$source] = array_merge($telegramConfigsList[$source], $tempExtract);
            }
        }
        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
    }
    // Close the multi handle
    curl_multi_close($multiHandle);
}

// Initialize array for Telegram configs (raw, per source)
$telegramConfigsRaw = [];

if (!empty($sourcesArray)) {
    // Batch size
    $batchSize = 10;
    $totalTelegramSources = count($sourcesArray);
    $telegramSourceKeys = array_keys($sourcesArray);

    echo "Fetching configs from Telegram channels...\n";
    // Process the Telegram sources in batches
    for ($i = 0; $i < $totalTelegramSources; $i += $batchSize) {
        $batchTelegramSources = array_slice($telegramSourceKeys, $i, min($batchSize, $totalTelegramSources - $i));
        processTelegramBatch($batchTelegramSources, $sourcesArray, $telegramConfigsRaw);
        echo "\rTelegram Progress: " . round(($i + count($batchTelegramSources)) / $totalTelegramSources * 100) . "% \n";
    }
    echo "Finished fetching from Telegram channels.\n\n";

    // Append Telegram configs to the main list
    foreach ($telegramConfigsRaw as $sourceName => $configsFromSource) {
        if (is_array($configsFromSource)) {
            foreach ($configsFromSource as $config) {
                // Add source information for later processing if needed
                $allConfigs[] = ['config_string' => $config, 'source_type' => 'telegram', 'source_name' => $sourceName];
            }
        }
    }
} else {
    echo "No Telegram channels configured in channels.json or file is empty/invalid.\n";
}


echo "Processing all collected configs...\n";
$finalOutput = [];
$needleArray = ["amp%3B"]; // Used for cleaning up URLs if necessary
$replaceArray = [""];

$configsHash = [
    "vmess" => "ps", "vless" => "hash", "trojan" => "hash",
    "tuic" => "hash", "hy2" => "hash", "ss" => "name",
];
$configsIp = [
    "vmess" => "add", "vless" => "hostname", "trojan" => "hostname",
    "tuic" => "hostname", "hy2" => "hostname", "ss" => "server_address",
];

$processedCount = 0;
$totalToProcess = count($allConfigs);

foreach ($allConfigs as $index => $configData) {
    $config = '';
    $sourceType = '';
    $sourceName = '';

    if (is_array($configData)) {
        $config = $configData['config_string'];
        $sourceType = $configData['source_type'];
        $sourceName = $configData['source_name'];
    } elseif (is_string($configData)) { // From initial $subscriptionUrls
        $config = $configData;
        $sourceType = 'subscription';
        // For subscription URLs, we don't have a simple name like Telegram channels
        // We could parse the URL or use a generic identifier. Using generic for now.
        $sourceName = 'URLSubscription';
    } else {
        continue; // Skip if format is unexpected
    }

    $processedCount++;
    if ($totalToProcess > 0) {
        $percentage = round(($processedCount / $totalToProcess) * 100);
        echo "\rProcessing Progress: $percentage% ($processedCount/$totalToProcess)";
    }

    if (is_valid($config)) { // Assumes is_valid checks the config string
        $type = detect_type($config);
        if (!$type) continue; // Skip if type cannot be detected

        // The renaming logic: $decodedConfig[$configHash] = ...
        // This part needs careful handling of $sourceName and $key (index)

        // For Telegram, $key was an index from array_reverse($configs) within a source.
        // For URL subs, they are just a flat list initially. We can use the $index from $allConfigs.
        $configIdentifierKey = $index; // Using the global index as a unique key for renaming

        if (isset($configsHash[$type]) && isset($configsIp[$type])) {
            $configHashKey = $configsHash[$type];
            // $configIpKey = $configsIp[$type]; // Not used in renaming directly in the original snippet for $decodedConfig[$configHashKey]

            $decodedConfig = configParse(explode("<", $config)[0]); // explode("<", $config)[0] was in original, might be for specific cleaning
            if (!$decodedConfig) continue; // Skip if parsing fails

            $isEncrypted = isEncrypted($config) ? "🔒" : "🔓";

            $nameSuffix = '';
            if ($sourceType === 'telegram') {
                $nameSuffix = "@" . $sourceName . " | " . $configIdentifierKey;
            } else { // subscription
                // For subscriptions, $sourceName is generic. We might want to make it more specific if needed.
                // For now, using a generic source and the global index.
                $nameSuffix = "@URL | " . $configIdentifierKey;
            }

            $decodedConfig[$configHashKey] = $isEncrypted . " | " . $type . " | " . $nameSuffix;

            $encodedConfig = reparseConfig($decodedConfig, $type);
            if ($encodedConfig && substr($encodedConfig, 0, 10) !== "ss://Og==@") { // ss://Og==@ seems to be a filter for invalid SS configs
                $finalOutput[] = str_replace($needleArray, $replaceArray, $encodedConfig);
            }
        }
    }
}
echo "\nFinished processing configs.\n\n";

// Deduplicate and clean the final list
if (!empty($finalOutput)) {
    echo "Deduplicating configs...\n";
    $finalOutput = array_values(array_unique($finalOutput)); // array_values to re-index
    echo "Found " . count($finalOutput) . " unique configs after deduplication.\n";

    // Filter out any completely empty lines again just in case
    $finalOutput = array_filter($finalOutput, function($value) {
        return !empty(trim($value));
    });
     echo "Found " . count($finalOutput) . " non-empty unique configs after final filter.\n";
}

// Write the final output to a file
if (!empty($finalOutput)) {
    file_put_contents("config.txt", implode("\n", $finalOutput) . "\n"); // Add trailing newline
    echo "\nConfig file written successfully to config.txt with " . count($finalOutput) . " configs.\n";
} else {
    // Ensure config.txt is empty if no configs are found
    file_put_contents("config.txt", "");
    echo "\nNo valid new configurations found. config.txt is empty.\n";
}

echo "\nGetting Configs Done!\n";
