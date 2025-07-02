<?php
// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

// Include the functions file
require "functions.php";

// Define primary subscription URLs
$primarySubscriptionUrls = [
    "https://raw.githubusercontent.com/mahdibland/V2RayAggregator/master/sub/sub_merge.txt",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/vmess",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/vless",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/tuic",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/trojan",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/shadowsocks",
    "https://raw.githubusercontent.com/soroushmirzaei/telegram-configs-collector/main/subscribe/protocols/reality", // Note: 'reality' often implies VLESS
    "https://raw.githubusercontent.com/V2RAYCONFIGSPOOL/V2RAY_SUB/main/v2ray_configs.txt",
    "https://raw.githubusercontent.com/Surfboardv2ray/Proxy-sorter/main/output/converted.txt",
    "https://raw.githubusercontent.com/PacketCipher/TVC/main/subscriptions/xray/normal/mix"
];

// Function to fetch configs from primary URLs
function fetch_from_primary_urls(array $urls) {
    $all_configs = [];
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    echo "Fetching configs from primary URLs...\n";

    foreach ($urls as $key => $url) {
        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 15); // 15 seconds timeout per URL
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false); // Allow self-signed certs, common in these sources
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYHOST, false);
        $curlHandles[$key] = $curlHandle;
        curl_multi_add_handle($multiHandle, $curlHandle);
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running);

    foreach ($curlHandles as $key => $curlHandle) {
        $content = curl_multi_getcontent($curlHandle);
        $http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        if ($http_code == 200 && $content) {
            // Split content by new lines, trim whitespace, and filter out empty lines
            $configs = array_filter(array_map('trim', explode("\n", $content)));
            if (!empty($configs)) {
                $all_configs = array_merge($all_configs, $configs);
                echo "Fetched " . count($configs) . " configs from " . $urls[$key] . "\n";
            } else {
                echo "No configs found or empty content from " . $urls[$key] . "\n";
            }
        } else {
            echo "Failed to fetch from " . $urls[$key] . " (HTTP code: " . $http_code . ", Error: " . curl_error($curlHandle) . ")\n";
        }
        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
    }

    curl_multi_close($multiHandle);
    echo "Finished fetching from primary URLs. Total raw configs: " . count($all_configs) . "\n";
    return $all_configs;
}


// Fetch the JSON data for Telegram channels
$sourcesArray = json_decode(
    file_get_contents("channels.json"),
    true
);
if ($sourcesArray === null) {
    echo "Warning: channels.json is missing or contains invalid JSON. Skipping Telegram scraping.\n";
    $sourcesArray = []; // Ensure it's an array to prevent errors later
}


// Function to process a batch of Telegram sources
function processBatch($batchSources, $sourcesArray, &$telegramConfigsList)
{
    // Initialize cURL multi handle
    $multiHandle = curl_multi_init();
    $curlHandles = [];

    // Add individual cURL handles to the multi handle
    foreach ($batchSources as $source) {
        if (!isset($sourcesArray[$source])) continue; // Skip if source details are missing
        $url = "https://t.me/s/" . $source;
        $curlHandle = curl_init($url);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 20); // Slightly longer timeout for Telegram
        $curlHandles[$source] = $curlHandle;
        curl_multi_add_handle($multiHandle, $curlHandle);
    }

    if (empty($curlHandles)) return; // No valid handles to process

    // Execute the multi handle
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle);
    } while ($running);

    // Get the content from the individual cURL handles
    foreach ($curlHandles as $source => $curlHandle) {
        $tempData = curl_multi_getcontent($curlHandle);
        $http_code = curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);

        if ($http_code == 200 && $tempData) {
            $type = implode("|", $sourcesArray[$source]);
            $tempExtract = extractLinksByType($tempData, $type); // Assuming extractLinksByType handles empty/failed extractions gracefully
            if (!empty($tempExtract)) { // Ensure $tempExtract is not null and not empty
                // $telegramConfigsList[$source] = $tempExtract; // Original: stores structured by source
                // New: directly add to a flat list
                foreach ($tempExtract as $extractedConfig) {
                    $telegramConfigsList[] = ['config' => $extractedConfig, 'source' => $source];
                }
                 echo "Extracted " . count($tempExtract) . " configs from Telegram source: " . $source . "\n";
            } else {
                echo "No configs extracted from Telegram source: " . $source . "\n";
            }
        } else {
             echo "Failed to fetch from Telegram source " . $source . " (HTTP code: " . $http_code . ", Error: " . curl_error($curlHandle) . ")\n";
        }
        curl_multi_remove_handle($multiHandle, $curlHandle);
        curl_close($curlHandle);
    }
    curl_multi_close($multiHandle);
}

// --- Main Data Collection ---
echo "Starting Config Collection Process...\n";
$raw_collected_configs = [];

// 1. Fetch from primary URLs
$raw_collected_configs = fetch_from_primary_urls($primarySubscriptionUrls);

// 2. Fetch from Telegram channels (secondary)
$telegramConfigsAccumulator = []; // Use a simple array to accumulate raw configs with source
if (!empty($sourcesArray)) {
    echo "\nFetching configs from Telegram channels...\n";
    $batchSize = 10;
    $totalSources = count($sourcesArray);
    $sourceKeys = array_keys($sourcesArray);

    for ($i = 0; $i < $totalSources; $i += $batchSize) {
        $batchChunk = array_slice($sourceKeys, $i, min($batchSize, $totalSources - $i));
        processBatch($batchChunk, $sourcesArray, $telegramConfigsAccumulator); // Pass the flat accumulator
        echo "\rTelegram Progress: " . round(($i + count($batchChunk)) / $totalSources * 100) . "% \n";
    }
    echo "Finished fetching from Telegram channels. Total raw configs from Telegram: " . count($telegramConfigsAccumulator) . "\n";

    // Append Telegram configs to the main list for deduplication
    // The new processBatch adds items as ['config' => $config, 'source' => $source]
    // We need to extract just the 'config' part for the raw list, but keep source for processing later.
    // For now, let's just add the config string to raw_collected_configs for deduplication
    // The processing loop will need adjustment if source specific naming is critical from telegram.
} else {
    echo "No Telegram channels defined or channels.json is empty. Skipping Telegram scraping.\n";
}

// Temporary structure to hold config and its original source for later processing
$processed_configs_with_source = [];
foreach ($raw_collected_configs as $config_str) {
    $processed_configs_with_source[] = ['config' => $config_str, 'source' => 'primary_url'];
}
// Add telegram configs, preserving their source
foreach ($telegramConfigsAccumulator as $tg_item) {
    $processed_configs_with_source[] = ['config' => $tg_item['config'], 'source' => $tg_item['source']];
}


// 3. Deduplicate all collected raw config strings
// We need to deduplicate based on the config string itself.
$unique_raw_configs_map = [];
foreach ($processed_configs_with_source as $item) {
    $unique_raw_configs_map[$item['config']] = $item; // Keep the first encountered source for a unique config
}
$deduplicated_configs_with_source = array_values($unique_raw_configs_map);

echo "\nTotal raw configs after fetching from all sources: " . count($processed_configs_with_source) . "\n";
echo "Total unique raw configs after deduplication: " . count($deduplicated_configs_with_source) . "\n";


// --- Processing Configs (adapted from original logic) ---
echo "\nProcessing Unique Configs...\n";
$finalOutput = [];
$needleArray = ["amp%3B"];
$replaceArray = [""];

// Define the hash and IP keys for each type of configuration
$configsHash = [
    "vmess" => "ps",
    "vless" => "hash",
    "trojan" => "hash",
    "tuic" => "hash",
    "hy2" => "hash", // hysteria2
    "ss" => "name",
    "reality" => "hash" // Assuming reality uses vless structure for naming
];
$configsIp = [
    "vmess" => "add",
    "vless" => "hostname",
    "trojan" => "hostname",
    "tuic" => "hostname",
    "hy2" => "hostname",
    "ss" => "server_address",
    "reality" => "hostname"
];

$totalConfigsToProcess = count($deduplicated_configs_with_source);
$processedCounter = 0;

// Loop through the deduplicated list of configs (which are items of ['config' => $config_str, 'source' => $source_info])
// The original code iterated $configsList which was $source => $configs_array.
// And then $key was from array_reverse($configs). This $key was used in naming.
// We need a new way to generate a unique part for the name if the old $key is essential.
// For now, let's use an incremental ID for uniqueness or a hash of the config.
$config_id_counter = 0;

foreach ($deduplicated_configs_with_source as $item) {
    $config_string = $item['config'];
    $source_info = $item['source']; // This is 'primary_url' or the Telegram channel name.

    $processedCounter++;
    // Print the progress bar
    $percentage = ($totalConfigsToProcess > 0) ? ($processedCounter / $totalConfigsToProcess) * 100 : 0;
    echo "\rProcessing Progress: [" . str_repeat("=", (int)($percentage / 2)) . str_repeat(" ", 50 - (int)($percentage / 2)) . "] " . number_format($percentage, 2) . "% ($processedCounter/$totalConfigsToProcess)";

    // The original logic had a $limitKey and processed configs in reverse with a $key.
    // Since we've deduplicated and mixed sources, that exact logic might not apply directly for filtering "latest".
    // We will process all valid unique configs.
    // The `explode("<", $config_string)[0]` was to handle potential HTML tags if configs were extracted from web pages.
    // For raw text subscription links, this might be less necessary but doesn't harm.
    $cleaned_config_string = explode("<", $config_string)[0];

    if (is_valid($cleaned_config_string)) { // is_valid should check the raw config string
        $type = detect_type($cleaned_config_string); // detect_type should operate on the raw config string

        if ($type === 'unknown') {
            // echo "\nSkipping unknown config type: " . substr($cleaned_config_string, 0, 30) . "...\n";
            continue;
        }
        if (!isset($configsHash[$type]) || !isset($configsIp[$type])) {
            // echo "\nSkipping config type with no defined hash/ip keys: " . $type . "\n";
            continue;
        }

        $configHashKey = $configsHash[$type];
        $configIpKey = $configsIp[$type];

        $decodedConfig = configParse($cleaned_config_string); // configParse operates on the raw config string

        if ($decodedConfig === null || !isset($decodedConfig[$configIpKey])) {
            // echo "\nFailed to parse or missing IP/hostname key for config: " . substr($cleaned_config_string, 0, 30) . "...\n";
            continue;
        }

        $isEncrypted = isEncrypted($cleaned_config_string) ? "🔒" : "🔓"; // isEncrypted on raw string

        // Construct the new name/remark for the config
        // Using $source_info and an incrementing $config_id_counter for uniqueness
        $newName = $isEncrypted . " | " . $type . " | @" . $source_info . " | ID:" . $config_id_counter++;

        $decodedConfig[$configHashKey] = $newName;

        $encodedConfig = reparseConfig($decodedConfig, $type);

        // Original had a specific ss check, let's retain it.
        if ($type === 'ss' && substr($encodedConfig, 0, 10) === "ss://Og==@") { // Og== is base64 for null bytes, often invalid
            // echo "\nSkipping invalid SS config: " . $encodedConfig . "\n";
            continue;
        }

        if ($encodedConfig) {
             $finalOutput[] = str_replace(
                $needleArray, // $needleArray was ["amp%3B"]
                $replaceArray, // $replaceArray was [""]
                $encodedConfig
            );
        }
    }
}
echo "\nFinished processing unique configs.\n";


// deleteFolder("subscriptions/location/normal"); // Location based subscriptions are commented out in original
// deleteFolder("subscriptions/location/base64");
// mkdir("subscriptions/location/normal");
// mkdir("subscriptions/location/base64");

// Loop through each location in the location-based array
// foreach ($locationBased as $location => $configs) {
//     $tempConfig = urldecode(implode("\n", $configs));
//     $base64TempConfig = base64_encode($tempConfig);
//     file_put_contents(
//         "subscriptions/location/normal/" . $location,
//         $tempConfig
//     );
//     file_put_contents(
//         "subscriptions/location/base64/" . $location,
//         $base64TempConfig
//     );
// }

// Write the final output to a file only if it's not empty
if (!empty($finalOutput)) {
    file_put_contents("config.txt", implode("\n", $finalOutput));
    echo "\nConfig file written successfully with " . count($finalOutput) . " configs.\n";
} else {
    echo "\nNo valid new configurations found. Config file not written.\n";
}

echo "\nGetting Configs Done!\n";
